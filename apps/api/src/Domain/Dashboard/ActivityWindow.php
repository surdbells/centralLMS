<?php

declare(strict_types=1);

namespace Central\Api\Domain\Dashboard;

/**
 * The stretch of days a learner's activity chart covers.
 *
 * The chart had a Week/Month toggle and a pair of arrows either side of it,
 * and none of the three did anything: the window was a constant fourteen days
 * ending today, so "Month" drew a fortnight and the arrows logged to the
 * console. This is what those controls now ask for.
 *
 * The end is a date rather than an offset because the caller is stepping
 * backwards through a chart, and "three weeks before today" means something
 * different depending on when the request is answered.
 */
final readonly class ActivityWindow
{
    /** A fortnight, which is what the chart drew before it could be asked. */
    public const DEFAULT_DAYS = 14;

    private const MIN_DAYS = 7;

    /** A quarter. Past this the chart is a smear and the query is not cheap. */
    private const MAX_DAYS = 92;

    private function __construct(
        public int $days,
        public \DateTimeImmutable $until,
    ) {
    }

    public static function of(int $days, \DateTimeImmutable $until, \DateTimeImmutable $now): self
    {
        // Clamped rather than refused. A chart is not worth a 422, and a
        // number outside the range is a caller asking for more than there is
        // rather than a caller asking for something meaningless.
        $days = max(self::MIN_DAYS, min(self::MAX_DAYS, $days));

        // Nobody has been learning tomorrow. Without this the arrows walk
        // forward for ever, drawing an ever-emptier chart.
        if ($until > $now) {
            $until = $now;
        }

        return new self($days, $until);
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query, \DateTimeImmutable $now): self
    {
        $days = isset($query['activityDays']) && is_numeric($query['activityDays'])
            ? (int) $query['activityDays']
            : self::DEFAULT_DAYS;

        return self::of($days, self::date($query['activityUntil'] ?? null, $now), $now);
    }

    /**
     * A date the caller sent, or today if they sent nothing usable.
     *
     * An unparseable date is today rather than an error, for the same reason
     * an out-of-range day count is clamped.
     */
    private static function date(mixed $value, \DateTimeImmutable $now): \DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return $now;
        }

        try {
            return new \DateTimeImmutable($value . ' 00:00:00', $now->getTimezone());
        } catch (\Exception) {
            return $now;
        }
    }

    /** The first day drawn. */
    public function from(): \DateTimeImmutable
    {
        return $this->until->modify(sprintf('-%d days', $this->days - 1));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'days'  => $this->days,
            'from'  => $this->from()->format('Y-m-d'),
            'until' => $this->until->format('Y-m-d'),
        ];
    }
}
