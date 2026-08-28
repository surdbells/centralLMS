<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

/**
 * How a rubric arrives at a mark.
 */
enum RubricKind: string
{
    /**
     * Every criterion scored on its own, and the marks summed.
     *
     * What most marking wants: a learner is told they did well on structure
     * and poorly on evidence, which is something they can act on.
     */
    case Analytic = 'analytic';

    /**
     * One judgement against the whole piece.
     *
     * Faster, and right when the criteria are not really separable — a
     * translation is good or it is not, and scoring its grammar apart from its
     * meaning says less than the single verdict does.
     */
    case Holistic = 'holistic';

    public function label(): string
    {
        return match ($this) {
            self::Analytic => 'Criterion by criterion',
            self::Holistic => 'One overall judgement',
        };
    }

    /**
     * Whether a marker picks one level across the whole rubric.
     *
     * A holistic rubric still has criteria — they describe what to look at —
     * but the mark is one choice rather than a sum.
     */
    public function isSingleJudgement(): bool
    {
        return $this === self::Holistic;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }
}
