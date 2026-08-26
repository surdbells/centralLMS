<?php

declare(strict_types=1);

namespace Central\Api\Domain\Notification;

/**
 * Sends one message on one channel.
 *
 * Implementations must not throw for an ordinary delivery failure — a bounced
 * address, a rate limit, a provider outage are all expected, and the
 * dispatcher needs to record them against the row rather than lose the batch.
 * They return a result instead. Genuine programming errors may still throw.
 */
interface NotificationTransport
{
    public function channel(): NotificationChannel;

    /**
     * Whether it is configured well enough to try.
     *
     * A transport with no API key reports false, and the dispatcher skips its
     * deliveries with a reason rather than burning all three attempts on a
     * misconfiguration that will not fix itself.
     */
    public function isConfigured(): bool;

    public function send(PendingDelivery $delivery): TransportResult;
}
