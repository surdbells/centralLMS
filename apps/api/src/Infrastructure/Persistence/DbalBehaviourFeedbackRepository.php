<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Feedback\BehaviourFeedbackRepository;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

final class DbalBehaviourFeedbackRepository implements BehaviourFeedbackRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function pendingFor(string $tenantId, string $managerId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT u.id AS user_id,
                    TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS name,
                    NULLIF(u.avatar_url, '') AS avatar_url,
                    jt.name AS job_title,
                    d.name AS department,
                    c.id AS course_id, c.title AS course_title,
                    e.completed_at,
                    bf.rating, bf.comments
             FROM users u
             JOIN enrollments e ON e.user_id = u.id AND e.tenant_id = u.tenant_id AND e.status = 'completed'
             JOIN courses c ON c.id = e.course_id
             LEFT JOIN lookups jt ON jt.id = u.job_title_id
             LEFT JOIN lookups d  ON d.id  = u.department_id
             LEFT JOIN behaviour_feedback bf
                    ON bf.user_id = u.id AND bf.course_id = c.id AND bf.manager_id = :manager
             WHERE u.tenant_id = :tenant AND u.manager_id = :manager AND u.deleted_at IS NULL
             ORDER BY name, e.completed_at DESC NULLS LAST",
            ['tenant' => $tenantId, 'manager' => $managerId],
        );

        // Postgres returns TIMESTAMPTZ as "2026-11-01 10:22:33.123456+00", which
        // some browsers (Safari) cannot parse; hand the client ISO 8601 instead.
        return array_map(static function (array $r): array {
            if (isset($r['completed_at']) && is_string($r['completed_at']) && $r['completed_at'] !== '') {
                $r['completed_at'] = (new \DateTimeImmutable($r['completed_at']))->format(DATE_ATOM);
            }
            return $r;
        }, $rows);
    }

    public function isReport(string $tenantId, string $managerId, string $userId): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM users
             WHERE id = :user AND manager_id = :manager AND tenant_id = :tenant AND deleted_at IS NULL',
            ['user' => $userId, 'manager' => $managerId, 'tenant' => $tenantId],
        ) !== false;
    }

    public function saveForCourse(
        string $tenantId,
        string $managerId,
        string $userId,
        string $courseId,
        int $rating,
        ?string $comments,
    ): void {
        $this->connection->executeStatement(
            'INSERT INTO behaviour_feedback
                 (id, tenant_id, user_id, manager_id, course_id, rating, comments)
             VALUES (:id, :tenant, :user, :manager, :course, :rating, :comments)
             ON CONFLICT (manager_id, user_id, course_id) WHERE course_id IS NOT NULL
             DO UPDATE SET rating = EXCLUDED.rating, comments = EXCLUDED.comments, updated_at = NOW()',
            [
                'id'       => Uuid::uuid4()->toString(),
                'tenant'   => $tenantId,
                'user'     => $userId,
                'manager'  => $managerId,
                'course'   => $courseId,
                'rating'   => $rating,
                'comments' => $comments,
            ],
        );
    }

    public function summary(string $tenantId): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT COUNT(*)                           AS count,
                    COALESCE(AVG(rating), 0)           AS average,
                    COUNT(*) FILTER (WHERE rating = 5) AS s5,
                    COUNT(*) FILTER (WHERE rating = 4) AS s4,
                    COUNT(*) FILTER (WHERE rating = 3) AS s3,
                    COUNT(*) FILTER (WHERE rating = 2) AS s2,
                    COUNT(*) FILTER (WHERE rating = 1) AS s1
             FROM behaviour_feedback
             WHERE tenant_id = :tenant",
            ['tenant' => $tenantId],
        );

        $row = $row === false ? [] : $row;

        return [
            'average' => round((float) ($row['average'] ?? 0), 2),
            'count'   => (int) ($row['count'] ?? 0),
            'distribution' => [
                5 => (int) ($row['s5'] ?? 0),
                4 => (int) ($row['s4'] ?? 0),
                3 => (int) ($row['s3'] ?? 0),
                2 => (int) ($row['s2'] ?? 0),
                1 => (int) ($row['s1'] ?? 0),
            ],
        ];
    }
}
