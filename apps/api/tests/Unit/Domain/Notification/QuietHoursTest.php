<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Notification;

use Central\Api\Domain\Notification\QuietHours;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The wrapping window is the whole point of this class.
 *
 * `22:00`–`07:00` is what people actually set, and the obvious implementation
 * — `$now >= $start && $now <= $end` — is false for every minute of it. That
 * bug does nothing visible: quiet hours simply never apply, which looks
 * exactly like quiet hours working for someone who is awake when they test it.
 */
#[CoversClass(QuietHours::class)]
final class QuietHoursTest extends TestCase
{
    #[Test]
    public function a_window_that_wraps_midnight_covers_the_late_evening(): void
    {
        $quiet = QuietHours::between('22:00', '07:00');

        self::assertTrue($quiet->wrapsMidnight());
        self::assertTrue($quiet->covers(new \DateTimeImmutable('2026-03-01 23:30')));
    }

    #[Test]
    public function a_window_that_wraps_midnight_covers_the_small_hours(): void
    {
        $quiet = QuietHours::between('22:00', '07:00');

        self::assertTrue($quiet->covers(new \DateTimeImmutable('2026-03-02 03:15')));
    }

    #[Test]
    public function a_window_that_wraps_midnight_ends_when_it_says_it_does(): void
    {
        $quiet = QuietHours::between('22:00', '07:00');

        self::assertTrue($quiet->covers(new \DateTimeImmutable('2026-03-02 06:59')));
        self::assertFalse($quiet->covers(new \DateTimeImmutable('2026-03-02 07:00')));
        self::assertFalse($quiet->covers(new \DateTimeImmutable('2026-03-02 12:00')));
    }

    #[Test]
    public function a_daytime_window_does_not_wrap(): void
    {
        $quiet = QuietHours::between('09:00', '17:00');

        self::assertFalse($quiet->wrapsMidnight());
        self::assertTrue($quiet->covers(new \DateTimeImmutable('2026-03-01 12:00')));
        self::assertFalse($quiet->covers(new \DateTimeImmutable('2026-03-01 08:59')));
        self::assertFalse($quiet->covers(new \DateTimeImmutable('2026-03-01 23:00')));
    }

    #[Test]
    public function the_start_minute_is_inside_and_the_end_minute_is_outside(): void
    {
        // Half-open, so a window ending at 07:00 and one starting at 07:00
        // meet without overlapping.
        $quiet = QuietHours::between('22:00', '07:00');

        self::assertTrue($quiet->covers(new \DateTimeImmutable('2026-03-01 22:00')));
        self::assertFalse($quiet->covers(new \DateTimeImmutable('2026-03-02 07:00')));
    }

    #[Test]
    public function a_window_with_equal_ends_covers_nothing(): void
    {
        // Read the other way it would silence the person permanently, which
        // is not what someone typing 09:00 twice means.
        $quiet = QuietHours::between('09:00', '09:00');

        self::assertTrue($quiet->isEmpty());
        self::assertFalse($quiet->covers(new \DateTimeImmutable('2026-03-01 09:00')));
        self::assertFalse($quiet->covers(new \DateTimeImmutable('2026-03-01 21:00')));
    }

    #[Test]
    public function the_next_opening_from_the_evening_side_is_the_following_morning(): void
    {
        $quiet = QuietHours::between('22:00', '07:00');

        self::assertSame(
            '2026-03-02 07:00',
            $quiet->nextOpening(new \DateTimeImmutable('2026-03-01 23:30'))->format('Y-m-d H:i'),
        );
    }

    #[Test]
    public function the_next_opening_from_the_small_hours_is_the_same_morning(): void
    {
        $quiet = QuietHours::between('22:00', '07:00');

        self::assertSame(
            '2026-03-02 07:00',
            $quiet->nextOpening(new \DateTimeImmutable('2026-03-02 03:15'))->format('Y-m-d H:i'),
        );
    }

    #[Test]
    public function a_time_outside_the_window_is_returned_unchanged(): void
    {
        // So callers can apply it unconditionally.
        $quiet = QuietHours::between('22:00', '07:00');
        $now   = new \DateTimeImmutable('2026-03-01 14:00');

        self::assertSame($now->format('c'), $quiet->nextOpening($now)->format('c'));
    }

    #[Test]
    public function postgres_time_values_with_seconds_are_accepted(): void
    {
        // The column is TIME and comes back as 22:00:00.
        $quiet = QuietHours::between('22:00:00', '07:00:00');

        self::assertSame('22:00', $quiet->start());
        self::assertSame('07:00', $quiet->end());
    }

    #[Test]
    public function both_ends_are_needed(): void
    {
        self::assertNull(QuietHours::tryBetween('22:00', null));
        self::assertNull(QuietHours::tryBetween(null, '07:00'));
        self::assertNull(QuietHours::tryBetween(null, null));
        self::assertNull(QuietHours::tryBetween('', ''));
        self::assertNotNull(QuietHours::tryBetween('22:00', '07:00'));
    }

    #[Test]
    public function nonsense_is_rejected_rather_than_guessed_at(): void
    {
        self::assertNull(QuietHours::tryBetween('25:00', '07:00'));
        self::assertNull(QuietHours::tryBetween('22:99', '07:00'));
        self::assertNull(QuietHours::tryBetween('bedtime', 'morning'));

        $this->expectException(\InvalidArgumentException::class);
        QuietHours::between('half past ten', '07:00');
    }
}
