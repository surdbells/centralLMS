<?php

declare(strict_types=1);

namespace Central\Api\Domain\Ilt;

/**
 * Whether a learner was there.
 */
enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case Absent = 'absent';

    /** Absent with a reason accepted in advance. */
    case Excused = 'excused';

    public static function fromLegacy(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::Absent;
    }

    /**
     * Whether this counts towards the attendance requirement.
     *
     * Late counts: someone who arrived fifteen minutes into a two-hour session
     * attended it, and a rule that says otherwise turns a traffic jam into a
     * failed certification.
     */
    public function countsAsAttended(): bool
    {
        return $this === self::Present || $this === self::Late;
    }

    /**
     * Whether it counts against them.
     *
     * An excused absence is neither attended nor held against the learner: it
     * comes out of the denominator rather than adding to the numerator.
     */
    public function countsAgainst(): bool
    {
        return $this === self::Absent;
    }

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Late    => 'Late',
            self::Absent  => 'Absent',
            self::Excused => 'Excused',
        };
    }
}
