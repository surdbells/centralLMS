<?php

declare(strict_types=1);

namespace Central\Api\Domain\Dashboard;

/**
 * The window a dashboard reports on, and the one before it.
 *
 * The previous window is the point. Every "growth" figure on the admin
 * dashboard is this period against the one before it, and the legacy compared
 * "this month" with "last month" using `MONTH(created_at) = MONTH(NOW()) - 1`
 * — which is 0 in January, so every January the previous month is empty and
 * growth reads as +100%.
 */
final readonly class DashboardPeriod
{
    private const ALLOWED = [7, 30, 90, 365];

    private function __construct(
        public int $days,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public \DateTimeImmutable $previousFrom,
        public \DateTimeImmutable $previousTo,
    ) {
    }

    public static function of(int $days, \DateTimeImmutable $now): self
    {
        if (!in_array($days, self::ALLOWED, true)) {
            $days = 30;
        }

        $to   = $now;
        $from = $now->modify(sprintf('-%d days', $days));

        return new self(
            days: $days,
            from: $from,
            to: $to,
            // Immediately before, and the same length, so the two are
            // comparable. A calendar month against a calendar month compares
            // 28 days with 31.
            previousFrom: $from->modify(sprintf('-%d days', $days)),
            previousTo: $from,
        );
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query, \DateTimeImmutable $now): self
    {
        return self::of((int) ($query['period'] ?? 30), $now);
    }

    /**
     * How much a figure moved, as a percentage of the previous window.
     *
     * Null rather than 100 when the previous window was empty: going from no
     * enrolments to three is not growth of one hundred per cent, and drawing
     * it as such is how a dashboard reports a triumph on the day a tenant is
     * created.
     */
    public static function growth(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * @return list<int>
     */
    public static function allowed(): array
    {
        return self::ALLOWED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'days' => $this->days,
            'from' => $this->from->format('Y-m-d'),
            'to'   => $this->to->format('Y-m-d'),
        ];
    }
}
