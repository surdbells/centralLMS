<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

/**
 * How an assessment decides which questions a learner sees.
 *
 * Legacy generic assessments had no concept of this — questions belonged to
 * the assessment and everyone got all of them. That is exactly `Manual`, so
 * migrated rows take this value and behave identically.
 */
enum QuestionSelectionMode: string
{
    /** A fixed, ordered list chosen by the author. */
    case Manual = 'manual';

    /** N questions drawn at random from the attached banks. */
    case Random = 'random';

    /** Drawn per rule — N of this difficulty, M of that category. */
    case Pool = 'pool';

    public static function fromLegacy(?string $value): self
    {
        if ($value === null || trim($value) === '') {
            // Generic assessments predate the concept.
            return self::Manual;
        }

        return self::tryFrom(strtolower(trim($value)))
            ?? throw new \ValueError(sprintf('Unknown legacy question selection "%s".', $value));
    }

    /** Whether `assessment_random_rules` rows are consulted. */
    public function usesRules(): bool
    {
        return $this === self::Pool;
    }

    /** Whether each learner gets a different question set. */
    public function variesPerLearner(): bool
    {
        return $this !== self::Manual;
    }

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Fixed list',
            self::Random => 'Random selection',
            self::Pool   => 'Rule-based pool',
        };
    }
}
