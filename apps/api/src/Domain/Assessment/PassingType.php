<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

/**
 * Whether the pass mark is a percentage or an absolute point total.
 *
 * Generic assessments only had `pass_percentage`, so they migrate as
 * {@see self::Percentage} with no behaviour change.
 */
enum PassingType: string
{
    case Percentage = 'percentage';
    case Points     = 'points';

    public static function fromLegacy(?string $value): self
    {
        if ($value === null || trim($value) === '') {
            return self::Percentage;
        }

        return self::tryFrom(strtolower(trim($value)))
            ?? throw new \ValueError(sprintf('Unknown legacy passing type "%s".', $value));
    }

    /**
     * @param float $threshold `passing_score` — read as a percentage or as points
     *                         depending on this type
     */
    public function isPassed(float $rawScore, float $maxScore, float $threshold): bool
    {
        return match ($this) {
            // A zero-point assessment cannot be failed on a percentage basis;
            // dividing by it would be a fatal, and treating it as 0% would fail
            // every learner on an ungraded survey.
            self::Percentage => $maxScore <= 0.0
                ? true
                : ($rawScore / $maxScore) * 100 >= $threshold,
            self::Points     => $rawScore >= $threshold,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage',
            self::Points     => 'Points',
        };
    }
}
