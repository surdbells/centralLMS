<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

/**
 * Publication state of an assessment.
 *
 * The generic side had only `draft` and `published`; the proctored side added
 * `closed` (no new attempts, existing ones may finish) and `archived` (hidden
 * from authoring). Both extra states are useful for course quizzes too, so the
 * richer set is the unified one.
 */
enum AssessmentStatus: string
{
    case Draft     = 'draft';
    case Published = 'published';
    case Closed    = 'closed';
    case Archived  = 'archived';

    public static function fromLegacy(string $value): self
    {
        return self::tryFrom(strtolower(trim($value)))
            ?? throw new \ValueError(sprintf('Unknown legacy assessment status "%s".', $value));
    }

    /** Learners can see it and start new attempts. */
    public function isAvailableToLearners(): bool
    {
        return $this === self::Published;
    }

    /** An attempt already in progress may still be completed. */
    public function allowsExistingAttemptsToFinish(): bool
    {
        return $this === self::Published || $this === self::Closed;
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Draft',
            self::Published => 'Published',
            self::Closed    => 'Closed',
            self::Archived  => 'Archived',
        };
    }
}
