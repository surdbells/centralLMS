<?php

declare(strict_types=1);

namespace Central\Api\Domain\Gamification;

/**
 * How far back a leaderboard looks.
 *
 * The legacy stored these in a `leaderboards` table alongside a name — three
 * rows, each a saved query whose standings were computed on read anyway. A
 * parameter says the same thing without a table to keep in step.
 */
enum LeaderboardPeriod: string
{
    case AllTime = 'all_time';
    case Monthly = 'monthly';
    case Weekly  = 'weekly';
    case Daily   = 'daily';

    /**
     * The earliest moment that counts, or null for all time.
     *
     * Calendar boundaries rather than rolling windows: "this week" on a
     * leaderboard means since Monday, and a rolling seven days would move the
     * standings under somebody every time they looked.
     */
    public function since(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        return match ($this) {
            self::AllTime => null,
            self::Monthly => $now->modify('first day of this month')->setTime(0, 0),
            self::Weekly  => $now->modify('monday this week')->setTime(0, 0),
            self::Daily   => $now->setTime(0, 0),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::AllTime => 'All time',
            self::Monthly => 'This month',
            self::Weekly  => 'This week',
            self::Daily   => 'Today',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }
}
