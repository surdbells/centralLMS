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

    public function sliceableFields(string $tenantId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT field_key, label
             FROM tenant_custom_fields
             WHERE tenant_id = :tenant AND is_active AND type IN ('text', 'dropdown')
             ORDER BY sort_order, label",
            ['tenant' => $tenantId],
        );

        return array_map(static fn (array $r): array => [
            'key'   => (string) $r['field_key'],
            'label' => (string) $r['label'],
        ], $rows);
    }

    public function completionByCustomField(string $tenantId, string $fieldKey): array
    {
        // The field key is a bound value read out of the JSONB map — never
        // concatenated into SQL — so an arbitrary key is at worst all "—".
        $rows = $this->connection->fetchAllAssociative(
            "SELECT COALESCE(NULLIF(TRIM(u.custom_fields ->> :key), ''), '—') AS value,
                    COUNT(*)                                       AS enrolled,
                    COUNT(*) FILTER (WHERE e.status = 'completed')  AS completed
             FROM enrollments e
             JOIN users u ON u.id = e.user_id
             WHERE e.tenant_id = :tenant AND u.deleted_at IS NULL
             GROUP BY 1
             ORDER BY enrolled DESC
             LIMIT 60",
            ['tenant' => $tenantId, 'key' => $fieldKey],
        );

        return array_map(static function (array $r): array {
            $enrolled  = (int) $r['enrolled'];
            $completed = (int) $r['completed'];

            return [
                'value'     => (string) $r['value'],
                'enrolled'  => $enrolled,
                'completed' => $completed,
                'rate'      => $enrolled > 0 ? round($completed / $enrolled * 100, 1) : 0.0,
            ];
        }, $rows);
    }

    public function assessment(string $tenantId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*)                                                     AS attempts,
                    COUNT(DISTINCT user_id)                                      AS learners,
                    COALESCE(AVG(percentage) FILTER (WHERE percentage IS NOT NULL), 0) AS avg_score,
                    COUNT(*) FILTER (WHERE passed)                               AS passed,
                    COUNT(*) FILTER (WHERE passed IS NOT NULL)                   AS graded
             FROM attempts
             WHERE tenant_id = :tenant',
            ['tenant' => $tenantId],
        );

        $row    = $row === false ? [] : $row;
        $graded = (int) ($row['graded'] ?? 0);

        return [
            'attempts'     => (int) ($row['attempts'] ?? 0),
            'learners'     => (int) ($row['learners'] ?? 0),
            'averageScore' => round((float) ($row['avg_score'] ?? 0), 1),
            'passRate'     => $graded > 0 ? round((int) ($row['passed'] ?? 0) / $graded * 100, 1) : 0.0,
        ];
    }

    public function competencyReadiness(string $tenantId): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT COUNT(*)                          AS items,
                    COUNT(*) FILTER (WHERE i.gap = 0) AS met
             FROM competency_assessment_items i
             JOIN competency_assessments a ON a.id = i.assessment_id
             WHERE a.tenant_id = :tenant AND a.status IN ('reviewed', 'finalized')",
            ['tenant' => $tenantId],
        );

        $row   = $row === false ? [] : $row;
        $items = (int) ($row['items'] ?? 0);
        $met   = (int) ($row['met'] ?? 0);

        return [
            'items' => $items,
            'met'   => $met,
            'rate'  => $items > 0 ? round($met / $items * 100, 1) : 0.0,
        ];
    }

    public function avgCoursesPerEmployee(string $tenantId): float
    {
        $avg = $this->connection->fetchOne(
            "SELECT COALESCE(AVG(cnt), 0) FROM (
                 SELECT u.id, COUNT(e.id) FILTER (WHERE e.status = 'completed') AS cnt
                 FROM users u
                 LEFT JOIN enrollments e ON e.user_id = u.id AND e.tenant_id = u.tenant_id
                 WHERE u.tenant_id = :tenant AND u.deleted_at IS NULL AND u.status = 'active'
                 GROUP BY u.id
             ) s",
            ['tenant' => $tenantId],
        );

        return round((float) $avg, 2);
    }

    public function competencyTrend(string $tenantId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT a.cycle,
                    COUNT(*)                          AS items,
                    COUNT(*) FILTER (WHERE i.gap = 0) AS met,
                    COALESCE(AVG(i.gap), 0)           AS avg_gap
             FROM competency_assessment_items i
             JOIN competency_assessments a ON a.id = i.assessment_id
             WHERE a.tenant_id = :tenant AND a.status IN ('reviewed', 'finalized')
             GROUP BY a.cycle
             ORDER BY a.cycle",
            ['tenant' => $tenantId],
        );

        return array_map(static function (array $r): array {
            $items = (int) $r['items'];
            $met   = (int) $r['met'];

            return [
                'cycle'  => (string) $r['cycle'],
                'items'  => $items,
                'met'    => $met,
                'rate'   => $items > 0 ? round($met / $items * 100, 1) : 0.0,
                'avgGap' => round((float) $r['avg_gap'], 2),
            ];
        }, $rows);
    }

    public function forumParticipation(string $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $params = [
            'tenant' => $tenantId,
            'from'   => $from->format(\DateTimeInterface::ATOM),
            'to'     => $to->format(\DateTimeInterface::ATOM),
        ];

        $row = $this->connection->fetchAssociative(
            "SELECT
                COUNT(*)                                        AS posts,
                COUNT(*) FILTER (WHERE parent_id IS NULL)       AS threads,
                COUNT(*) FILTER (WHERE parent_id IS NOT NULL)   AS replies,
                COUNT(DISTINCT user_id)                         AS participants
             FROM course_discussion_posts
             WHERE tenant_id = :tenant AND deleted_at IS NULL
               AND created_at >= :from AND created_at < :to",
            $params,
        );

        $row = $row === false ? [] : $row;

        $reactions = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM discussion_reactions
             WHERE tenant_id = :tenant AND created_at >= :from AND created_at < :to',
            $params,
        );

        // A thread counts as answered when one of its replies is the accepted
        // answer, judged on threads opened inside the window.
        $answered = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM course_discussion_posts q
             WHERE q.tenant_id = :tenant AND q.parent_id IS NULL AND q.deleted_at IS NULL
               AND q.created_at >= :from AND q.created_at < :to
               AND EXISTS (
                   SELECT 1 FROM course_discussion_posts a
                   WHERE a.parent_id = q.id AND a.is_answer AND a.deleted_at IS NULL
               )",
            $params,
        );

        $threads = (int) ($row['threads'] ?? 0);

        return [
            'posts'           => (int) ($row['posts'] ?? 0),
            'threads'         => $threads,
            'replies'         => (int) ($row['replies'] ?? 0),
            'participants'    => (int) ($row['participants'] ?? 0),
            'reactions'       => $reactions,
            'answeredThreads' => $answered,
            'answerRate'      => $threads > 0 ? round($answered / $threads, 3) : 0.0,
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
