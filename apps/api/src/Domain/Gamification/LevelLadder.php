<?php

declare(strict_types=1);

namespace Central\Api\Domain\Gamification;

/**
 * The rungs, and where a points total puts somebody on them.
 *
 * Pure, and the reason it is pure is that `user_level_progress` stored
 * `total_points` and `current_level_id` beside the ledger that already implied
 * both — and held one row for the whole system, so for every user but one the
 * summary was simply missing. Asking is cheap; remembering was wrong.
 */
final readonly class LevelLadder
{
    /** @var list<GamificationLevel> ascending by threshold */
    public array $levels;

    /**
     * @param list<GamificationLevel> $levels
     */
    public function __construct(array $levels)
    {
        // Sorted here rather than trusted from the caller: `progressFor()`
        // walks the rungs in order and stops at the first one out of reach,
        // so an unsorted ladder silently reports the wrong level.
        usort(
            $levels,
            static fn (GamificationLevel $a, GamificationLevel $b): int => $a->requiredPoints <=> $b->requiredPoints,
        );

        $this->levels = $levels;
    }

    public function isEmpty(): bool
    {
        return $this->levels === [];
    }

    /**
     * Where this total stands.
     *
     * The highest rung whose threshold it clears, plus how far to the next.
     */
    public function progressFor(int $points): LevelProgress
    {
        $current = null;
        $next    = null;

        foreach ($this->levels as $level) {
            if ($points >= $level->requiredPoints) {
                $current = $level;

                continue;
            }

            $next = $level;

            break;
        }

        // Nobody starts below the bottom rung. A ladder whose first threshold
        // is above zero would otherwise leave a new joiner with no level at
        // all, which reads as an error rather than as a beginning.
        if ($current === null) {
            $current = $this->levels[0] ?? null;
            $next    = $this->levels[1] ?? null;
        }

        return new LevelProgress($points, $current, $next);
    }
}
