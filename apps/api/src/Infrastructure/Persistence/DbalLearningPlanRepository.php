<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\LearningPlan\LearningPlan;
use Central\Api\Domain\LearningPlan\LearningPlanRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

final class DbalLearningPlanRepository implements LearningPlanRepository
{
    /** The admin item row (snake_case, as the web's AdminPlanItem expects). */
    private const ITEM_COLUMNS = 'i.id, i.course_id, i.title, i.description, i.is_mandatory, i.sort_order,
                    c.title AS course_title, i.user_id, i.category, i.provider, i.schedule_text,
                    i.planned_start, i.planned_end, i.source';

    private const CATEGORIES = ['functional', 'conference', 'certification', 'behavioural', 'organisational', 'leadership', 'other'];

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
                    (SELECT COUNT(*) FROM learning_plan_items i WHERE i.plan_id = p.id AND i.user_id IS NULL)     AS item_count,
                    (SELECT COUNT(*) FROM learning_plan_items i WHERE i.plan_id = p.id AND i.user_id IS NOT NULL) AS personal_item_count,
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
                    (SELECT COUNT(*) FROM learning_plan_items i WHERE i.plan_id = p.id AND i.user_id IS NULL)     AS item_count,
                    (SELECT COUNT(*) FROM learning_plan_items i WHERE i.plan_id = p.id AND i.user_id IS NOT NULL) AS personal_item_count,
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
            'SELECT ' . self::ITEM_COLUMNS . '
             FROM learning_plan_items i
             LEFT JOIN courses c ON c.id = i.course_id
             WHERE i.plan_id = :plan AND i.tenant_id = :tenant AND i.user_id IS NULL
             ORDER BY i.sort_order, i.created_at',
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

        // The per-person fields. A field left out of the input keeps its value
        // on update, so an older client that only sends title/course does not
        // wipe them.
        $extra      = $this->itemExtras($tenantId, $input, Uuid::isValid($id) ? $id : null, $planId);
        $updateSets = '';
        foreach (array_keys($extra) as $column) {
            $updateSets .= ", $column = EXCLUDED.$column";
        }

        $params = [
            'id'        => $id,
            'tenant'    => $tenantId,
            'plan'      => $planId,
            'course'    => $courseId,
            'title'     => $title === '' ? ($courseId !== null ? 'Course' : 'Item') : $title,
            'description' => $desc === '' ? null : $desc,
            'mandatory' => $mandatory ? 'true' : 'false',
            'order'     => $order,
        ] + $extra;

