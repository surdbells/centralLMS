<?php

declare(strict_types=1);

namespace Central\Api\Domain\Dashboard;

/**
 * How many days in a row somebody has done something.
 *
 * Worked out from the days they were active, not stored. `user_streaks` held
 * `current_streak` and `longest_streak` as columns, which meant every one of
 * them was wrong the moment a day passed without anybody writing to the table —
 * and the only thing that wrote to it read `learning_sessions`, which holds
 * three rows for the entire platform. Eight people therefore had a stored
 * streak derived from almost no activity, and nothing ever decremented it.
 *
 * A streak is a fact about a list of dates. This is that function.
 */
final readonly class LearningStreak
{
    /**
     * @param list<string>                                       $activeDays `Y-m-d`, most recent first, no duplicates
     * @param list<array{date: string, day: string, active: bool}> $week
     */
    private function __construct(
        public int $current,
        public int $longest,
        public bool $isActive,
        public ?string $lastActiveOn,
        public array $activeDays,
        /** The last seven days, oldest first, for the little row of dots. */
        public array $week,
    ) {
    }

    /**
     * @param list<string> $days `Y-m-d`, in any order, duplicates tolerated
     */
    public static function from(array $days, \DateTimeImmutable $today): self
    {
        $unique = array_values(array_unique(array_filter($days, static fn (string $d): bool => $d !== '')));
        rsort($unique);

        $todayKey     = $today->format('Y-m-d');
        $yesterdayKey = $today->modify('-1 day')->format('Y-m-d');
        $lookup       = array_flip($unique);

        return new self(
            current: self::runEndingAt($lookup, $todayKey, $yesterdayKey, $today),
            longest: self::longestRun($unique),
            // A streak survives today having not started yet. Counting today's
            // absence as a break tells somebody at nine in the morning that
            // they have lost a run they have all day to continue.
            isActive: isset($lookup[$todayKey]) || isset($lookup[$yesterdayKey]),
            lastActiveOn: $unique[0] ?? null,
            activeDays: $unique,
            week: self::week($lookup, $today),
        );
    }

    /**
     * The run of consecutive days ending today, or ending yesterday if today
     * has not been used yet.
     *
     * @param array<string, int> $lookup
     */
    private static function runEndingAt(
        array $lookup,
        string $todayKey,
        string $yesterdayKey,
        \DateTimeImmutable $today,
    ): int {
        $cursor = match (true) {
            isset($lookup[$todayKey])     => $today,
            isset($lookup[$yesterdayKey]) => $today->modify('-1 day'),
            default                       => null,
        };

        if ($cursor === null) {
            return 0;
        }

        $run = 0;

        while (isset($lookup[$cursor->format('Y-m-d')])) {
            $run++;
            $cursor = $cursor->modify('-1 day');
        }

        return $run;
    }

    /**
     * @param list<string> $descending
     */
    private static function longestRun(array $descending): int
    {
        if ($descending === []) {
            return 0;
        }

        $longest  = 1;
        $run      = 1;
        $previous = new \DateTimeImmutable($descending[0]);

        foreach (array_slice($descending, 1) as $day) {
            $date = new \DateTimeImmutable($day);
            $gap  = (int) $previous->diff($date)->days;

            $run      = $gap === 1 ? $run + 1 : 1;
            $longest  = max($longest, $run);
            $previous = $date;
        }

        return $longest;
    }

    /**
     * @param  array<string, int> $lookup
     * @return list<array{date: string, day: string, active: bool}>
     */
    private static function week(array $lookup, \DateTimeImmutable $today): array
    {
        $week = [];

        for ($back = 6; $back >= 0; $back--) {
            $date = $today->modify(sprintf('-%d days', $back));
            $key  = $date->format('Y-m-d');

            $week[] = [
                'date'   => $key,
                'day'    => $date->format('D'),
                'active' => isset($lookup[$key]),
            ];
        }

        return $week;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'current'      => $this->current,
            'longest'      => $this->longest,
            'isActive'     => $this->isActive,
            'lastActiveOn' => $this->lastActiveOn,
            'week'         => $this->week,
        ];
    }
}
