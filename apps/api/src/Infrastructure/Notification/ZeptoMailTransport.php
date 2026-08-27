<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Notification;

use Central\Api\Domain\Notification\NotificationChannel;
use Central\Api\Domain\Notification\NotificationTransport;
use Central\Api\Domain\Notification\PendingDelivery;
use Central\Api\Domain\Notification\TransportResult;
use Central\Api\Domain\Settings\SettingKey;
use Central\Api\Domain\Settings\SettingsRepository;
use Central\Api\Domain\Tenant\TenantRepository;
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
        private SettingsRepository $settings,
        private TenantRepository $tenants,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Whose name is on the mail, and where a reply goes.
     *
     * The organisation's own, falling back to its name and then to the
     * platform's. Somebody receiving a course reminder should see who it is
     * from, and a company that wants replies to reach its training inbox
     * should be able to say so.
     *
     * Failing to read the settings must not stop the mail: the default is
     * the platform's name, which is what every message said before this.
     *
     * @return array{name: string, replyTo: string|null}
     */
    private function senderFor(string $tenantId): array
    {
        try {
            $settings = $this->settings->forTenant($tenantId);

            $name = trim($settings->string(SettingKey::NotificationFromName));
            $replyTo = trim($settings->string(SettingKey::NotificationFromEmail));

            return [
                'name' => $name !== ''
                    ? $name
                    : ($this->tenants->nameOf($tenantId) ?? $this->fromName),
                'replyTo' => $replyTo !== ''
                    && filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false
                        ? $replyTo
                        : null,
            ];
        } catch (\Throwable $failure) {
            $this->logger->warning('Could not read a sender name; using the default', [
                'tenant' => $tenantId,
                'error'  => $failure->getMessage(),
            ]);

            return ['name' => $this->fromName, 'replyTo' => null];
        }
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

        $sender = $this->senderFor($delivery->tenantId);

        $payload = [
            // The address stays the platform's: it is the one the sending
            // domain is authorised for, and putting a tenant's address
            // there is how mail starts failing SPF and landing in spam.
            // What the organisation controls is the name beside it, and
            // where a reply goes.
            'from'     => ['address' => $this->fromAddress, 'name' => $sender['name']],
            'to'       => [['email_address' => ['address' => $address]]],
            'subject'  => $delivery->title,
            'textbody' => $delivery->body,
            'htmlbody' => $this->html($delivery),
        ];

        if ($sender['replyTo'] !== null) {
            $payload['reply_to'] = [['address' => $sender['replyTo']]];
        }

        $response = $this->http->postJson(
            rtrim($this->baseUrl, '/') . '/email',
            $payload,
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
