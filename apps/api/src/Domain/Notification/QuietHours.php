<?php

declare(strict_types=1);

namespace Central\Api\Domain\Notification;

/**
 * A window during which someone should not be interrupted.
 *
 * The legacy stored `quiet_hours_start` and `quiet_hours_end` on
 * `user_messaging_settings` and never read them, which is fortunate, because
 * the obvious reading is wrong. Quiet hours normally wrap midnight — 22:00 to
 * 07:00 is the usual setting — and `$now >= $start && $now <= $end` is false
 * for every minute of that window. A naive implementation silently does
 * nothing, which looks exactly like a working one.
 *
 * Pure: no clock, no timezone lookup, no database. Given a local time it
 * answers whether that time is inside the window and when the window ends.
 */
final readonly class QuietHours
{
    private function __construct(
        /** Minutes past local midnight, 0–1439. */
        public int $startMinute,
        public int $endMinute,
    ) {
    }

    /**
     * @throws \InvalidArgumentException on unparseable input
     */
    public static function between(string $start, string $end): self
    {
        return new self(self::minutes($start), self::minutes($end));
    }

    /**
     * Both or neither — a window with one end is not a window.
     */
    public static function tryBetween(?string $start, ?string $end): ?self
    {
        if ($start === null || $end === null || trim($start) === '' || trim($end) === '') {
            return null;
        }

        try {
            return self::between($start, $end);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Whether the window runs through midnight.
     */
    public function wrapsMidnight(): bool
    {
        return $this->startMinute > $this->endMinute;
    }

    /**
     * A window whose ends are equal covers nothing.
     *
     * Read the other way round it would cover the entire day and silence the
     * person permanently, which is not what someone setting 09:00 to 09:00
     * means.
     */
    public function isEmpty(): bool
    {
        return $this->startMinute === $this->endMinute;
    }

    public function covers(\DateTimeImmutable $localTime): bool
    {
        if ($this->isEmpty()) {
            return false;
        }

        $minute = ((int) $localTime->format('G')) * 60 + (int) $localTime->format('i');

        return $this->wrapsMidnight()
            ? $minute >= $this->startMinute || $minute < $this->endMinute
            : $minute >= $this->startMinute && $minute < $this->endMinute;
    }

    /**
     * The next moment it is acceptable to interrupt.
     *
     * Returns the given time unchanged when it is already outside the window,
     * so callers can apply it unconditionally.
     */
    public function nextOpening(\DateTimeImmutable $localTime): \DateTimeImmutable
    {
        if (!$this->covers($localTime)) {
            return $localTime;
        }

        $opening = $localTime
            ->setTime(intdiv($this->endMinute, 60), $this->endMinute % 60, 0);

        // Inside a wrapping window, 07:00 today is in the past whenever the
        // current time is still on the previous evening's side of midnight.
        return $opening <= $localTime ? $opening->modify('+1 day') : $opening;
    }

    public function start(): string
    {
        return self::format($this->startMinute);
    }

    public function end(): string
    {
        return self::format($this->endMinute);
    }

    private static function format(int $minute): string
    {
        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }

    private static function minutes(string $time): int
    {
        // Accepts 22:00 and 22:00:00 — PostgreSQL returns the latter.
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($time), $m) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a time of day.', $time));
        }

        $hours   = (int) $m[1];
        $minutes = (int) $m[2];

        if ($hours > 23 || $minutes > 59) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a time of day.', $time));
        }

        return $hours * 60 + $minutes;
    }
}
