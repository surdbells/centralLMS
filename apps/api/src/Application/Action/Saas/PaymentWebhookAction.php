<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Saas;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Saas\Payment\PaymentGatewayError;
use Central\Api\Application\Saas\Payment\PaymentService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Where the payment gateway tells us a charge went through.
 *
 * Public — the gateway has no session — but authenticated by the signature over
 * the raw body, checked in {@see PaymentService::handleWebhook}. This is the
 * authoritative confirmation: a payment settles here even if the customer closed
 * the tab before being returned. The raw body is read as received, since the
 * signature is over those exact bytes.
 */
final class PaymentWebhookAction
{
    /** The header Paystack signs its webhook bodies with. */
    private const SIGNATURE_HEADER = 'X-Paystack-Signature';

    public function __construct(private readonly PaymentService $payments)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $rawBody = (string) $request->getBody();
        $signature = $request->getHeaderLine(self::SIGNATURE_HEADER);

        try {
            $this->payments->handleWebhook($rawBody, $signature);
        } catch (PaymentGatewayError) {
            // A body that does not verify is not from the gateway. Reject it —
            // and do not 200, so nothing is mistaken for handled.
            return Json::error($response, 401, 'invalid_signature', 'The webhook signature did not verify.');
        }

        // 200 tells the gateway we have it; anything else has it retry.
        return Json::ok($response, ['received' => true]);
    }
}
