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
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Email over SMTP.
 *
 * The provider-agnostic option: any mailbox or relay that speaks SMTP — a
 * company's own Exchange/Microsoft 365, Google Workspace, Amazon SES, Mailgun,
 * Postmark, or a self-hosted Postfix — set with `MAIL_PROVIDER=smtp` and the
 * `MAIL_SMTP_*` settings. Uses Symfony Mailer's built-in SMTP transport, so
 * STARTTLS, implicit TLS and authentication are handled for us.
 *
 * Mirrors {@see ZeptoMailTransport} in what it puts on the message: the From
 * address stays the platform's (the one the sending domain is authorised for,
 * so SPF/DKIM hold), while the organisation controls the name beside it and
 * where a reply goes.
 */
final readonly class SmtpMailTransport implements NotificationTransport
{
    public function __construct(
        private string $host,
        private int $port,
        private string $username,
        private string $password,
        /** 'ssl' (implicit TLS, usually :465), 'tls'/'starttls' (usually :587), or 'none'. */
        private string $encryption,
        private string $fromAddress,
        private string $fromName,
        private SettingsRepository $settings,
        private TenantRepository $tenants,
        private LoggerInterface $logger,
    ) {
    }

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Email;
    }

    public function isConfigured(): bool
    {
        return trim($this->host) !== '' && trim($this->fromAddress) !== '';
    }

    public function send(PendingDelivery $delivery): TransportResult
    {
        $address = $delivery->address;

        // Checked before connecting: the server would reject it anyway, and a
        // local answer gives a clearer message.
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return TransportResult::rejected(sprintf('"%s" is not a valid email address.', $address));
        }

        $sender = $this->senderFor($delivery->tenantId);

        // The notification body is already the rendered, branded HTML email
        // (see EmailTemplateDefaults / EmailTemplateRenderer), so it goes in the
        // HTML part as-is, with a stripped-down plain-text alternative.
        // Escaping and re-wrapping it — as this used to — showed the raw markup
        // as text in the inbox.
        $email = (new Email())
            ->from(new Address($this->fromAddress, $sender['name']))
            ->to($address)
            ->subject($delivery->title)
            ->html($delivery->body)
            ->text($this->plainText($delivery->body));

        if ($sender['replyTo'] !== null) {
            $email->replyTo($sender['replyTo']);
        }

        foreach ($this->attachments($delivery) as $attachment) {
            $email->attach($attachment['content'], $attachment['name'], $attachment['mimeType']);
        }

        try {
            // The transport's send() returns the SentMessage (the Mailer
            // wrapper's is void), so we can record the provider's message id.
            $sent = $this->transport()->send($email);

            return TransportResult::sent($sent?->getMessageId());
        } catch (TransportExceptionInterface $failure) {
            // A refused connection, bad credentials or a temporary server error —
            // all worth another go, so the delivery is retried rather than dropped.
            $this->logger->warning('SMTP rejected a message', [
                'host'  => $this->host,
                'error' => $failure->getMessage(),
            ]);

            return TransportResult::failed($failure->getMessage());
        } catch (\Throwable $failure) {
            $this->logger->warning('SMTP could not send a message', [
                'error' => $failure->getMessage(),
            ]);

            return TransportResult::failed($failure->getMessage());
        }
    }

    private function transport(): EsmtpTransport
    {
        // null = negotiate STARTTLS when the server offers it (the :587 norm);
        // true = implicit TLS from the first byte (the :465 norm); false = none.
        $tls = match (strtolower(trim($this->encryption))) {
            'ssl'          => true,
            'none', 'off', '' => false,
            default        => null,
        };

        $transport = new EsmtpTransport($this->host, $this->port > 0 ? $this->port : 587, $tls);

        if (trim($this->username) !== '') {
            $transport->setUsername($this->username);
            $transport->setPassword($this->password);
        }

        return $transport;
    }

    /**
     * Whose name is on the mail, and where a reply goes.
     *
     * The organisation's own, falling back to its name and then to the
     * platform's — the same rule {@see ZeptoMailTransport} follows. Failing to
     * read the settings must not stop the mail.
     *
     * @return array{name: string, replyTo: string|null}
     */
    private function senderFor(string $tenantId): array
    {
        try {
            $settings = $this->settings->forTenant($tenantId);

            $name    = trim($settings->string(SettingKey::NotificationFromName));
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

    /**
     * Files travelling with the message.
     *
     * Carried on the delivery's `data` as base64, exactly as {@see
     * ZeptoMailTransport} reads them; decoded here because Symfony attaches raw
     * bytes and encodes the MIME part itself. Anything malformed is dropped
     * rather than raised — a report that arrives without its spreadsheet is
     * better than one that does not arrive.
     *
     * @return list<array{name: string, content: string, mimeType: string}>
     */
    private function attachments(PendingDelivery $delivery): array
    {
        $declared = $delivery->data['attachments'] ?? null;

        if (!is_array($declared)) {
            return [];
        }

        $attachments = [];

        foreach ($declared as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }

            $name    = $attachment['name'] ?? null;
            $content = $attachment['content'] ?? null;
            $type    = $attachment['mimeType'] ?? 'application/octet-stream';

            if (!is_string($name) || !is_string($content) || $name === '' || $content === '') {
                continue;
            }

            $raw = base64_decode($content, true);

            if ($raw === false) {
                continue;
            }

            $attachments[] = [
                'name'     => $name,
                'content'  => $raw,
                'mimeType' => is_string($type) ? $type : 'application/octet-stream',
            ];
        }

        return $attachments;
    }

    /**
     * A readable plain-text alternative to the HTML body, for clients that
     * prefer text. Block tags become line breaks, the rest is stripped, and
     * entities are decoded back to real characters.
     */
    private function plainText(string $html): string
    {
        $text = preg_replace('#<(?:br|/p|/div|/h[1-6]|/tr|/li)\s*/?>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Collapse the runs of blank lines the stripping leaves behind.
        return trim(preg_replace("/\n[ \t]*(?:\n[ \t]*)+/", "\n\n", $text) ?? $text);
    }
}
