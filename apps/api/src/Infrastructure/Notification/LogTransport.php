<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Notification;

use Central\Api\Domain\Notification\NotificationChannel;
use Central\Api\Domain\Notification\NotificationTransport;
use Central\Api\Domain\Notification\PendingDelivery;
use Central\Api\Domain\Notification\TransportResult;
use Psr\Log\LoggerInterface;

/**
 * Writes the message to the log instead of sending it.
 *
 * What development and the test suite use. It reports itself as configured and
 * succeeds, so the whole path — route, queue, claim, send, mark sent — runs
 * end to end without an account anywhere, and a delivery that would have been
 * wrong is visible in the log rather than in somebody's inbox.
 *
 * Chosen by MAIL_PROVIDER=log / SMS_PROVIDER=log.
 */
final readonly class LogTransport implements NotificationTransport
{
    public function __construct(
        private NotificationChannel $channel,
        private LoggerInterface $logger,
    ) {
    }

    public function channel(): NotificationChannel
    {
        return $this->channel;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(PendingDelivery $delivery): TransportResult
    {
        $this->logger->info('Notification (not actually sent)', [
            'channel' => $this->channel->value,
            'to'      => $delivery->address,
            'type'    => $delivery->type->value,
            'title'   => $delivery->title,
            'body'    => $delivery->body,
        ]);

        return TransportResult::sent('log-' . bin2hex(random_bytes(8)));
    }
}
