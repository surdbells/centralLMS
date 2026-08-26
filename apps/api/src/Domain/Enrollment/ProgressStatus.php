<?php

declare(strict_types=1);

namespace Central\Api\Domain\Enrollment;

/**
 * Where a learner has got to on one lesson.
 *
 * Deliberately not `EnrollmentStatus`: a lesson cannot be dropped, and reusing
 * the enum would leave a fourth case that has no meaning here.
 */
enum ProgressStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Completed = 'completed';

    public static function fromLegacy(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::NotStarted;
    }

    /**
     * The status implied by a percentage.
     *
     * Percentage and status are two views of one fact, and the legacy data has
     * rows where they disagree — 0% marked `in_progress`, 66.7% marked
     * `completed`. Deriving one from the other is what stops that recurring.
     *
     * Anything above zero counts as started: a learner who watched ten seconds
     * of a video has started it, and reporting otherwise makes "not started"
     * useless as a nudge list.
     */
    public static function fromPercentage(float $percentage): self
    {
        return match (true) {
            $percentage >= 100.0 => self::Completed,
            $percentage > 0.0    => self::InProgress,
            default              => self::NotStarted,
        };
    }

    public function isComplete(): bool
    {
        return $this === self::Completed;
    }

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::InProgress => 'In progress',
            self::Completed  => 'Completed',
        };
    }
}
