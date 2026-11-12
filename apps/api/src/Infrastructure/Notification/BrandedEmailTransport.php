<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Notification;

use Central\Api\Application\Notification\EmailComposer;
use Central\Api\Domain\Notification\NotificationChannel;
use Central\Api\Domain\Notification\NotificationTransport;
use Central\Api\Domain\Notification\PendingDelivery;
use Central\Api\Domain\Notification\TransportResult;
use Psr\Log\LoggerInterface;

/**
 * Every email, branded — whichever provider sends it.
 *
 * Wraps the real email transport (SMTP, ZeptoMail or the log) and replaces
 * the delivery's subject and body with the composed, branded email
 * ({@see EmailComposer}) before handing it on. Doing it here, at the one door
 * all mail goes through, is what makes "every email carries the branding"
 * true without every caller having to remember: queued notifications, the
 * scheduled-report mailer and the settings screen's test send all pass
 * through it.
 *
 * Fail-safe: if composing throws, the original wording goes out in the
 * layout, and if even that fails, as it was. A logo that cannot be read must
 * not stop a password reset.
 */
final readonly class BrandedEmailTransport implements NotificationTransport
{
    public function __construct(
        private NotificationTransport $inner,
        private EmailComposer $composer,
        private LoggerInterface $logger,
    ) {
    }

    public function channel(): NotificationChannel
    {
        return $this->inner->channel();
    }

    public function isConfigured(): bool
    {
        return $this->inner->isConfigured();
    }

    public function send(PendingDelivery $delivery): TransportResult
    {
        try {
            $email = $this->composer->forDelivery($delivery);
        } catch (\Throwable $e) {
            $this->logger->error('Could not compose a branded email; sending the plain wording', [
                'delivery' => $delivery->deliveryId,
                'type'     => $delivery->type->value,
                'error'    => $e->getMessage(),
            ]);

            try {
                $email = $this->composer->fallback($delivery);
            } catch (\Throwable) {
                return $this->inner->send($delivery);
            }
        }

        return $this->inner->send(new PendingDelivery(
            deliveryId: $delivery->deliveryId,
            tenantId: $delivery->tenantId,
            notificationId: $delivery->notificationId,
            userId: $delivery->userId,
            channel: $delivery->channel,
            address: $delivery->address,
            type: $delivery->type,
            title: $email['subject'] !== '' ? $email['subject'] : $delivery->title,
            body: $email['html'],
            data: [...$delivery->data, 'textBody' => $email['text']],
            priority: $delivery->priority,
            attempts: $delivery->attempts,
            maxAttempts: $delivery->maxAttempts,
        ));
    }
}
