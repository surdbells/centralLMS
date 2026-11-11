<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Analytics\AnalyticsRepository;
use Doctrine\DBAL\Connection;

final class DbalAnalyticsRepository implements AnalyticsRepository
{
    /** A course needs at least this many reviews to be ranked best/worst. */
    private const MIN_REVIEWS_TO_RANK = 3;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function complianceStatus(string $tenantId): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT
                 COUNT(*) FILTER (WHERE status = 'completed')   AS completed,
                 COUNT(*) FILTER (WHERE status = 'in_progress') AS in_progress,
                 COUNT(*) FILTER (WHERE status = 'not_started') AS not_started,
                 COUNT(*)                                       AS total
             FROM enrollments
             WHERE tenant_id = :tenant AND is_mandatory AND status <> 'dropped'",
            ['tenant' => $tenantId],
        );

        $row = $row === false ? [] : $row;

        return [
            'completed'    => (int) ($row['completed'] ?? 0),
            'inProgress'   => (int) ($row['in_progress'] ?? 0),
            'yetToAttempt' => (int) ($row['not_started'] ?? 0),
            'total'        => (int) ($row['total'] ?? 0),
        ];
    }

    public function adoption(string $tenantId, \DateTimeImmutable $since): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT
                 COUNT(*)                                                           AS people,
                 COUNT(*) FILTER (WHERE last_login_at IS NOT NULL)                   AS activated,
                 COUNT(*) FILTER (WHERE last_login_at IS NULL)                       AS never_signed_in,
                 COUNT(*) FILTER (WHERE created_at >= :since)                        AS new_users,
                 COUNT(*) FILTER (WHERE created_at >= :since AND last_login_at IS NOT NULL) AS new_activated
             FROM users
             WHERE tenant_id = :tenant AND deleted_at IS NULL',
            ['tenant' => $tenantId, 'since' => $since->format(\DateTimeInterface::ATOM)],
        );

        $row         = $row === false ? [] : $row;
        $people      = (int) ($row['people'] ?? 0);
        $activated   = (int) ($row['activated'] ?? 0);
        $newUsers    = (int) ($row['new_users'] ?? 0);
        $newActive   = (int) ($row['new_activated'] ?? 0);

        return [
            'people'             => $people,
            'activated'          => $activated,
            'neverSignedIn'      => (int) ($row['never_signed_in'] ?? 0),
            'newUsers'           => $newUsers,
            'newActivated'       => $newActive,
            'adoptionRate'       => $people > 0 ? round($activated / $people * 100, 1) : 0.0,
            'firstTimeLoginRate' => $newUsers > 0 ? round($newActive / $newUsers * 100, 1) : 0.0,
        ];
    }

    public function satisfaction(string $tenantId): array
    {
        $totals = $this->connection->fetchAssociative(
            "SELECT
                 COUNT(*)                            AS count,
                 COALESCE(AVG(rating), 0)            AS average,
                 COUNT(*) FILTER (WHERE rating = 5)  AS s5,
                 COUNT(*) FILTER (WHERE rating = 4)  AS s4,
                 COUNT(*) FILTER (WHERE rating = 3)  AS s3,
                 COUNT(*) FILTER (WHERE rating = 2)  AS s2,
                 COUNT(*) FILTER (WHERE rating = 1)  AS s1
             FROM course_reviews
             WHERE tenant_id = :tenant AND is_published AND deleted_at IS NULL",
            ['tenant' => $tenantId],
        );

        $totals = $totals === false ? [] : $totals;

        return [
            'average' => round((float) ($totals['average'] ?? 0), 2),
            'count'   => (int) ($totals['count'] ?? 0),
            'distribution' => [
                5 => (int) ($totals['s5'] ?? 0),
                4 => (int) ($totals['s4'] ?? 0),
                3 => (int) ($totals['s3'] ?? 0),
                2 => (int) ($totals['s2'] ?? 0),
                1 => (int) ($totals['s1'] ?? 0),
            ],
            'top' => $this->rankedCourses($tenantId, 'DESC'),
            'low' => $this->rankedCourses($tenantId, 'ASC'),
        ];
    }

    /**
     * @param  'ASC'|'DESC' $direction
     * @return list<array<string, mixed>>
     */
    private function rankedCourses(string $tenantId, string $direction): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT id, title, rating_average AS average, rating_count AS reviews
                 FROM courses
                 WHERE tenant_id = :tenant AND rating_count >= :min
                 ORDER BY rating_average %s, rating_count DESC
                 LIMIT 5',
                $direction === 'ASC' ? 'ASC' : 'DESC',
            ),
            ['tenant' => $tenantId, 'min' => self::MIN_REVIEWS_TO_RANK],
        );

        return array_map(static fn (array $r): array => [
            'courseId' => (string) $r['id'],
            'title'    => (string) $r['title'],
            'average'  => round((float) $r['average'], 2),
            'reviews'  => (int) $r['reviews'],
        ], $rows);
    }
}
