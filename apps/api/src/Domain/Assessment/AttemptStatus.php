<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

/**
 * Where an attempt is in its lifecycle.
 *
 * Merges two legacy vocabularies. The generic side had four values
 * (`in_progress`, `completed`, `timed_out`, `cancelled`) and collapsed
 * submission and grading into one; the proctored side had seven and kept them
 * apart. The richer set wins, because "submitted but not yet graded" is a real
 * state that a long-answer question puts every attempt into.
 */
enum AttemptStatus: string
{
    case NotStarted    = 'not_started';
    case InProgress    = 'in_progress';
    case Submitted     = 'submitted';
    /** The timer ran out and the system submitted on the learner's behalf. */
    case AutoSubmitted = 'auto_submitted';
    case Grading       = 'grading';
    case Graded        = 'graded';
    case Abandoned     = 'abandoned';

    /**
     * Legacy `assessment_attempts.status` values.
     *
     * `completed` maps to Graded rather than Submitted: the generic assessment
     * had no manual grading, so a completed attempt was always fully scored.
     */
    private const LEGACY_GENERIC = [
        'in_progress' => self::InProgress,
        'completed'   => self::Graded,
        'timed_out'   => self::AutoSubmitted,
        'cancelled'   => self::Abandoned,
    ];

    public static function fromLegacy(string $value): self
    {
        $value = strtolower(trim($value));

        return self::tryFrom($value)
            ?? self::LEGACY_GENERIC[$value]
            ?? throw new \ValueError(sprintf('Unknown legacy attempt status "%s".', $value));
    }

    /** The learner can still answer questions. */
    public function isOpen(): bool
    {
        return $this === self::InProgress || $this === self::NotStarted;
    }

    /** The learner is finished, whether or not scoring is. */
    public function isSubmitted(): bool
    {
        return match ($this) {
            self::Submitted, self::AutoSubmitted, self::Grading, self::Graded => true,
            self::NotStarted, self::InProgress, self::Abandoned              => false,
        };
    }

    /** Counts towards `max_attempts`. An abandoned attempt does not. */
    public function countsAsAttempt(): bool
    {
        return $this->isSubmitted();
    }

    public function isScored(): bool
    {
        return $this === self::Graded;
    }

    public function label(): string
    {
        return match ($this) {
            self::NotStarted    => 'Not started',
            self::InProgress    => 'In progress',
            self::Submitted     => 'Submitted',
            self::AutoSubmitted => 'Submitted automatically',
            self::Grading       => 'Being graded',
            self::Graded        => 'Graded',
            self::Abandoned     => 'Abandoned',
        };
    }
}
