<?php

declare(strict_types=1);

namespace Central\Api\Domain\Engagement;

/**
 * Sessions: a stretch of a person actually using the app, and what the
 * engagement reports read off them.
 *
 * All the analytics take a half-open [$from, $to) window and a tenant, and none
 * omits the tenant.
 */
interface UserSessionRepository
{
    /**
     * Records that the caller is present now.
     *
     * A ping within $idleMinutes of their last extends the current session; a
     * later one opens a new session (the device is read from the user-agent at
     * that point). Returns the session id touched.
     */
    public function touch(
        string $tenantId,
        string $userId,
        string $deviceType,
        ?string $userAgent,
        ?string $ip,
        int $idleMinutes,
    ): string;

    /**
     * Distinct learners with at least one session in the window, and the
     * session count and average length.
     *
     * @return array{learners: int, sessions: int, averageMinutes: float}
     */
    public function totals(string $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to): array;

    /**
     * Distinct active learners per bucket — DAU / WAU / MAU.
     *
     * @param 'day'|'week'|'month' $bucket
     *
     * @return list<array{period: string, learners: int, sessions: int}>
     */
    public function activeSeries(
        string $tenantId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $bucket,
    ): array;

    /**
     * Sessions and distinct learners by device, over the window.
     *
     * @return list<array{device: string, sessions: int, learners: int}>
     */
    public function deviceUsage(string $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to): array;

    /**
     * Sign-in frequency from the per-sign-in records (refresh tokens), not
     * sessions: total sign-ins, distinct people who signed in, and the average
     * per person who did.
     *
     * @return array{signIns: int, learners: int, averagePerLearner: float}
     */
    public function loginFrequency(string $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
