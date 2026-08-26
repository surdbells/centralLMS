<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Notification;

use Central\Api\Domain\Notification\NotificationChannel;
use Central\Api\Domain\Notification\NotificationTransport;
use Central\Api\Domain\Notification\PendingDelivery;
use Central\Api\Domain\Notification\TransportResult;

/**
 * The bell.
 *
 * There is nothing to send: the notification row *is* the delivery, and it is
 * on the person's bell the moment it is written — so a routed in-app delivery
 * is recorded as sent there and then, and never reaches a worker.
 *
 * This exists for the one case that can: a delivery the dispatcher finds
 * pending on this channel, whether from an older row or a future change that
 * defers one. Without it that row would be a channel with no transport, which
 * the dispatcher rightly treats as a wiring fault.
 */
final readonly class InAppTransport implements NotificationTransport
{
    public function channel(): NotificationChannel
    {
        return NotificationChannel::InApp;
    }

    /**
     * Always. It needs no credentials and no network.
     */
    public function isConfigured(): bool
    {
        return true;
    }

    public function send(PendingDelivery $delivery): TransportResult
    {
        return TransportResult::sent();
    }
}
