<?php

declare(strict_types=1);

namespace Central\Api\Domain\Ilt;

/**
 * Where one session has got to.
 */
enum SessionStatus: string
{
    case Scheduled = 'scheduled';
    case Ongoing = 'ongoing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public static function fromLegacy(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::Scheduled;
    }

    /** Whether a meeting should exist for it. */
    public function needsMeeting(): bool
    {
        return $this === self::Scheduled || $this === self::Ongoing;
    }

    /** Whether attendance may still be recorded. */
    public function acceptsAttendance(): bool
    {
        return $this === self::Ongoing || $this === self::Completed;
    }

    /** Whether it still counts towards the attendance requirement. */
    public function countsTowardsAttendance(): bool
    {
        return $this !== self::Cancelled;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
