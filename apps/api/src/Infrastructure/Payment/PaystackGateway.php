<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Payment;

use Central\Api\Application\Saas\Payment\GatewayCharge;
use Central\Api\Application\Saas\Payment\GatewayInit;
use Central\Api\Application\Saas\Payment\PaymentGateway;
use Central\Api\Application\Saas\Payment\PaymentGatewayError;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Paystack, behind the {@see PaymentGateway} contract.
 *
 * The redirect flow: initialize a transaction and hand back its hosted checkout
 * URL; verify by reference when the customer returns; and confirm authoritatively
 * from the `charge.success` webhook, whose body is signed with the secret key.
 * Amounts are in kobo throughout, which is exactly how the invoices are stored,
 * so nothing is converted.
 */
final class PaystackGateway implements PaymentGateway
{
    private readonly RequestFactory $requests;
    private readonly StreamFactory $streams;

    /**
     * @param array<string, mixed> $config the `payments.paystack` section
     */
    public function __construct(
        private readonly array $config,
        private readonly ClientInterface $http,
        private readonly LoggerInterface $logger,
    ) {
        $this->requests = new RequestFactory();
        $this->streams = new StreamFactory();
    }

    public function name(): string
    {
        return 'paystack';
    }

    public function initialize(
        string $reference,
        int $amount,
        string $currency,
        string $email,
        string $callbackUrl,
        array $metadata = [],
    ): GatewayInit {
        $data = $this->post('/transaction/initialize', [
            'email'        => $email,
            'amount'       => $amount,
            'currency'     => $currency,
            'reference'    => $reference,
            'callback_url' => $callbackUrl,
            'metadata'     => (object) $metadata,
        ]);

        $checkout = is_array($data['data'] ?? null) ? $data['data'] : [];
        $url = $checkout['authorization_url'] ?? null;

        if (!is_string($url) || $url === '') {
            throw PaymentGatewayError::badResponse('paystack', 'no authorization URL in the initialize response');
        }

        return new GatewayInit(
            reference: is_string($checkout['reference'] ?? null) ? $checkout['reference'] : $reference,
            authorizationUrl: $url,
            accessCode: is_string($checkout['access_code'] ?? null) ? $checkout['access_code'] : null,
        );
    }

    public function verify(string $reference): GatewayCharge
    {
        $data = $this->get('/transaction/verify/' . rawurlencode($reference));
        $charge = is_array($data['data'] ?? null) ? $data['data'] : [];

        return $this->chargeFrom($charge, $reference);
    }

    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        $secret = $this->secretKey();
        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $rawBody, $secret), $signature);
    }

    public function parseWebhook(array $payload): ?GatewayCharge
    {
        // Only a completed charge settles an invoice; everything else is noise
        // to this layer.
        if (($payload['event'] ?? null) !== 'charge.success') {
            return null;
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $reference = is_string($data['reference'] ?? null) ? $data['reference'] : '';

        return $reference === '' ? null : $this->chargeFrom($data, $reference);
    }

    // --- internals ------------------------------------------------------------

    /**
     * @param  array<string, mixed> $data the gateway's transaction object
     * @return GatewayCharge
     */
    private function chargeFrom(array $data, string $reference): GatewayCharge
    {
        return new GatewayCharge(
            reference: is_string($data['reference'] ?? null) ? $data['reference'] : $reference,
            successful: ($data['status'] ?? null) === 'success',
            amount: is_numeric($data['amount'] ?? null) ? (int) $data['amount'] : 0,
            currency: is_string($data['currency'] ?? null) ? $data['currency'] : 'NGN',
            gatewayReference: isset($data['id']) ? (string) $data['id'] : ($data['reference'] ?? null),
            raw: $data,
        );
    }

    /**
     * @param  array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        $request = $this->authed('POST', $path)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream(json_encode($body, JSON_THROW_ON_ERROR)));

        return $this->send($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        return $this->send($this->authed('GET', $path));
    }

    private function authed(string $method, string $path): \Psr\Http\Message\RequestInterface
    {
        $secret = $this->secretKey();
        if ($secret === '') {
            throw PaymentGatewayError::notConfigured('paystack');
        }

        return $this->requests->createRequest($method, $this->baseUrl() . $path)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $secret);
    }

    /**
     * @return array<string, mixed>
     */
    private function send(\Psr\Http\Message\RequestInterface $request): array
    {
        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $this->logger->error('Paystack request failed', ['error' => $e->getMessage()]);

            throw PaymentGatewayError::badResponse('paystack', 'the request could not be completed');
        }

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw PaymentGatewayError::badResponse('paystack', 'the response was not JSON');
        }

        // Paystack answers 4xx/5xx with {status:false, message:...}; a `status`
        // of false is a refusal even on a 200.
        if ($response->getStatusCode() >= 400 || ($decoded['status'] ?? true) === false) {
            $message = is_string($decoded['message'] ?? null) ? $decoded['message'] : 'request rejected';
            $this->logger->warning('Paystack rejected a request', ['status' => $response->getStatusCode(), 'message' => $message]);

            throw PaymentGatewayError::badResponse('paystack', $message);
        }

        return $decoded;
    }

    private function secretKey(): string
    {
        return (string) ($this->config['secret_key'] ?? '');
    }

    private function baseUrl(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? 'https://api.paystack.co'), '/');
    }
}
