<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Notification;

use Central\Api\Domain\Notification\NotificationChannel;
use Central\Api\Domain\Notification\NotificationSchedule;
use Central\Api\Domain\Notification\NotificationType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reminders are expressed as offsets, never as times.
 *
 * `live_training_reminders.scheduled_at` held the session's start minus the
 * offset, and nothing recomputed it when a session moved. The first test here
 * is the one that would have caught that.
 */
#[CoversClass(NotificationSchedule::class)]
final class NotificationScheduleTest extends TestCase
{
    #[Test]
    public function moving_the_session_moves_the_reminder(): void
    {
        // The whole design in one assertion: the same schedule object, asked
        // about two different session times, gives two different due times.
        $schedule = $this->schedule(60);

        self::assertSame(
            '2026-03-01 08:00',
            $schedule->dueAt(new \DateTimeImmutable('2026-03-01 09:00'))->format('Y-m-d H:i'),
        );

        self::assertSame(
            '2026-03-05 13:00',
            $schedule->dueAt(new \DateTimeImmutable('2026-03-05 14:00'))->format('Y-m-d H:i'),
        );
    }

    #[Test]
    public function a_reminder_is_due_once_its_moment_arrives(): void
    {
        $schedule = $this->schedule(60);
        $starts   = new \DateTimeImmutable('2026-03-01 09:00');

        self::assertFalse($schedule->isDue($starts, new \DateTimeImmutable('2026-03-01 07:59')));
        self::assertTrue($schedule->isDue($starts, new \DateTimeImmutable('2026-03-01 08:00')));
        self::assertTrue($schedule->isDue($starts, new \DateTimeImmutable('2026-03-01 08:30')));
    }

    #[Test]
    public function a_reminder_that_has_already_fired_is_not_due_again(): void
    {
        $schedule = $this->schedule(60, status: \Central\Api\Domain\Notification\ScheduleStatus::Expanded);

        self::assertFalse($schedule->isDue(
            new \DateTimeImmutable('2026-03-01 09:00'),
            new \DateTimeImmutable('2026-03-01 08:30'),
        ));
    }

    #[Test]
    public function a_reminder_lapses_once_the_session_has_started(): void
    {
        // Telling somebody at 09:15 that a 09:00 session "starts in 1 hour"
        // leaves them worse off than telling them nothing.
        $schedule = $this->schedule(60);
        $starts   = new \DateTimeImmutable('2026-03-01 09:00');

        self::assertTrue($schedule->hasLapsed($starts, new \DateTimeImmutable('2026-03-01 09:15')));
    }

    #[Test]
    public function a_worker_a_few_minutes_late_still_sends(): void
    {
        // The grace period exists for a worker that was briefly down.
        $schedule = $this->schedule(60);
        $starts   = new \DateTimeImmutable('2026-03-01 09:00');

        self::assertFalse($schedule->hasLapsed($starts, new \DateTimeImmutable('2026-03-01 08:10')));
    }

    #[Test]
    public function a_worker_down_past_the_grace_period_does_not_send(): void
    {
        $schedule = $this->schedule(60);
        $starts   = new \DateTimeImmutable('2026-03-01 09:00');

        self::assertTrue($schedule->hasLapsed($starts, new \DateTimeImmutable('2026-03-01 08:20')));
    }

    #[Test]
    public function a_session_booked_inside_its_own_lead_time_lapses_rather_than_firing_late(): void
    {
        // Booking tomorrow morning with a 24-hour reminder on it: the moment
        // is already past when the schedule is created.
        $schedule = $this->schedule(1440);
        $starts   = new \DateTimeImmutable('2026-03-02 09:00');
        $now      = new \DateTimeImmutable('2026-03-01 18:00');

        self::assertTrue($schedule->isDue($starts, $now));
        self::assertTrue($schedule->hasLapsed($starts, $now));
    }

    // --- building from a programme's configuration ------------------------

    #[Test]
    public function offsets_become_schedules_longest_lead_time_first(): void
    {
        $schedules = $this->forOffsets([60, 1440, 15]);

        self::assertSame([1440, 60, 15], array_map(
            static fn (NotificationSchedule $s): int => $s->offsetMinutes,
            $schedules,
        ));
    }

    #[Test]
    public function a_repeated_offset_produces_one_reminder(): void
    {
        // The unique index would reject the second anyway.
        self::assertCount(2, $this->forOffsets([60, 60, 1440]));
    }

    #[Test]
    public function an_impossible_offset_is_dropped_rather_than_stored(): void
    {
        // Negative would mean "after it starts"; beyond thirty days is past
        // the check constraint.
        $schedules = $this->forOffsets([-60, 60, 50000]);

        self::assertCount(1, $schedules);
        self::assertSame(60, $schedules[0]->offsetMinutes);
    }

    #[Test]
    public function a_schedule_always_has_somewhere_to_go(): void
    {
        // An empty channel list is rejected by the schema, and a reminder
        // with nowhere to go is not a reminder.
        $schedules = NotificationSchedule::forSubject(
            'tenant-1',
            NotificationType::SessionReminder,
            'training_session',
            'session-1',
            [60],
            [],
        );

        self::assertSame([NotificationChannel::InApp], $schedules[0]->channels);
    }

    // --- helpers ----------------------------------------------------------

    private function schedule(
        int $offsetMinutes,
        \Central\Api\Domain\Notification\ScheduleStatus $status = \Central\Api\Domain\Notification\ScheduleStatus::Pending,
    ): NotificationSchedule {
        return new NotificationSchedule(
            id: 'schedule-1',
            tenantId: 'tenant-1',
            type: NotificationType::SessionReminder,
            subjectType: 'training_session',
            subjectId: 'session-1',
            offsetMinutes: $offsetMinutes,
            status: $status,
        );
    }

    /**
     * @param  list<int> $offsets
     * @return list<NotificationSchedule>
     */
    private function forOffsets(array $offsets): array
    {
        return NotificationSchedule::forSubject(
            'tenant-1',
            NotificationType::SessionReminder,
            'training_session',
            'session-1',
            $offsets,
            [NotificationChannel::InApp, NotificationChannel::Email],
        );
    }
}
