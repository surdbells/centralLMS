<?php

declare(strict_types=1);

namespace Central\Api\Domain\Enrollment;

/**
 * Where a learner has got to on a course.
 *
 * The legacy table carried this enum *and* `is_started` *and* `is_completed`.
 * In the live data 16 of the 159 enrolments say `completed` while
 * `is_completed` is 0, so the booleans were never maintained. This is the one
 * representation, and the database ties `completed_at` to it.
 */
enum EnrollmentStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Completed = 'completed';

    /** Taken off the course. The row and its progress are kept. */
    case Dropped = 'dropped';

    public static function fromLegacy(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::NotStarted;
    }

    /**
     * Whether the learner may still open the course.
     *
     * A dropped enrolment is the only one that closes the door. A completed
     * learner may revisit the material — that is the point of keeping it.
     */
    public function grantsAccess(): bool
    {
        return $this !== self::Dropped;
    }

    /** Whether it counts towards a completion figure. */
    public function isComplete(): bool
    {
        return $this === self::Completed;
    }

    /**
     * Whether a due date still applies.
     *
     * Neither a finished learner nor a dropped one can be overdue, and
     * reporting one as such is how a compliance dashboard loses its audience.
     */
    public function canBeOverdue(): bool
    {
        return $this === self::NotStarted || $this === self::InProgress;
    }

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::InProgress => 'In progress',
            self::Completed  => 'Completed',
            self::Dropped    => 'Dropped',
        };
    }
}
