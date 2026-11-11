<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Engagement\UserSessionRepository;
use Doctrine\DBAL\Connection;

final class DbalUserSessionRepository implements UserSessionRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function touch(
        string $tenantId,
        string $userId,
        string $deviceType,
        ?string $userAgent,
        ?string $ip,
        int $idleMinutes,
    ): string {
        // Extend the most recent session if it is still within the idle window.
        $extended = $this->connection->fetchOne(
            'UPDATE user_sessions SET last_seen_at = NOW()
              WHERE id = (
                  SELECT id FROM user_sessions
                  WHERE user_id = :user AND tenant_id = :tenant
                    AND last_seen_at >= NOW() - make_interval(mins => :idle)
                  ORDER BY last_seen_at DESC
                  LIMIT 1
              )
              RETURNING id',
            ['user' => $userId, 'tenant' => $tenantId, 'idle' => max(1, $idleMinutes)],
        );

        if (is_string($extended) && $extended !== '') {
            return $extended;
        }

        // None recent enough — open a new session.
        $id = $this->connection->fetchOne(
            'INSERT INTO user_sessions (tenant_id, user_id, device_type, user_agent, ip_address)
             VALUES (:tenant, :user, :device, :agent, :ip)
             RETURNING id',
            [
                'tenant' => $tenantId,
                'user'   => $userId,
                'device' => $deviceType,
                'agent'  => $userAgent,
                'ip'     => $ip,
            ],
        );

        return is_string($id) ? $id : '';
    }

    public function totals(string $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT
                 COUNT(DISTINCT user_id)                                              AS learners,
                 COUNT(*)                                                             AS sessions,
                 COALESCE(AVG(EXTRACT(EPOCH FROM (last_seen_at - started_at)) / 60), 0) AS avg_minutes
             FROM user_sessions
             WHERE tenant_id = :tenant AND started_at >= :from AND started_at < :to',
            $this->window($tenantId, $from, $to),
        );

        $row = $row === false ? [] : $row;

        return [
            'learners'       => (int) ($row['learners'] ?? 0),
            'sessions'       => (int) ($row['sessions'] ?? 0),
            'averageMinutes' => round((float) ($row['avg_minutes'] ?? 0), 1),
        ];
    }

    public function activeSeries(
        string $tenantId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $bucket,
    ): array {
        $trunc = $this->truncUnit($bucket);

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT date_trunc('%s', started_at) AS period,
                        COUNT(DISTINCT user_id)       AS learners,
                        COUNT(*)                      AS sessions
                 FROM user_sessions
                 WHERE tenant_id = :tenant AND started_at >= :from AND started_at < :to
                 GROUP BY 1
                 ORDER BY 1",
                $trunc,
            ),
            $this->window($tenantId, $from, $to),
        );

        return array_map(static fn (array $r): array => [
            'period'   => (new \DateTimeImmutable((string) $r['period']))->format('Y-m-d'),
            'learners' => (int) $r['learners'],
            'sessions' => (int) $r['sessions'],
        ], $rows);
    }

    public function deviceUsage(string $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT device_type              AS device,
                    COUNT(*)                 AS sessions,
                    COUNT(DISTINCT user_id)  AS learners
             FROM user_sessions
             WHERE tenant_id = :tenant AND started_at >= :from AND started_at < :to
             GROUP BY device_type
             ORDER BY sessions DESC',
            $this->window($tenantId, $from, $to),
        );

        return array_map(static fn (array $r): array => [
            'device'   => (string) $r['device'],
            'sessions' => (int) $r['sessions'],
            'learners' => (int) $r['learners'],
        ], $rows);
    }

    public function loginFrequency(string $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        // From the per-sign-in records. refresh_tokens has no tenant column, so
        // the tenant comes from the user the token belongs to.
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*)                  AS sign_ins,
                    COUNT(DISTINCT rt.user_id) AS learners
             FROM refresh_tokens rt
             JOIN users u ON u.id = rt.user_id
             WHERE u.tenant_id = :tenant
               AND rt.issued_at >= :from AND rt.issued_at < :to',
            $this->window($tenantId, $from, $to),
        );

        $row      = $row === false ? [] : $row;
        $signIns  = (int) ($row['sign_ins'] ?? 0);
        $learners = (int) ($row['learners'] ?? 0);

        return [
            'signIns'          => $signIns,
            'learners'         => $learners,
            'averagePerLearner' => $learners > 0 ? round($signIns / $learners, 2) : 0.0,
        ];
    }

    public function avgActiveDays(string $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        $avg = $this->connection->fetchOne(
            'SELECT COALESCE(AVG(days), 0) FROM (
                 SELECT user_id, COUNT(DISTINCT started_at::date) AS days
                 FROM user_sessions
                 WHERE tenant_id = :tenant AND started_at >= :from AND started_at < :to
                 GROUP BY user_id
             ) s',
            $this->window($tenantId, $from, $to),
        );

        return round((float) $avg, 1);
    }

    public function forUser(
        string $tenantId,
        string $userId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $params = $this->window($tenantId, $from, $to) + ['user' => $userId];

        $totals = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS sessions,
                    COALESCE(AVG(EXTRACT(EPOCH FROM (last_seen_at - started_at)) / 60), 0) AS avg_minutes
             FROM user_sessions
             WHERE tenant_id = :tenant AND user_id = :user AND started_at >= :from AND started_at < :to',
            $params,
        );
        $totals = $totals === false ? [] : $totals;

        $signIns = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM refresh_tokens
             WHERE user_id = :user AND issued_at >= :from AND issued_at < :to',
            ['user' => $userId, 'from' => $from->format(\DateTimeInterface::ATOM), 'to' => $to->format(\DateTimeInterface::ATOM)],
        );

        $devices = $this->connection->fetchAllAssociative(
            'SELECT device_type AS device, COUNT(*) AS sessions
             FROM user_sessions
             WHERE tenant_id = :tenant AND user_id = :user AND started_at >= :from AND started_at < :to
             GROUP BY device_type ORDER BY sessions DESC',
            $params,
        );

        return [
            'sessions'       => (int) ($totals['sessions'] ?? 0),
            'averageMinutes' => round((float) ($totals['avg_minutes'] ?? 0), 1),
            'signIns'        => $signIns,
            'devices'        => array_map(static fn (array $r): array => [
                'device'   => (string) $r['device'],
                'sessions' => (int) $r['sessions'],
            ], $devices),
        ];
    }

    // --- internals ------------------------------------------------------------

    /**
     * @return array{tenant: string, from: string, to: string}
     */
    private function window(string $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return [
            'tenant' => $tenantId,
            'from'   => $from->format(\DateTimeInterface::ATOM),
            'to'     => $to->format(\DateTimeInterface::ATOM),
        ];
    }

    private function truncUnit(string $bucket): string
    {
        return match ($bucket) {
            'week'  => 'week',
            'month' => 'month',
            default => 'day',
        };
    }
}
