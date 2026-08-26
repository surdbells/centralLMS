<?php

declare(strict_types=1);

namespace Central\Api\Domain\Gamification;

/**
 * Where somebody stands, computed from their total.
 *
 * Never stored. See {@see LevelLadder}.
 */
final readonly class LevelProgress
{
    public function __construct(
        public int $points,
        public ?GamificationLevel $current,
        public ?GamificationLevel $next,
    ) {
    }

    /** Points still needed for the next rung, or null at the top. */
    public function pointsToNext(): ?int
    {
        return $this->next === null ? null : max(0, $this->next->requiredPoints - $this->points);
    }

    /**
     * How far through the current rung, as a percentage.
     *
     * Measured between the two thresholds rather than from zero: somebody on
     * 1,010 of a 1,000–2,000 band is 1% through it, not 101% of the way to
     * the level they have already reached.
     */
    public function percentToNext(): float
    {
        if ($this->current === null || $this->next === null) {
            // At the top, or no ladder configured. Complete, not zero — the
            // bar should read as finished rather than as never started.
            return 100.0;
        }

        $band = $this->next->requiredPoints - $this->current->requiredPoints;

        if ($band <= 0) {
            return 100.0;
        }

        $through = $this->points - $this->current->requiredPoints;

        return round(max(0.0, min(100.0, 100 * $through / $band)), 1);
    }

    public function isAtTop(): bool
    {
        return $this->current !== null && $this->next === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'points'        => $this->points,
            'current'       => $this->current?->toArray(),
            'next'          => $this->next?->toArray(),
            'pointsToNext'  => $this->pointsToNext(),
            'percentToNext' => $this->percentToNext(),
            'atTop'         => $this->isAtTop(),
        ];
    }
}
