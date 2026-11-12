<?php

declare(strict_types=1);

namespace Central\Api\Application\Notification;

use Central\Api\Domain\Notification\NotificationTransport;
use Central\Api\Domain\Notification\PendingDelivery;
use Central\Api\Domain\Notification\TransportResult;

/**
 * The configured email transport, for the few things that send one email
 * straight away instead of queueing a notification — the settings screen's
 * "send me a test".
 *
 * A class of its own so it can be injected by type: the transports live in
 * the container under a key, which autowiring cannot ask for.
 */
final readonly class EmailSender
{
    public function __construct(private NotificationTransport $transport)
    {
    }

    public function isConfigured(): bool
    {
        return $this->transport->isConfigured();
    }

    public function send(PendingDelivery $delivery): TransportResult
    {
        return $this->transport->send($delivery);
    }
}
