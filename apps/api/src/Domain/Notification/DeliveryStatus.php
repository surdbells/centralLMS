<?php

declare(strict_types=1);

namespace Central\Api\Domain\Notification;

/**
 * Where one attempt to reach someone on one channel has got to.
 *
 * `Skipped` is the addition to the legacy's set. Without it a delivery that
 * was never attempted — the recipient opted out, or has no mobile number —
 * has to be recorded as `failed`, and then a queue that is working perfectly
 * looks like one that is broken. Distinguishing "we chose not to" from "we
 * tried and could not" is what makes the failure count worth alerting on.
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';

    /** Claimed by a worker. Nothing else may touch it. */
    case Sending = 'sending';

    case Sent    = 'sent';
    case Failed  = 'failed';
    case Skipped = 'skipped';

    public function isTerminal(): bool
    {
        return $this === self::Sent || $this === self::Failed || $this === self::Skipped;
    }

    /**
     * Whether this counts as a delivery problem someone should look at.
     */
    public function isProblem(): bool
    {
        return $this === self::Failed;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
