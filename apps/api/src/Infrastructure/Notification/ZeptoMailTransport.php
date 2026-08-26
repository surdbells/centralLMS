<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Notification;

use Central\Api\Domain\Notification\NotificationChannel;
use Central\Api\Domain\Notification\NotificationTransport;
use Central\Api\Domain\Notification\PendingDelivery;
use Central\Api\Domain\Notification\TransportResult;
use Psr\Log\LoggerInterface;

/**
 * Email via ZeptoMail's transactional API.
 *
 * The provider the legacy's settings already named.
 */
final readonly class ZeptoMailTransport implements NotificationTransport
{
    public function __construct(
        private HttpSender $http,
        private string $token,
        private string $baseUrl,
        private string $fromAddress,
        private string $fromName,
        private LoggerInterface $logger,
    ) {
    }

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Email;
    }

    public function isConfigured(): bool
    {
        return trim($this->token) !== '' && trim($this->fromAddress) !== '';
    }

    public function send(PendingDelivery $delivery): TransportResult
    {
        $address = $delivery->address;

        // Checked before the request: the provider will reject it anyway, and
        // a local answer gives a better message than the provider's.
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return TransportResult::rejected(sprintf('"%s" is not a valid email address.', $address));
        }

        $response = $this->http->postJson(
            rtrim($this->baseUrl, '/') . '/email',
            [
                'from'     => ['address' => $this->fromAddress, 'name' => $this->fromName],
                'to'       => [['email_address' => ['address' => $address]]],
                'subject'  => $delivery->title,
                'textbody' => $delivery->body,
                'htmlbody' => $this->html($delivery),
            ],
            ['Authorization' => 'Zoho-enczapikey ' . $this->token],
        );

        $status = $response['status'];

        // Never reached the provider — always worth another go.
        if ($status === 0) {
            return TransportResult::failed($response['error'] ?? 'The mail provider could not be reached.');
        }

        if ($status >= 200 && $status < 300) {
            return TransportResult::sent($this->messageId(HttpSender::decode($response['body'])));
        }

        $this->logger->warning('ZeptoMail rejected a message', [
            'status' => $status,
            'body'   => mb_substr($response['body'], 0, 500),
        ]);

        $reason = $this->reason($response['body'], $status);

        // 4xx is our mistake and will not fix itself; 429 and 5xx will.
        return $status >= 400 && $status < 500 && $status !== 429
            ? TransportResult::rejected($reason)
            : TransportResult::failed($reason);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function messageId(array $decoded): ?string
    {
        $data = $decoded['data'] ?? null;

        if (is_array($data) && isset($data[0]) && is_array($data[0])) {
            $id = $data[0]['message_id'] ?? null;

            if (is_string($id)) {
                return $id;
            }
        }

        $id = $decoded['request_id'] ?? null;

        return is_string($id) ? $id : null;
    }

    private function reason(string $body, int $status): string
    {
        $message = HttpSender::decode($body)['message'] ?? null;

        return is_string($message) && trim($message) !== ''
            ? $message
            : sprintf('The mail provider returned HTTP %d.', $status);
    }

    /**
     * A plain, readable HTML part.
     *
     * Every value is escaped. A notification title can carry a course name an
     * author typed and a body can carry a learner's own name — both arrive
     * from a form, and an email client that renders HTML is not the place to
     * discover that.
     */
    private function html(PendingDelivery $delivery): string
    {
        $title = htmlspecialchars($delivery->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $body  = nl2br(htmlspecialchars($delivery->body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        return <<<HTML
            <div style="font-family:system-ui,-apple-system,'Segoe UI',sans-serif;font-size:15px;line-height:1.6;color:#1f2933">
                <h1 style="font-size:18px;margin:0 0 12px">{$title}</h1>
                <p style="margin:0">{$body}</p>
            </div>
            HTML;
    }
}