        $this->connection->transactional(function () use ($tenantId, $planId, $extra, $updateSets, $params): void {
            $columns = $extra === [] ? '' : ', ' . implode(', ', array_keys($extra));
            $values  = $extra === [] ? '' : ', :' . implode(', :', array_keys($extra));

            // The WHERE keeps an id from another plan or tenant from being
            // overwritten through this plan.
            $this->connection->executeStatement(
                "INSERT INTO learning_plan_items
                     (id, tenant_id, plan_id, course_id, title, description, is_mandatory, sort_order$columns)
                 VALUES (:id, :tenant, :plan, :course, :title, :description, :mandatory, :order$values)
                 ON CONFLICT (id) DO UPDATE SET
                     course_id = EXCLUDED.course_id, title = EXCLUDED.title,
                     description = EXCLUDED.description, is_mandatory = EXCLUDED.is_mandatory,
                     sort_order = EXCLUDED.sort_order$updateSets, updated_at = NOW()
                 WHERE learning_plan_items.tenant_id = EXCLUDED.tenant_id
                   AND learning_plan_items.plan_id = EXCLUDED.plan_id",
                $params,
            );

            if (is_string($extra['user_id'] ?? null)) {
                $this->assignMembers($tenantId, $planId, [$extra['user_id']]);
            }
        });

        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::ITEM_COLUMNS . '
             FROM learning_plan_items i
             LEFT JOIN courses c ON c.id = i.course_id
             WHERE i.id = :id AND i.tenant_id = :tenant AND i.plan_id = :plan',
            ['id' => $id, 'tenant' => $tenantId, 'plan' => $planId],
        );

        return $row === false ? [] : $row;
    }

    public function personalItems(
        string $tenantId,
        string $planId,
        ?string $userId,
        ?string $category,
        ?string $q,
        int $page,
        int $perPage,
    ): array {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $params = ['plan' => $planId, 'tenant' => $tenantId];

        // Item-level filter shared by the people page, the items and the
        // category counts: the person, and the search (on the person or the item).
        $itemFilter = '';
        if ($userId !== null && $userId !== '') {
            $itemFilter .= ' AND i.user_id = :user';
            $params['user'] = $userId;
        }
        if ($q !== null && trim($q) !== '') {
            $itemFilter .= " AND (CONCAT(u.first_name, ' ', u.last_name) ILIKE :q OR u.email ILIKE :q
                                  OR i.title ILIKE :q OR i.provider ILIKE :q)";
            $params['q'] = '%' . addcslashes(trim($q), '%_\\') . '%';
        }

        $categoryFilter = '';
        $withCategory   = $params;
        if ($category !== null && $category !== '') {
            $categoryFilter = ' AND COALESCE(i.category, \'other\') = :category';
            $withCategory['category'] = $category;
        }

        $from = "FROM learning_plan_items i
                 JOIN users u ON u.id = i.user_id AND u.deleted_at IS NULL
                 WHERE i.plan_id = :plan AND i.tenant_id = :tenant AND i.user_id IS NOT NULL";

        $total = (int) $this->connection->fetchOne(
            "SELECT COUNT(DISTINCT i.user_id) $from $itemFilter $categoryFilter",
            $withCategory,
        );

        $people = $this->connection->fetchAllAssociative(
            "SELECT u.id, TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS name, u.email, d.name AS department
             FROM users u
             LEFT JOIN lookups d ON d.id = u.department_id
             WHERE u.id IN (SELECT DISTINCT i.user_id $from $itemFilter $categoryFilter)
             ORDER BY name, u.email
             LIMIT $perPage OFFSET $offset",
            $withCategory,
        );

        /** @var array<string, array<string, mixed>> $byUser */
        $byUser = [];
        foreach ($people as $p) {
            $byUser[(string) $p['id']] = [
                'userId'     => (string) $p['id'],
                'name'       => (string) $p['name'],
                'email'      => (string) $p['email'],
                'department' => $p['department'] === null ? null : (string) $p['department'],
                'items'      => [],
            ];
        }

        if ($byUser !== []) {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT i.id, i.user_id, i.category, i.title, i.provider, i.schedule_text,
                        i.planned_start, i.planned_end, i.course_id, i.source,
                        COALESCE(pr.scheduled_date, i.planned_start) AS scheduled_date,
                        pr.status AS progress_status, e.status AS enrollment_status
                 FROM learning_plan_items i
                 JOIN users u ON u.id = i.user_id AND u.deleted_at IS NULL
                 LEFT JOIN learning_plan_progress pr ON pr.plan_item_id = i.id AND pr.user_id = i.user_id
                 LEFT JOIN enrollments e ON e.course_id = i.course_id AND e.user_id = i.user_id AND e.tenant_id = i.tenant_id
                 WHERE i.plan_id = :plan AND i.tenant_id = :tenant AND i.user_id IN (:ids)
                       $itemFilter $categoryFilter
                 ORDER BY i.sort_order, i.created_at",
                $withCategory + ['ids' => array_keys($byUser)],
                ['ids' => ArrayParameterType::STRING],
            );

            foreach ($rows as $r) {
                $uid = (string) $r['user_id'];
                if (!isset($byUser[$uid])) {
                    continue;
                }

                $byUser[$uid]['items'][] = [
                    'id'            => (string) $r['id'],
                    'category'      => $r['category'],
                    'title'         => (string) $r['title'],
                    'provider'      => $r['provider'],
                    'scheduleText'  => $r['schedule_text'],
                    'plannedStart'  => $r['planned_start'],
                    'plannedEnd'    => $r['planned_end'],
                    'scheduledDate' => $r['scheduled_date'],
                    'status'        => $this->deriveStatus(
                        $r['course_id'] !== null,
                        $r['enrollment_status'] === null ? null : (string) $r['enrollment_status'],
                        $r['scheduled_date'] !== null,
                        $r['progress_status'] === null ? null : (string) $r['progress_status'],
                    ),
                    'courseId'      => $r['course_id'],
                    'source'        => (string) $r['source'],
                ];
            }
        }

        $categories = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT COALESCE(i.category, 'other') AS category, COUNT(*) AS n $from $itemFilter GROUP BY 1 ORDER BY 1",
            $params,
        ) as $r) {
            $categories[(string) $r['category']] = (int) $r['n'];
        }

        return [
            'people'     => array_values($byUser),
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'categories' => $categories,
        ];
    }

    // --- import ---------------------------------------------------------------

    public function findByYearTitle(string $tenantId, int $year, string $title): ?LearningPlan
    {
        $norm = mb_strtolower(str_replace(["\u{2013}", "\u{2014}"], '-', trim($title)));

        $id = $this->connection->fetchOne(
            "SELECT id FROM learning_plans
             WHERE tenant_id = :tenant AND year = :year AND deleted_at IS NULL
               AND lower(replace(replace(btrim(title), :en, '-'), :em, '-')) = :title
             ORDER BY created_at
             LIMIT 1",
            ['tenant' => $tenantId, 'year' => $year, 'title' => $norm, 'en' => "\u{2013}", 'em' => "\u{2014}"],
        );

        return $id === false || $id === null ? null : $this->find($tenantId, (string) $id);
    }

    public function importedItems(string $tenantId, string $planId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, import_key, user_id, title, category, provider, schedule_text,
                    planned_start, planned_end, sort_order
             FROM learning_plan_items
             WHERE plan_id = :plan AND tenant_id = :tenant AND source = 'import' AND import_key IS NOT NULL",
            ['plan' => $planId, 'tenant' => $tenantId],
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['import_key']] = [
                'id'            => (string) $r['id'],
                'user_id'       => $r['user_id'] === null ? null : (string) $r['user_id'],
                'title'         => (string) $r['title'],
                'category'      => $r['category'] === null ? null : (string) $r['category'],
                'provider'      => $r['provider'] === null ? null : (string) $r['provider'],
                'schedule_text' => $r['schedule_text'] === null ? null : (string) $r['schedule_text'],
                'planned_start' => $r['planned_start'] === null ? null : (string) $r['planned_start'],
                'planned_end'   => $r['planned_end'] === null ? null : (string) $r['planned_end'],
                'sort_order'    => (int) $r['sort_order'],
            ];
        }

        return $out;
    }

    public function upsertImportedItem(string $tenantId, string $planId, array $row): string
    {
        $inserted = $this->connection->fetchOne(
            "INSERT INTO learning_plan_items
                 (id, tenant_id, plan_id, user_id, course_id, title, is_mandatory, sort_order,
                  category, provider, schedule_text, planned_start, planned_end, source, import_key)
             VALUES (:id, :tenant, :plan, :user, NULL, :title, FALSE, :order,
                     :category, :provider, :schedule, :start, :end, 'import', :key)
             ON CONFLICT (plan_id, import_key) WHERE import_key IS NOT NULL DO UPDATE SET
                 user_id       = EXCLUDED.user_id,
                 title         = EXCLUDED.title,
                 category      = EXCLUDED.category,
                 provider      = EXCLUDED.provider,
                 schedule_text = EXCLUDED.schedule_text,
                 planned_start = EXCLUDED.planned_start,
                 planned_end   = EXCLUDED.planned_end,
                 sort_order    = EXCLUDED.sort_order,
                 updated_at    = NOW()
             RETURNING (xmax = 0) AS inserted",
            [
                'id'       => Uuid::uuid4()->toString(),
                'tenant'   => $tenantId,
                'plan'     => $planId,
                'user'     => $row['userId'],
                'title'    => $row['title'],
                'order'    => $row['sortOrder'],
                'category' => $row['category'],
                'provider' => $row['provider'],
                'schedule' => $row['scheduleText'],
                'start'    => $row['plannedStart'],
                'end'      => $row['plannedEnd'],
                'key'      => $row['importKey'],
            ],
        );

        return $inserted === true || $inserted === 't' || $inserted === 1 || $inserted === '1' ? 'created' : 'updated';
    }

    public function deleteItemsByIds(string $tenantId, string $planId, array $ids): int
    {
        $ids = array_values(array_unique(array_filter($ids, static fn ($i): bool => $i !== '')));
        if ($ids === []) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            'DELETE FROM learning_plan_items WHERE plan_id = :plan AND tenant_id = :tenant AND id IN (:ids)',
            ['plan' => $planId, 'tenant' => $tenantId, 'ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );
    }

    public function userEmailIndex(string $tenantId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, lower(btrim(email)) AS email, TRIM(CONCAT(first_name, ' ', last_name)) AS name, status
             FROM users
             WHERE tenant_id = :tenant AND deleted_at IS NULL AND email IS NOT NULL",
            ['tenant' => $tenantId],
        );

        $out = [];
        foreach ($rows as $r) {
            $email = (string) $r['email'];
            if ($email !== '' && !isset($out[$email])) {
                $out[$email] = ['id' => (string) $r['id'], 'name' => (string) $r['name'], 'status' => (string) $r['status']];
            }
        }

        return $out;
    }

    public function transactional(\Closure $work): mixed
    {
        return $this->connection->transactional(static fn (): mixed => $work());
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
        return $this->connection->transactional(function () use ($tenantId, $planId, $userId): bool {
            // Their own items go with them (their progress cascades).
            $this->connection->executeStatement(
                'DELETE FROM learning_plan_items
                 WHERE plan_id = :plan AND tenant_id = :tenant AND user_id = :user',
                ['plan' => $planId, 'user' => $userId, 'tenant' => $tenantId],
            );

            return $this->connection->executeStatement(
                'DELETE FROM learning_plan_members
                 WHERE plan_id = :plan AND user_id = :user AND tenant_id = :tenant',
                ['plan' => $planId, 'user' => $userId, 'tenant' => $tenantId],
            ) > 0;
        });
    }

    // --- the learner's own ----------------------------------------------------

    public function forLearner(string $tenantId, string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT p.id AS plan_id, p.year, p.title AS plan_title, p.description AS plan_description,
                    i.id AS item_id, i.course_id, i.title AS item_title, i.description AS item_description,
                    i.is_mandatory, i.sort_order, c.title AS course_title,
                    e.status AS enrollment_status, e.progress_percentage,
                    COALESCE(pr.scheduled_date, i.planned_start) AS scheduled_date,
                    pr.status AS progress_status,
                    i.user_id AS item_user_id, i.category, i.provider, i.schedule_text,
                    i.planned_start, i.planned_end
             FROM learning_plans p
             JOIN learning_plan_members m ON m.plan_id = p.id AND m.user_id = :user
             JOIN learning_plan_items i ON i.plan_id = p.id AND (i.user_id IS NULL OR i.user_id = :user)
             LEFT JOIN courses c ON c.id = i.course_id
             LEFT JOIN enrollments e ON e.course_id = i.course_id AND e.user_id = :user AND e.tenant_id = :tenant
             LEFT JOIN learning_plan_progress pr ON pr.plan_item_id = i.id AND pr.user_id = :user
             WHERE p.tenant_id = :tenant AND p.status = 'published' AND p.deleted_at IS NULL
             ORDER BY p.year DESC, p.title, (i.user_id IS NOT NULL), i.sort_order, i.created_at",
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
                'category'     => $r['category'],
                'provider'     => $r['provider'],
                'scheduleText' => $r['schedule_text'],
                'plannedStart' => $r['planned_start'],
                'plannedEnd'   => $r['planned_end'],
                'isPersonal'   => $r['item_user_id'] !== null,
            ];
        }

        return array_values($plans);
    }

    public function canTouchItem(string $tenantId, string $userId, string $itemId): bool
    {
        return $this->connection->fetchOne(
            "SELECT 1
             FROM learning_plan_items i
             JOIN learning_plans p ON p.id = i.plan_id
             JOIN learning_plan_members m ON m.plan_id = p.id AND m.user_id = :user
             WHERE i.id = :item AND i.tenant_id = :tenant AND p.tenant_id = :tenant
               AND p.status = 'published' AND p.deleted_at IS NULL
               AND (i.user_id IS NULL OR i.user_id = :user)",
            ['item' => $itemId, 'user' => $userId, 'tenant' => $tenantId],
        ) !== false;
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
             JOIN learning_plan_items i ON i.plan_id = p.id AND (i.user_id IS NULL OR i.user_id = :user)
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
                 scheduled_date = CASE WHEN :scheduledSet THEN :scheduled ELSE learning_plan_progress.scheduled_date END,
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
                // An explicit null date clears it; leaving the key out keeps it.
                'scheduledSet' => array_key_exists('scheduled_date', $fields) ? 'true' : 'false',
            ],
        );
    }

    /**
     * The per-person columns present in a saveItem() input, validated, keyed by
     * column name.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, string|null>
     *
     * @throws ValidationFailed
     */
    private function itemExtras(string $tenantId, array $input, ?string $itemId = null, ?string $planId = null): array
    {
        $extra      = [];
        $violations = [];

        $text = static function (mixed $v, int $max): ?string {
            if (!is_string($v)) {
                return null;
            }
            $v = trim($v);

            return $v === '' ? null : mb_substr($v, 0, $max);
        };

        if (array_key_exists('userId', $input)) {
            $userId = $text($input['userId'], 64);

            if ($userId !== null) {
                $exists = Uuid::isValid($userId) && $this->connection->fetchOne(
                    'SELECT 1 FROM users WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                    ['id' => $userId, 'tenant' => $tenantId],
                ) !== false;

                if (!$exists) {
                    $violations['userId'] = 'That person was not found.';
                }
            }
            $extra['user_id'] = $userId;
        }

        if (array_key_exists('category', $input)) {
            $category = $text($input['category'], 40);
            if ($category !== null && !in_array($category, self::CATEGORIES, true)) {
                $violations['category'] = 'Unknown category.';
            }
            $extra['category'] = $category;
        }

        if (array_key_exists('provider', $input)) {
            $extra['provider'] = $text($input['provider'], 1000);
        }

        if (array_key_exists('scheduleText', $input)) {
            $extra['schedule_text'] = $text($input['scheduleText'], 500);
        }

        foreach (['plannedStart' => 'planned_start', 'plannedEnd' => 'planned_end'] as $key => $column) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $date = $text($input[$key], 10);
            if ($date !== null && (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]))) {
                $violations[$key] = 'Use a YYYY-MM-DD date.';
            }
            $extra[$column] = $date;
        }

        // When only one of the two dates is sent on an update, check it against
        // the stored other one, so the range CHECK constraint is a 422, not a 500.
        $start = $extra['planned_start'] ?? null;
        $end   = $extra['planned_end'] ?? null;
        $hasStart = array_key_exists('planned_start', $extra);
        $hasEnd   = array_key_exists('planned_end', $extra);

        if ($hasStart !== $hasEnd && $itemId !== null && $planId !== null && !isset($violations['plannedStart']) && !isset($violations['plannedEnd'])) {
            $stored = $this->connection->fetchAssociative(
                'SELECT planned_start, planned_end FROM learning_plan_items
                 WHERE id = :id AND tenant_id = :tenant AND plan_id = :plan',
                ['id' => $itemId, 'tenant' => $tenantId, 'plan' => $planId],
            );
            if ($stored !== false) {
                $start = $hasStart ? $start : ($stored['planned_start'] === null ? null : substr((string) $stored['planned_start'], 0, 10));
                $end   = $hasEnd ? $end : ($stored['planned_end'] === null ? null : substr((string) $stored['planned_end'], 0, 10));
            }
        }

        if ($start !== null && $end !== null
            && $end < $start && !isset($violations['plannedEnd']) && !isset($violations['plannedStart'])) {
            $violations['plannedEnd'] = 'The end date is before the start.';
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        return $extra;
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
            personalItemCount: (int) ($row['personal_item_count'] ?? 0),
        );
    }
}
