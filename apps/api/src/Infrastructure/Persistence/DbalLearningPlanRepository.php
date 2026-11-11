<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\LearningPlan\LearningPlan;
use Central\Api\Domain\LearningPlan\LearningPlanRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

final class DbalLearningPlanRepository implements LearningPlanRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    // --- administration -------------------------------------------------------

    public function list(string $tenantId, ?int $year = null): array
    {
        $where  = 'p.tenant_id = :tenant AND p.deleted_at IS NULL';
        $params = ['tenant' => $tenantId];

        if ($year !== null) {
            $where .= ' AND p.year = :year';
            $params['year'] = $year;
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM learning_plan_items i WHERE i.plan_id = p.id)   AS item_count,
                    (SELECT COUNT(*) FROM learning_plan_members m WHERE m.plan_id = p.id) AS member_count
             FROM learning_plans p
             WHERE $where
             ORDER BY p.year DESC, p.title",
            $params,
        );

        return array_map(fn (array $r): LearningPlan => $this->hydrate($r), $rows);
    }

    public function find(string $tenantId, string $id): ?LearningPlan
    {
        $row = $this->connection->fetchAssociative(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM learning_plan_items i WHERE i.plan_id = p.id)   AS item_count,
                    (SELECT COUNT(*) FROM learning_plan_members m WHERE m.plan_id = p.id) AS member_count
             FROM learning_plans p
             WHERE p.id = :id AND p.tenant_id = :tenant AND p.deleted_at IS NULL",
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function save(LearningPlan $plan): LearningPlan
    {
        $id = $plan->id === '' ? Uuid::uuid4()->toString() : $plan->id;

        $this->connection->executeStatement(
            'INSERT INTO learning_plans (id, tenant_id, year, title, description, status)
             VALUES (:id, :tenant, :year, :title, :description, :status)
             ON CONFLICT (id) DO UPDATE SET
                 year = EXCLUDED.year, title = EXCLUDED.title,
                 description = EXCLUDED.description, status = EXCLUDED.status,
                 updated_at = NOW()',
            [
                'id'          => $id,
                'tenant'      => $plan->tenantId,
                'year'        => $plan->year,
                'title'       => $plan->title,
                'description' => $plan->description,
                'status'      => $plan->status,
            ],
        );

        return $this->find($plan->tenantId, $id) ?? $plan;
    }

    public function setStatus(string $tenantId, string $id, string $status): ?LearningPlan
    {
        if (!in_array($status, LearningPlan::STATUSES, true)) {
            return null;
        }

        $this->connection->executeStatement(
            'UPDATE learning_plans SET status = :status, updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['status' => $status, 'id' => $id, 'tenant' => $tenantId],
        );

        return $this->find($tenantId, $id);
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->connection->executeStatement(
            'UPDATE learning_plans SET deleted_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId],
        ) > 0;
    }

    public function items(string $tenantId, string $planId): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT i.id, i.course_id, i.title, i.description, i.is_mandatory, i.sort_order,
                    c.title AS course_title
             FROM learning_plan_items i
             LEFT JOIN courses c ON c.id = i.course_id
             WHERE i.plan_id = :plan AND i.tenant_id = :tenant
             ORDER BY i.sort_order, i.created_at",
            ['plan' => $planId, 'tenant' => $tenantId],
        );
    }

    public function saveItem(string $tenantId, string $planId, array $input): array
    {
        $id        = is_string($input['id'] ?? null) && $input['id'] !== '' ? (string) $input['id'] : Uuid::uuid4()->toString();
        $courseId  = is_string($input['courseId'] ?? null) && $input['courseId'] !== '' ? (string) $input['courseId'] : null;
        $title     = trim((string) ($input['title'] ?? ''));
        $desc      = is_string($input['description'] ?? null) ? trim((string) $input['description']) : null;
        $mandatory = filter_var($input['isMandatory'] ?? false, FILTER_VALIDATE_BOOL);
        $order     = (int) ($input['sortOrder'] ?? 0);

        $this->connection->executeStatement(
            'INSERT INTO learning_plan_items
                 (id, tenant_id, plan_id, course_id, title, description, is_mandatory, sort_order)
             VALUES (:id, :tenant, :plan, :course, :title, :description, :mandatory, :order)
             ON CONFLICT (id) DO UPDATE SET
                 course_id = EXCLUDED.course_id, title = EXCLUDED.title,
                 description = EXCLUDED.description, is_mandatory = EXCLUDED.is_mandatory,
                 sort_order = EXCLUDED.sort_order',
            [
                'id'        => $id,
                'tenant'    => $tenantId,
                'plan'      => $planId,
                'course'    => $courseId,
                'title'     => $title === '' ? ($courseId !== null ? 'Course' : 'Item') : $title,
                'description' => $desc === '' ? null : $desc,
                'mandatory' => $mandatory ? 'true' : 'false',
                'order'     => $order,
            ],
        );

        $row = $this->connection->fetchAssociative(
            "SELECT i.id, i.course_id, i.title, i.description, i.is_mandatory, i.sort_order,
                    c.title AS course_title
             FROM learning_plan_items i
             LEFT JOIN courses c ON c.id = i.course_id
             WHERE i.id = :id",
            ['id' => $id],
        );

        return $row === false ? [] : $row;
    }

    public function deleteItem(string $tenantId, string $planId, string $itemId): bool
    {
        return $this->connection->executeStatement(
            'DELETE FROM learning_plan_items
             WHERE id = :id AND plan_id = :plan AND tenant_id = :tenant',
            ['id' => $itemId, 'plan' => $planId, 'tenant' => $tenantId],
        ) > 0;
    }

    public function assignMembers(string $tenantId, string $planId, array $userIds): int
    {
        $userIds = array_values(array_unique(array_filter($userIds, static fn ($u): bool => is_string($u) && $u !== '')));

        if ($userIds === []) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            'INSERT INTO learning_plan_members (plan_id, user_id, tenant_id)
             SELECT :plan, u.id, :tenant
             FROM users u
             WHERE u.tenant_id = :tenant AND u.deleted_at IS NULL AND u.id IN (:ids)
             ON CONFLICT (plan_id, user_id) DO NOTHING',
            ['plan' => $planId, 'tenant' => $tenantId, 'ids' => $userIds],
            ['ids' => ArrayParameterType::STRING],
        );
    }

    public function assignDepartment(string $tenantId, string $planId, string $departmentId): int
    {
        return (int) $this->connection->executeStatement(
            "INSERT INTO learning_plan_members (plan_id, user_id, tenant_id)
             SELECT :plan, u.id, :tenant
             FROM users u
             WHERE u.tenant_id = :tenant AND u.deleted_at IS NULL
               AND u.status = 'active' AND u.department_id = :dept
             ON CONFLICT (plan_id, user_id) DO NOTHING",
            ['plan' => $planId, 'tenant' => $tenantId, 'dept' => $departmentId],
        );
    }

    public function assignEveryone(string $tenantId, string $planId): int
    {
        return (int) $this->connection->executeStatement(
            "INSERT INTO learning_plan_members (plan_id, user_id, tenant_id)
             SELECT :plan, u.id, :tenant
             FROM users u
             WHERE u.tenant_id = :tenant AND u.deleted_at IS NULL AND u.status = 'active'
             ON CONFLICT (plan_id, user_id) DO NOTHING",
            ['plan' => $planId, 'tenant' => $tenantId],
        );
    }

    public function members(string $tenantId, string $planId, int $page, int $perPage): array
    {
        $perPage = max(1, min(200, $perPage));
        $offset  = max(0, ($page - 1) * $perPage);

        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM learning_plan_members WHERE plan_id = :plan AND tenant_id = :tenant',
            ['plan' => $planId, 'tenant' => $tenantId],
        );

        $items = $this->connection->fetchAllAssociative(
            "SELECT u.id, TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS name, u.email, m.assigned_at
             FROM learning_plan_members m
             JOIN users u ON u.id = m.user_id
             WHERE m.plan_id = :plan AND m.tenant_id = :tenant
             ORDER BY name
             LIMIT $perPage OFFSET $offset",
            ['plan' => $planId, 'tenant' => $tenantId],
        );

        return ['items' => $items, 'total' => $total];
    }

    public function removeMember(string $tenantId, string $planId, string $userId): bool
    {
        return $this->connection->executeStatement(
            'DELETE FROM learning_plan_members
             WHERE plan_id = :plan AND user_id = :user AND tenant_id = :tenant',
            ['plan' => $planId, 'user' => $userId, 'tenant' => $tenantId],
        ) > 0;
    }

    // --- the learner's own ----------------------------------------------------

    public function forLearner(string $tenantId, string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT p.id AS plan_id, p.year, p.title AS plan_title, p.description AS plan_description,
                    i.id AS item_id, i.course_id, i.title AS item_title, i.description AS item_description,
                    i.is_mandatory, i.sort_order, c.title AS course_title,
                    e.status AS enrollment_status, e.progress_percentage,
                    pr.scheduled_date, pr.status AS progress_status
             FROM learning_plans p
             JOIN learning_plan_members m ON m.plan_id = p.id AND m.user_id = :user
             JOIN learning_plan_items i ON i.plan_id = p.id
             LEFT JOIN courses c ON c.id = i.course_id
             LEFT JOIN enrollments e ON e.course_id = i.course_id AND e.user_id = :user AND e.tenant_id = :tenant
             LEFT JOIN learning_plan_progress pr ON pr.plan_item_id = i.id AND pr.user_id = :user
             WHERE p.tenant_id = :tenant AND p.status = 'published' AND p.deleted_at IS NULL
             ORDER BY p.year DESC, p.title, i.sort_order, i.created_at",
            ['tenant' => $tenantId, 'user' => $userId],
        );

        /** @var array<string, array<string, mixed>> $plans */
        $plans = [];

        foreach ($rows as $r) {
            $planId = (string) $r['plan_id'];

            if (!isset($plans[$planId])) {
                $plans[$planId] = [
                    'id'          => $planId,
                    'year'        => (int) $r['year'],
                    'title'       => (string) $r['plan_title'],
                    'description' => $r['plan_description'],
                    'items'       => [],
                ];
            }

            $status = $this->deriveStatus(
                $r['course_id'] !== null,
                $r['enrollment_status'] === null ? null : (string) $r['enrollment_status'],
                $r['scheduled_date'] !== null,
                $r['progress_status'] === null ? null : (string) $r['progress_status'],
            );

            $plans[$planId]['items'][] = [
                'id'          => (string) $r['item_id'],
                'courseId'    => $r['course_id'],
                'title'       => (string) ($r['course_title'] ?? $r['item_title']),
                'description' => $r['item_description'],
                'isMandatory' => (bool) $r['is_mandatory'],
                'isCourse'    => $r['course_id'] !== null,
                'status'      => $status,
                'scheduledDate' => $r['scheduled_date'],
                'progress'    => $r['progress_percentage'] === null ? null : (int) $r['progress_percentage'],
            ];
        }

        return array_values($plans);
    }

    public function setSchedule(string $tenantId, string $userId, string $itemId, ?string $date): void
    {
        $this->upsertProgress($tenantId, $userId, $itemId, ['scheduled_date' => $date]);
    }

    public function setItemStatus(string $tenantId, string $userId, string $itemId, string $status): void
    {
        $completedAt = $status === 'completed' ? (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM) : null;
        $this->upsertProgress($tenantId, $userId, $itemId, ['status' => $status, 'completed_at' => $completedAt]);
    }

    public function completionForLearner(string $tenantId, string $userId, ?int $year = null): array
    {
        $where  = "p.tenant_id = :tenant AND p.status = 'published' AND p.deleted_at IS NULL";
        $params = ['tenant' => $tenantId, 'user' => $userId];

        if ($year !== null) {
            $where .= ' AND p.year = :year';
            $params['year'] = $year;
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT p.year,
                    COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE
                        (i.course_id IS NOT NULL AND e.status = 'completed')
                     OR (i.course_id IS NULL AND pr.status = 'completed')
                    ) AS completed
             FROM learning_plans p
             JOIN learning_plan_members m ON m.plan_id = p.id AND m.user_id = :user
             JOIN learning_plan_items i ON i.plan_id = p.id
             LEFT JOIN enrollments e ON e.course_id = i.course_id AND e.user_id = :user AND e.tenant_id = :tenant
             LEFT JOIN learning_plan_progress pr ON pr.plan_item_id = i.id AND pr.user_id = :user
             WHERE $where
             GROUP BY p.year
             ORDER BY p.year DESC",
            $params,
        );

        $total     = 0;
        $completed = 0;
        $byYear    = [];

        foreach ($rows as $r) {
            $t = (int) $r['total'];
            $c = (int) $r['completed'];
            $total += $t;
            $completed += $c;
            $byYear[] = [
                'year'      => (int) $r['year'],
                'total'     => $t,
                'completed' => $c,
                'rate'      => $t > 0 ? round($c / $t * 100, 1) : 0.0,
            ];
        }

        return [
            'total'     => $total,
            'completed' => $completed,
            'rate'      => $total > 0 ? round($completed / $total * 100, 1) : 0.0,
            'byYear'    => $byYear,
        ];
    }

    // --- internals ------------------------------------------------------------

    /**
     * The person's status for an item: Not Scheduled / Scheduled / In Progress /
     * Completed. A course takes it from the enrolment; an intervention from the
     * hand-set status; a target date turns "not scheduled" into "scheduled".
     */
    private function deriveStatus(bool $isCourse, ?string $enrollmentStatus, bool $hasDate, ?string $progressStatus): string
    {
        if ($isCourse) {
            if ($enrollmentStatus === 'completed') {
                return 'completed';
            }
            if ($enrollmentStatus === 'in_progress') {
                return 'in_progress';
            }

            return $hasDate ? 'scheduled' : 'not_scheduled';
        }

        return $progressStatus ?? ($hasDate ? 'scheduled' : 'not_scheduled');
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function upsertProgress(string $tenantId, string $userId, string $itemId, array $fields): void
    {
        $this->connection->executeStatement(
            'INSERT INTO learning_plan_progress
                 (id, tenant_id, plan_item_id, user_id, scheduled_date, status, completed_at)
             VALUES (:id, :tenant, :item, :user, :scheduled, :status, :completed)
             ON CONFLICT (plan_item_id, user_id) DO UPDATE SET
                 scheduled_date = COALESCE(:scheduled, learning_plan_progress.scheduled_date),
                 status         = COALESCE(:status, learning_plan_progress.status),
                 completed_at   = CASE WHEN :statusSet THEN :completed ELSE learning_plan_progress.completed_at END,
                 updated_at     = NOW()',
            [
                'id'        => Uuid::uuid4()->toString(),
                'tenant'    => $tenantId,
                'item'      => $itemId,
                'user'      => $userId,
                'scheduled' => $fields['scheduled_date'] ?? null,
                'status'    => $fields['status'] ?? null,
                'completed' => $fields['completed_at'] ?? null,
                'statusSet' => array_key_exists('status', $fields) ? 'true' : 'false',
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): LearningPlan
    {
        $time = static fn (mixed $v): ?\DateTimeImmutable => $v === null ? null : new \DateTimeImmutable((string) $v);

        return new LearningPlan(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            year: (int) $row['year'],
            title: (string) $row['title'],
            description: $row['description'] === null ? null : (string) $row['description'],
            status: (string) $row['status'],
            createdAt: $time($row['created_at'] ?? null),
            updatedAt: $time($row['updated_at'] ?? null),
            itemCount: (int) ($row['item_count'] ?? 0),
            memberCount: (int) ($row['member_count'] ?? 0),
        );
    }
}
