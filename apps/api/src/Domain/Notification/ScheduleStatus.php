<?php

declare(strict_types=1);

namespace Central\Api\Domain\Notification;

/**
 * Where a planned future notification has got to.
 */
enum ScheduleStatus: string
{
    case Pending = 'pending';

    /** Turned into notifications and deliveries. Done. */
    case Expanded = 'expanded';

    /**
     * Its moment passed without it going out — most often because the
     * schedule was created after the due time had already gone, which is what
     * happens when someone books a session for tomorrow morning with a
     * 24-hour reminder on it.
     *
     * Recorded rather than silently dropped: "why did nobody get a reminder"
     * is a question that needs an answer in the data.
     */
    case Skipped = 'skipped';

    case Failed = 'failed';

    /** The session it belonged to was cancelled or deleted. */
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
