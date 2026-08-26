<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

/**
 * When a saved report should run itself.
 *
 * Pure, and the reason it is pure is the reason `next_run_at` is not stored.
 * On `scheduled_reports` that column sat beside the frequency it was derived
 * from and nothing recomputed it, so changing a weekly report to monthly left
 * it arriving weekly until somebody noticed. Here due-ness is asked, never
 * remembered: given the last completed run and the current time, this class
 * answers.
 */
final readonly class ReportSchedule
{
    public function __construct(
        public ScheduleFrequency $frequency,
        /** Minutes past local midnight. */
        public int $timeOfDay,
        /** 1 = Monday … 7 = Sunday. Weekly only. */
        public ?int $dayOfWeek = null,
        /** 1–28. Monthly only — see the migration for why not 31. */
        public ?int $dayOfMonth = null,
        /** @var list<string> */
        public array $recipients = [],
        public bool $paused = false,
    ) {
    }

    /**
     * Whether it should run now.
     *
     * Two conditions, and both matter. The window must have opened — today is
     * the right day and the hour has passed — and it must not have run in this
     * window already, or a scheduler ticking every ten minutes would send the
     * same report six times an hour.
     */
    public function isDue(?\DateTimeImmutable $lastRun, \DateTimeImmutable $now): bool
    {
        if ($this->paused) {
            return false;
        }

        $openedAt = $this->windowOpenedAt($now);

        if ($openedAt === null || $openedAt > $now) {
            return false;
        }

        // Never run, and its window is open.
        return $lastRun === null || $lastRun < $openedAt;
    }

    /**
     * The start of the current window, or null if today is not a run day.
     */
    public function windowOpenedAt(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $at = $now->setTime(intdiv($this->timeOfDay, 60), $this->timeOfDay % 60, 0);

        return match ($this->frequency) {
            ScheduleFrequency::Daily => $at,

            ScheduleFrequency::Weekly => (int) $now->format('N') === $this->dayOfWeek ? $at : null,

            ScheduleFrequency::Monthly => (int) $now->format('j') === $this->dayOfMonth ? $at : null,
        };
    }

    /**
     * When it will next run, for showing somebody what they have set up.
     *
     * Derived on read like everything else here — it is never written down.
     */
    public function nextRunAfter(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $at = $now->setTime(intdiv($this->timeOfDay, 60), $this->timeOfDay % 60, 0);

        return match ($this->frequency) {
            ScheduleFrequency::Daily => $at > $now ? $at : $at->modify('+1 day'),

            ScheduleFrequency::Weekly => $this->nextWeekly($at, $now),

            ScheduleFrequency::Monthly => $this->nextMonthly($at, $now),
        };
    }

    /**
     * Builds from a request body.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, string> $violations
     */
    public static function fromInput(array $input, array &$violations): ?self
    {
        $frequency = ScheduleFrequency::tryFrom(strtolower(trim((string) ($input['frequency'] ?? ''))));

        if ($frequency === null) {
            $violations['schedule.frequency'] = 'Choose daily, weekly or monthly.';
        }

        $timeOfDay = self::minutes((string) ($input['timeOfDay'] ?? '08:00'));

        if ($timeOfDay === null) {
            $violations['schedule.timeOfDay'] = 'Times look like 08:00.';
        }

        $dayOfWeek  = isset($input['dayOfWeek']) ? (int) $input['dayOfWeek'] : null;
        $dayOfMonth = isset($input['dayOfMonth']) ? (int) $input['dayOfMonth'] : null;

        if ($frequency === ScheduleFrequency::Weekly && ($dayOfWeek === null || $dayOfWeek < 1 || $dayOfWeek > 7)) {
            $violations['schedule.dayOfWeek'] = 'Give a day, 1 for Monday through 7 for Sunday.';
        }

        if ($frequency === ScheduleFrequency::Monthly && ($dayOfMonth === null || $dayOfMonth < 1 || $dayOfMonth > 28)) {
            // 28 is the cap on purpose: the 31st would skip five months a year.
            $violations['schedule.dayOfMonth'] = 'Give a day from 1 to 28 — later days skip short months.';
        }

        $recipients = self::recipients($input['recipients'] ?? null, $violations);

        if ($violations !== []) {
            return null;
        }

        \assert($frequency instanceof ScheduleFrequency && $timeOfDay !== null);

        return new self(
            $frequency,
            $timeOfDay,
            $frequency === ScheduleFrequency::Weekly ? $dayOfWeek : null,
            $frequency === ScheduleFrequency::Monthly ? $dayOfMonth : null,
            $recipients,
            (bool) ($input['paused'] ?? false),
        );
    }

    public function timeOfDayString(): string
    {
        return sprintf('%02d:%02d', intdiv($this->timeOfDay, 60), $this->timeOfDay % 60);
    }

    /**
     * A sentence describing it, for the screen that lists schedules.
     */
    public function describe(): string
    {
        $at = $this->timeOfDayString();

        return match ($this->frequency) {
            ScheduleFrequency::Daily   => sprintf('Every day at %s', $at),
            ScheduleFrequency::Weekly  => sprintf('Every %s at %s', self::weekdayName($this->dayOfWeek ?? 1), $at),
            ScheduleFrequency::Monthly => sprintf('On day %d of each month at %s', $this->dayOfMonth ?? 1, $at),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'frequency'   => $this->frequency->value,
            'timeOfDay'   => $this->timeOfDayString(),
            'dayOfWeek'   => $this->dayOfWeek,
            'dayOfMonth'  => $this->dayOfMonth,
            'recipients'  => $this->recipients,
            'paused'      => $this->paused,
            'description' => $this->describe(),
        ];
    }

    private function nextWeekly(\DateTimeImmutable $at, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $today  = (int) $now->format('N');
        $target = $this->dayOfWeek ?? 1;

        $days = ($target - $today + 7) % 7;

        if ($days === 0 && $at <= $now) {
            $days = 7;
        }

        return $at->modify(sprintf('+%d days', $days));
    }

    private function nextMonthly(\DateTimeImmutable $at, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $target    = $this->dayOfMonth ?? 1;
        $thisMonth = $at->setDate((int) $now->format('Y'), (int) $now->format('n'), $target);

        if ($thisMonth > $now) {
            return $thisMonth;
        }

        // Move to the first of next month before setting the day. `+1 month`
        // on the 31st of January lands in March, because PHP adds a month and
        // then normalises the overflow. The cap of 28 makes it unreachable
        // here; the order is still the one that is right.
        $nextMonth = $at->modify('first day of next month');

        return $nextMonth->setDate(
            (int) $nextMonth->format('Y'),
            (int) $nextMonth->format('n'),
            $target,
        );
    }

    private static function minutes(string $time): ?int
    {
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($time), $m) !== 1) {
            return null;
        }

        $hours   = (int) $m[1];
        $minutes = (int) $m[2];

        return $hours > 23 || $minutes > 59 ? null : $hours * 60 + $minutes;
    }

    /**
     * @param  array<string, string> $violations
     * @return list<string>
     */
    private static function recipients(mixed $value, array &$violations): array
    {
        if (!is_array($value) || $value === []) {
            $violations['schedule.recipients'] = 'Say who the report should go to.';

            return [];
        }

        $recipients = [];

        foreach ($value as $address) {
            $address = strtolower(trim((string) $address));

            if ($address === '') {
                continue;
            }

            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                $violations['schedule.recipients'] = sprintf('"%s" is not an email address.', $address);

                continue;
            }

            if (!in_array($address, $recipients, true)) {
                $recipients[] = $address;
            }
        }

        if ($recipients === [] && !isset($violations['schedule.recipients'])) {
            $violations['schedule.recipients'] = 'Say who the report should go to.';
        }

        return $recipients;
    }

    private static function weekdayName(int $day): string
    {
        return [
            1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
            5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday',
        ][$day] ?? 'Monday';
    }
}
