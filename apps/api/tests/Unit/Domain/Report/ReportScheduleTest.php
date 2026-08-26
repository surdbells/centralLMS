<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Report;

use Central\Api\Domain\Report\ReportSchedule;
use Central\Api\Domain\Report\ScheduleFrequency;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * When a saved report runs itself.
 *
 * `scheduled_reports.next_run_at` sat beside the frequency it was derived from
 * and nothing recomputed it, so a weekly report changed to monthly kept
 * arriving weekly. Due-ness is asked here, never remembered.
 */
#[CoversClass(ReportSchedule::class)]
final class ReportScheduleTest extends TestCase
{
    /** A Wednesday. */
    private const WEDNESDAY_9AM = '2026-03-04 09:00:00';

    #[Test]
    public function a_daily_schedule_is_due_once_its_hour_has_passed(): void
    {
        $schedule = $this->daily();

        self::assertFalse($schedule->isDue(null, new \DateTimeImmutable('2026-03-04 07:59')));
        self::assertTrue($schedule->isDue(null, new \DateTimeImmutable('2026-03-04 08:00')));
    }

    #[Test]
    public function a_daily_schedule_that_ran_today_is_not_due_again(): void
    {
        // A scheduler ticking every ten minutes would otherwise send the same
        // report six times an hour.
        $schedule = $this->daily();

        self::assertFalse($schedule->isDue(
            new \DateTimeImmutable('2026-03-04 08:05'),
            new \DateTimeImmutable(self::WEDNESDAY_9AM),
        ));
    }

    #[Test]
    public function a_daily_schedule_that_ran_yesterday_is_due(): void
    {
        self::assertTrue($this->daily()->isDue(
            new \DateTimeImmutable('2026-03-03 08:05'),
            new \DateTimeImmutable(self::WEDNESDAY_9AM),
        ));
    }

    #[Test]
    public function a_weekly_schedule_only_fires_on_its_day(): void
    {
        $monday = $this->weekly(1);

        self::assertFalse($monday->isDue(null, new \DateTimeImmutable(self::WEDNESDAY_9AM)));
        self::assertTrue($monday->isDue(null, new \DateTimeImmutable('2026-03-02 09:00')));
    }

    #[Test]
    public function a_monthly_schedule_only_fires_on_its_date(): void
    {
        $first = $this->monthly(1);

        self::assertFalse($first->isDue(null, new \DateTimeImmutable(self::WEDNESDAY_9AM)));
        self::assertTrue($first->isDue(null, new \DateTimeImmutable('2026-03-01 09:00')));
    }

    #[Test]
    public function a_paused_schedule_never_fires(): void
    {
        $schedule = new ReportSchedule(
            ScheduleFrequency::Daily,
            8 * 60,
            recipients: ['a@b.test'],
            paused: true,
        );

        self::assertFalse($schedule->isDue(null, new \DateTimeImmutable(self::WEDNESDAY_9AM)));
    }

    #[Test]
    public function the_next_run_is_computed_not_stored(): void
    {
        // Changing the frequency changes the answer immediately, because
        // there is nothing written down to go stale.
        $now = new \DateTimeImmutable(self::WEDNESDAY_9AM);

        self::assertSame('2026-03-05 08:00', $this->daily()->nextRunAfter($now)->format('Y-m-d H:i'));
        self::assertSame('2026-03-09 08:00', $this->weekly(1)->nextRunAfter($now)->format('Y-m-d H:i'));
        self::assertSame('2026-04-01 08:00', $this->monthly(1)->nextRunAfter($now)->format('Y-m-d H:i'));
    }

    #[Test]
    public function a_monthly_run_still_to_come_this_month_is_not_pushed_to_next(): void
    {
        self::assertSame(
            '2026-03-15 08:00',
            $this->monthly(15)->nextRunAfter(new \DateTimeImmutable('2026-03-10 09:00'))->format('Y-m-d H:i'),
        );
    }

    #[Test]
    public function a_monthly_schedule_rolls_over_the_year(): void
    {
        self::assertSame(
            '2027-01-15 08:00',
            $this->monthly(15)->nextRunAfter(new \DateTimeImmutable('2026-12-20 09:00'))->format('Y-m-d H:i'),
        );
    }

    #[Test]
    public function a_day_beyond_the_28th_is_refused(): void
    {
        // The 31st would skip February, April, June, September and November,
        // and the person who set it would find out in March.
        $violations = [];

        self::assertNull(ReportSchedule::fromInput([
            'frequency'  => 'monthly',
            'dayOfMonth' => 31,
            'timeOfDay'  => '08:00',
            'recipients' => ['a@b.test'],
        ], $violations));

        self::assertArrayHasKey('schedule.dayOfMonth', $violations);
    }

    #[Test]
    public function a_weekly_schedule_without_a_day_is_refused(): void
    {
        $violations = [];

        self::assertNull(ReportSchedule::fromInput([
            'frequency'  => 'weekly',
            'timeOfDay'  => '08:00',
            'recipients' => ['a@b.test'],
        ], $violations));

        self::assertArrayHasKey('schedule.dayOfWeek', $violations);
    }

    #[Test]
    public function a_schedule_with_nobody_to_send_to_is_refused(): void
    {
        // It would produce a file that goes nowhere, every week, for ever.
        $violations = [];

        self::assertNull(ReportSchedule::fromInput([
            'frequency' => 'daily',
            'timeOfDay' => '08:00',
        ], $violations));

        self::assertArrayHasKey('schedule.recipients', $violations);
    }

    #[Test]
    public function a_recipient_that_is_not_an_address_is_refused(): void
    {
        $violations = [];

        self::assertNull(ReportSchedule::fromInput([
            'frequency'  => 'daily',
            'timeOfDay'  => '08:00',
            'recipients' => ['not-an-address'],
        ], $violations));

        self::assertArrayHasKey('schedule.recipients', $violations);
    }

    #[Test]
    public function duplicate_recipients_collapse(): void
    {
        $violations = [];

        $schedule = ReportSchedule::fromInput([
            'frequency'  => 'daily',
            'timeOfDay'  => '08:00',
            'recipients' => ['A@B.test', 'a@b.test', ' a@b.test '],
        ], $violations);

        self::assertNotNull($schedule);
        self::assertSame(['a@b.test'], $schedule->recipients);
    }

    #[Test]
    public function an_unparseable_time_is_refused(): void
    {
        $violations = [];

        self::assertNull(ReportSchedule::fromInput([
            'frequency'  => 'daily',
            'timeOfDay'  => 'breakfast',
            'recipients' => ['a@b.test'],
        ], $violations));

        self::assertArrayHasKey('schedule.timeOfDay', $violations);
    }

    #[Test]
    public function the_description_reads_as_a_sentence(): void
    {
        self::assertSame('Every day at 08:00', $this->daily()->describe());
        self::assertSame('Every Monday at 08:00', $this->weekly(1)->describe());
        self::assertSame('On day 15 of each month at 08:00', $this->monthly(15)->describe());
    }

    private function daily(): ReportSchedule
    {
        return new ReportSchedule(ScheduleFrequency::Daily, 8 * 60, recipients: ['a@b.test']);
    }

    private function weekly(int $day): ReportSchedule
    {
        return new ReportSchedule(ScheduleFrequency::Weekly, 8 * 60, dayOfWeek: $day, recipients: ['a@b.test']);
    }

    private function monthly(int $day): ReportSchedule
    {
        return new ReportSchedule(ScheduleFrequency::Monthly, 8 * 60, dayOfMonth: $day, recipients: ['a@b.test']);
    }
}
