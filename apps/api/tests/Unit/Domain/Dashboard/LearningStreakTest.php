<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Dashboard;

use Central\Api\Domain\Dashboard\DashboardPeriod;
use Central\Api\Domain\Dashboard\LearningStreak;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A streak is a fact about a list of dates.
 *
 * `user_streaks` stored it as two columns, kept up to date by whatever wrote
 * `learning_sessions` — a table holding three rows for the whole platform. So
 * eight people carried a number that nothing could decrement and almost
 * nothing had incremented.
 */
#[CoversClass(LearningStreak::class)]
#[CoversClass(DashboardPeriod::class)]
final class LearningStreakTest extends TestCase
{
    private const TODAY = '2026-03-11';

    #[Test]
    public function consecutive_days_ending_today_are_the_current_streak(): void
    {
        $streak = $this->streak(['2026-03-11', '2026-03-10', '2026-03-09']);

        self::assertSame(3, $streak->current);
        self::assertTrue($streak->isActive);
    }

    #[Test]
    public function a_gap_ends_the_run(): void
    {
        $streak = $this->streak(['2026-03-11', '2026-03-10', '2026-03-08', '2026-03-07']);

        self::assertSame(2, $streak->current);
    }

    #[Test]
    public function a_streak_survives_a_day_that_has_not_finished_yet(): void
    {
        // Somebody who studied every evening for a fortnight and is reading
        // this at nine in the morning has not lost their streak.
        $streak = $this->streak(['2026-03-10', '2026-03-09', '2026-03-08']);

        self::assertSame(3, $streak->current);
        self::assertTrue($streak->isActive);
    }

    #[Test]
    public function two_days_of_silence_ends_it(): void
    {
        $streak = $this->streak(['2026-03-09', '2026-03-08', '2026-03-07']);

        self::assertSame(0, $streak->current);
        self::assertFalse($streak->isActive);
        // The record stands even though the run is over, which is the whole
        // reason for keeping the longest separately.
        self::assertSame(3, $streak->longest);
    }

    #[Test]
    public function the_longest_run_is_found_anywhere_in_the_history(): void
    {
        $streak = $this->streak([
            '2026-03-11', '2026-03-10',
            '2026-02-05', '2026-02-04', '2026-02-03', '2026-02-02', '2026-02-01',
        ]);

        self::assertSame(2, $streak->current);
        self::assertSame(5, $streak->longest);
    }

    #[Test]
    public function a_run_across_a_month_boundary_is_still_a_run(): void
    {
        // Date arithmetic done on the day number rather than the date is how
        // a streak resets on the first of every month.
        $streak = $this->streak(['2026-03-02', '2026-03-01', '2026-02-28', '2026-02-27']);

        self::assertSame(4, $streak->longest);
    }

    #[Test]
    public function a_run_across_a_leap_day_is_still_a_run(): void
    {
        $streak = $this->streak(['2024-03-01', '2024-02-29', '2024-02-28']);

        self::assertSame(3, $streak->longest);
    }

    #[Test]
    public function somebody_who_has_never_been_active_has_no_streak(): void
    {
        $streak = $this->streak([]);

        self::assertSame(0, $streak->current);
        self::assertSame(0, $streak->longest);
        self::assertFalse($streak->isActive);
        self::assertNull($streak->lastActiveOn);
        self::assertCount(7, $streak->week);
    }

    #[Test]
    public function the_same_day_twice_counts_once(): void
    {
        // Two lessons finished on one afternoon is one day of activity, and
        // the query that feeds this unions two tables.
        $streak = $this->streak(['2026-03-11', '2026-03-11', '2026-03-10']);

        self::assertSame(2, $streak->current);
    }

    #[Test]
    public function the_days_need_not_arrive_in_order(): void
    {
        $streak = $this->streak(['2026-03-09', '2026-03-11', '2026-03-10']);

        self::assertSame(3, $streak->current);
        self::assertSame('2026-03-11', $streak->lastActiveOn);
    }

    #[Test]
    public function the_week_is_seven_days_ending_today(): void
    {
        $streak = $this->streak(['2026-03-11', '2026-03-08']);

        self::assertCount(7, $streak->week);
        self::assertSame('2026-03-05', $streak->week[0]['date']);
        self::assertSame('2026-03-11', $streak->week[6]['date']);
        self::assertTrue($streak->week[6]['active']);
        self::assertTrue($streak->week[3]['active']);
        self::assertFalse($streak->week[5]['active']);
    }

    // --- periods ----------------------------------------------------------

    #[Test]
    public function the_previous_window_is_the_same_length_and_immediately_before(): void
    {
        // Not "last month": comparing a 28-day February with a 31-day March
        // reports a 10% decline in a month where nothing changed.
        $period = DashboardPeriod::of(30, new \DateTimeImmutable('2026-03-11 12:00:00'));

        self::assertSame('2026-02-09', $period->from->format('Y-m-d'));
        self::assertSame('2026-03-11', $period->to->format('Y-m-d'));
        self::assertSame('2026-01-10', $period->previousFrom->format('Y-m-d'));
        self::assertSame('2026-02-09', $period->previousTo->format('Y-m-d'));
    }

    #[Test]
    public function an_unrecognised_period_falls_back_to_a_month(): void
    {
        $period = DashboardPeriod::of(999, new \DateTimeImmutable('2026-03-11'));

        self::assertSame(30, $period->days);
    }

    #[Test]
    public function growth_against_an_empty_window_is_not_a_number(): void
    {
        // The legacy divided by it. Every tenant's first month therefore
        // showed +100% growth, and every month after a quiet one did too.
        self::assertNull(DashboardPeriod::growth(3, 0));
        self::assertSame(50.0, DashboardPeriod::growth(30, 20));
        self::assertSame(-25.0, DashboardPeriod::growth(15, 20));
        self::assertSame(0.0, DashboardPeriod::growth(0, 0) ?? 0.0);
    }

    /**
     * @param list<string> $days
     */
    private function streak(array $days): LearningStreak
    {
        return LearningStreak::from($days, new \DateTimeImmutable(self::TODAY . ' 09:00:00'));
    }
}
