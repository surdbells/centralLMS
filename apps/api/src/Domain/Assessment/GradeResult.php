<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

/**
 * The outcome of marking one answer.
 */
final readonly class GradeResult
{
    public function __construct(
        public float $pointsEarned,
        public float $pointsPossible,
        public bool $isCorrect,
        public bool $needsHumanGrading,
    ) {
    }

    /**
     * An answer no machine can mark.
     *
     * `isCorrect` is deliberately false rather than null-ish: nothing should
     * read an ungraded essay as correct while it waits for a marker.
     * `pointsEarned` stays at zero until a human sets it, so a total computed
     * before marking finishes understates rather than overstates.
     */
    public static function awaitingHuman(float $pointsPossible): self
    {
        return new self(
            pointsEarned: 0.0,
            pointsPossible: $pointsPossible,
            isCorrect: false,
            needsHumanGrading: true,
        );
    }
}
