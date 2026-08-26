<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Enrollment\AssignmentRequest;
use Central\Api\Domain\Enrollment\Enrollment;
use Central\Api\Domain\Enrollment\EnrollmentRepository;
use Central\Api\Domain\Enrollment\EnrollmentSource;
use Central\Api\Domain\Enrollment\EnrollmentStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class DbalEnrollmentRepository implements EnrollmentRepository
{
    private const COLUMNS = 'e.id, e.tenant_id, e.course_id, e.user_id, e.status, e.source,
                             e.assigned_by, e.is_mandatory, e.due_date, e.progress_percentage,
                             e.final_score, e.time_spent_seconds, e.last_lesson_id,
                             e.started_at, e.completed_at, e.last_activity_at, e.enrolled_at';

    /**
     * Joined into every read. A list of enrolments is always rendered as
     * people or as courses, never as ids, and fetching them separately is the
     * N+1 the legacy screens had.
     */
    private const JOINS = "
        LEFT JOIN courses c ON c.id = e.course_id
        LEFT JOIN users   u ON u.id = e.user_id
    ";

    private const EXTRA = "c.title AS course_title,
                           NULLIF(btrim(concat_ws(' ', u.first_name, u.last_name)), '') AS user_name,
                           u.email AS user_email";

    public function __construct(private readonly Connection $connection)
    {
    }

    public function forCourse(
        string $tenantId,
        string $courseId,
        int $page,
        int $perPage,
        ?EnrollmentStatus $status = null,
        ?string $search = null,
        bool $overdueOnly = false,
    ): array {
        $where = 'e.tenant_id = :tenant AND e.course_id = :course AND e.deleted_at IS NULL';
        $params = ['tenant' => $tenantId, 'course' => $courseId];

        if ($status !== null) {
            $where .= ' AND e.status = :status';
            $params['status'] = $status->value;
        }

        if ($search !== null && trim($search) !== '') {
            $where .= " AND (u.email ILIKE :search
                          OR concat_ws(' ', u.first_name, u.last_name) ILIKE :search)";
            $params['search'] = '%' . trim($search) . '%';
        }

        if ($overdueOnly) {
            $where .= " AND e.due_date IS NOT NULL
                        AND e.due_date < CURRENT_DATE
                        AND e.status IN ('not_started', 'in_progress')";
        }

        return $this->page($where, $params, $page, $perPage, 'u.first_name ASC, u.email ASC');
    }

    public function forUser(
        string $tenantId,
        string $userId,
        int $page,
        int $perPage,
        ?EnrollmentStatus $status = null,
    ): array {
        $where = 'e.tenant_id = :tenant AND e.user_id = :user AND e.deleted_at IS NULL';
        $params = ['tenant' => $tenantId, 'user' => $userId];

        if ($status !== null) {
            $where .= ' AND e.status = :status';
            $params['status'] = $status->value;
        } else {
            // A learner taken off a course should not still see it among
            // "my courses". The row survives so their progress does, but it
            // is not theirs to open any more; asking for it explicitly still
            // works, for an administrator looking at what happened.
            $where .= " AND e.status <> 'dropped'";
        }

        // Overdue work first, then whatever they touched most recently: the
        // learner's home screen should open on what is late.
        return $this->page(
            $where,
            $params,
            $page,
            $perPage,
            "(e.due_date IS NOT NULL AND e.due_date < CURRENT_DATE
              AND e.status IN ('not_started','in_progress')) DESC,
             e.due_date ASC NULLS LAST,
             e.last_activity_at DESC NULLS LAST",
        );
    }

    public function find(string $tenantId, string $id): ?Enrollment
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, %s FROM enrollments e %s
                 WHERE e.id = :id AND e.tenant_id = :tenant AND e.deleted_at IS NULL',
                self::COLUMNS,
                self::EXTRA,
                self::JOINS,
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function findForUserAndCourse(string $tenantId, string $userId, string $courseId): ?Enrollment
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, %s FROM enrollments e %s
                 WHERE e.user_id = :user AND e.course_id = :course
                   AND e.tenant_id = :tenant AND e.deleted_at IS NULL',
                self::COLUMNS,
                self::EXTRA,
                self::JOINS,
            ),
            ['user' => $userId, 'course' => $courseId, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function assign(
        string $tenantId,
        string $courseId,
        AssignmentRequest $request,
        ?string $actorId,
    ): array {
        /** @var array{enrolled: list<string>, reinstated: list<string>, alreadyEnrolled: list<string>, notFound: list<string>} $result */
        $result = $this->connection->transactional(
            function (Connection $tx) use ($tenantId, $courseId, $request, $actorId): array {
                $requested = $request->userIds;
                $targets = $requested !== []
                    ? $this->existingUsers($tx, $tenantId, $requested)
                    : $this->usersInGroup($tx, $tenantId, $request);

                $notFound = array_values(array_diff($requested, $targets));

                if ($targets === []) {
                    return [
                        'enrolled'        => [],
                        'reinstated'      => [],
                        'alreadyEnrolled' => [],
                        'notFound'        => $notFound,
                    ];
                }

                // Who is already on it, and in what state. One query, not one
                // per learner as the legacy isAlreadyEnrolled() did inside its
                // loop.
                /** @var array<string, string> $existing user id => status */
                $existing = $tx->fetchAllKeyValue(
                    'SELECT user_id, status FROM enrollments
                     WHERE course_id = :course AND tenant_id = :tenant
                       AND deleted_at IS NULL AND user_id IN (:users)',
                    ['course' => $courseId, 'tenant' => $tenantId, 'users' => $targets],
                    ['users' => ArrayParameterType::STRING],
                );

                $toInsert = [];
                $toReinstate = [];
                $alreadyEnrolled = [];

                foreach ($targets as $userId) {
                    if (!array_key_exists($userId, $existing)) {
                        $toInsert[] = $userId;
                    } elseif ($existing[$userId] === EnrollmentStatus::Dropped->value) {
                        // Reinstated, not re-enrolled: their progress is still
                        // on the row and starting them at zero would be wrong.
                        $toReinstate[] = $userId;
                    } else {
                        $alreadyEnrolled[] = $userId;
                    }
                }

                $dueDate = $request->dueDate?->format('Y-m-d');

                if ($toInsert !== []) {
                    $tx->executeStatement(
                        'INSERT INTO enrollments
                             (tenant_id, course_id, user_id, source, assigned_by, is_mandatory, due_date)
                         SELECT :tenant, :course, u, :source, :actor, :mandatory, :due::date
                         FROM unnest(:users::uuid[]) AS u',
                        [
                            'tenant'    => $tenantId,
                            'course'    => $courseId,
                            'source'    => $request->source->value,
                            'actor'     => $actorId,
                            'mandatory' => $request->isMandatory ? 'true' : 'false',
                            'due'       => $dueDate,
                            'users'     => '{' . implode(',', $toInsert) . '}',
                        ],
                    );
                }

                if ($toReinstate !== []) {
                    $tx->executeStatement(
                        "UPDATE enrollments
                         SET status = CASE
                                 -- Back to where their progress says they are,
                                 -- not to the beginning.
                                 WHEN progress_percentage >= 100 THEN 'completed'
                                 WHEN progress_percentage > 0 THEN 'in_progress'
                                 ELSE 'not_started'
                             END,
                             completed_at = CASE
                                 WHEN progress_percentage >= 100 THEN COALESCE(completed_at, NOW())
                                 ELSE NULL
                             END,
                             source = :source,
                             assigned_by = :actor,
                             is_mandatory = :mandatory,
                             due_date = :due::date,
                             updated_at = NOW()
                         WHERE course_id = :course AND tenant_id = :tenant AND user_id IN (:users)",
                        [
                            'source'    => $request->source->value,
                            'actor'     => $actorId,
                            'mandatory' => $request->isMandatory ? 'true' : 'false',
                            'due'       => $dueDate,
                            'course'    => $courseId,
                            'tenant'    => $tenantId,
                            'users'     => $toReinstate,
                        ],
                        ['users' => ArrayParameterType::STRING],
                    );
                }

                $this->log($tx, $tenantId, $courseId, $toInsert, 'enrolled', $request->source, $actorId);
                $this->log($tx, $tenantId, $courseId, $toReinstate, 'reinstated', $request->source, $actorId);

                return [
                    'enrolled'        => $toInsert,
                    'reinstated'      => $toReinstate,
                    'alreadyEnrolled' => $alreadyEnrolled,
                    'notFound'        => $notFound,
                ];
            },
        );

        return $result;
    }

    public function unenroll(
        string $tenantId,
        string $courseId,
        array $userIds,
        ?string $actorId,
        ?string $reason = null,
    ): array {
        if ($userIds === []) {
            return [];
        }

        /** @var list<string> $removed */
        $removed = $this->connection->transactional(
            function (Connection $tx) use ($tenantId, $courseId, $userIds, $actorId, $reason): array {
                /** @var list<string> $affected */
                $affected = $tx->fetchFirstColumn(
                    "UPDATE enrollments
                     SET status = 'dropped',
                         -- Cleared because the check constraint ties it to the
                         -- status, and a dropped learner has not completed.
                         completed_at = NULL,
                         updated_at = NOW()
                     WHERE course_id = :course AND tenant_id = :tenant
                       AND deleted_at IS NULL AND status <> 'dropped'
                       AND user_id IN (:users)
                     RETURNING user_id",
                    ['course' => $courseId, 'tenant' => $tenantId, 'users' => $userIds],
                    ['users' => ArrayParameterType::STRING],
                );

                $this->log($tx, $tenantId, $courseId, $affected, 'unenrolled', null, $actorId, $reason);

                return $affected;
            },
        );

        return $removed;
    }

    public function statsForCourse(string $tenantId, string $courseId): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT
                COUNT(*)                                                    AS total,
                COUNT(*) FILTER (WHERE status = 'not_started')              AS not_started,
                COUNT(*) FILTER (WHERE status = 'in_progress')              AS in_progress,
                COUNT(*) FILTER (WHERE status = 'completed')                AS completed,
                COUNT(*) FILTER (WHERE status = 'dropped')                  AS dropped,
                COUNT(*) FILTER (
                    WHERE due_date IS NOT NULL AND due_date < CURRENT_DATE
                      AND status IN ('not_started', 'in_progress')
                )                                                           AS overdue,
                -- Dropped learners are excluded from the average: leaving
                -- them in makes a course look worse the more people are taken
                -- off it.
                COALESCE(AVG(progress_percentage) FILTER (WHERE status <> 'dropped'), 0) AS average_progress
             FROM enrollments
             WHERE course_id = :course AND tenant_id = :tenant AND deleted_at IS NULL",
            ['course' => $courseId, 'tenant' => $tenantId],
        );

        if ($row === false) {
            $row = [];
        }

        return [
            'total'           => (int) ($row['total'] ?? 0),
            'notStarted'      => (int) ($row['not_started'] ?? 0),
            'inProgress'      => (int) ($row['in_progress'] ?? 0),
            'completed'       => (int) ($row['completed'] ?? 0),
            'dropped'         => (int) ($row['dropped'] ?? 0),
            'overdue'         => (int) ($row['overdue'] ?? 0),
            'averageProgress' => round((float) ($row['average_progress'] ?? 0), 2),
        ];
    }

    public function history(string $tenantId, string $courseId, string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT ev.action, ev.source, ev.notes, ev.created_at,
                    NULLIF(btrim(concat_ws(' ', a.first_name, a.last_name)), '') AS actor_name
             FROM enrollment_events ev
             LEFT JOIN users a ON a.id = ev.actor_id
             WHERE ev.tenant_id = :tenant AND ev.course_id = :course AND ev.user_id = :user
             ORDER BY ev.created_at DESC",
            ['tenant' => $tenantId, 'course' => $courseId, 'user' => $userId],
        );

        return array_map(static fn (array $r): array => [
            'action'    => (string) $r['action'],
            'source'    => $r['source'] !== null ? (string) $r['source'] : null,
            'notes'     => $r['notes'] !== null ? (string) $r['notes'] : null,
            'actorName' => $r['actor_name'] !== null ? (string) $r['actor_name'] : null,
            'at'        => (string) $r['created_at'],
        ], $rows);
    }

    // --- internals --------------------------------------------------------

    /**
     * The subset of the requested ids that are real users in this tenant.
     *
     * Filtering here rather than letting the foreign key reject the batch: one
     * stale id from a client should not lose the other 199 assignments.
     *
     * @param list<string> $userIds
     *
     * @return list<string>
     */
    private function existingUsers(Connection $tx, string $tenantId, array $userIds): array
    {
        /** @var list<string> $found */
        $found = $tx->fetchFirstColumn(
            'SELECT id FROM users
             WHERE tenant_id = :tenant AND deleted_at IS NULL AND id IN (:ids)',
            ['tenant' => $tenantId, 'ids' => $userIds],
            ['ids' => ArrayParameterType::STRING],
        );

        return $found;
    }

    /**
     * Everyone in the named department, team or branch.
     *
     * @return list<string>
     */
    private function usersInGroup(Connection $tx, string $tenantId, AssignmentRequest $request): array
    {
        $kind = $request->source->groupKind();

        if ($kind === null || $request->groupId === null) {
            return [];
        }

        // The column name is chosen from a fixed set, never interpolated from
        // input: `groupKind()` returns one of three literals.
        $column = match ($kind) {
            'department' => 'department_id',
            'team'       => 'team_id',
            default      => 'branch_id',
        };

        /** @var list<string> $found */
        $found = $tx->fetchFirstColumn(
            sprintf(
                "SELECT id FROM users
                 WHERE tenant_id = :tenant AND deleted_at IS NULL
                   AND status <> 'suspended' AND %s = :group",
                $column,
            ),
            ['tenant' => $tenantId, 'group' => $request->groupId],
        );

        return $found;
    }

    /**
     * @param list<string> $userIds
     */
    private function log(
        Connection $tx,
        string $tenantId,
        string $courseId,
        array $userIds,
        string $action,
        ?EnrollmentSource $source,
        ?string $actorId,
        ?string $notes = null,
    ): void {
        if ($userIds === []) {
            return;
        }

        $tx->executeStatement(
            'INSERT INTO enrollment_events (tenant_id, course_id, user_id, action, source, actor_id, notes)
             SELECT :tenant, :course, u, :action, :source, :actor, :notes
             FROM unnest(:users::uuid[]) AS u',
            [
                'tenant' => $tenantId,
                'course' => $courseId,
                'action' => $action,
                'source' => $source?->value,
                'actor'  => $actorId,
                'notes'  => $notes,
                'users'  => '{' . implode(',', $userIds) . '}',
            ],
        );
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{items: list<Enrollment>, total: int}
     */
    private function page(string $where, array $params, int $page, int $perPage, string $orderBy): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM enrollments e %s WHERE %s', self::JOINS, $where),
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s, %s FROM enrollments e %s WHERE %s ORDER BY %s LIMIT :limit OFFSET :offset',
                self::COLUMNS,
                self::EXTRA,
                self::JOINS,
                $where,
                $orderBy,
            ),
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return [
            'items' => array_map(fn (array $row): Enrollment => $this->hydrate($row), $rows),
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Enrollment
    {
        $status = EnrollmentStatus::tryFrom((string) $row['status']);

        if ($status === null) {
            // The check constraint makes this unreachable, and an unknown
            // status must not silently read as "not started".
            throw new \RuntimeException(sprintf('Unknown enrolment status "%s".', $row['status']));
        }

        return new Enrollment(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            courseId: (string) $row['course_id'],
            userId: (string) $row['user_id'],
            status: $status,
            source: EnrollmentSource::fromLegacy((string) $row['source']),
            assignedBy: $this->text($row['assigned_by'] ?? null),
            isMandatory: (bool) $row['is_mandatory'],
            dueDate: $this->date($row['due_date'] ?? null),
            progressPercentage: (float) $row['progress_percentage'],
            finalScore: isset($row['final_score']) ? (float) $row['final_score'] : null,
            timeSpentSeconds: (int) $row['time_spent_seconds'],
            lastLessonId: $this->text($row['last_lesson_id'] ?? null),
            startedAt: $this->date($row['started_at'] ?? null),
            completedAt: $this->date($row['completed_at'] ?? null),
            lastActivityAt: $this->date($row['last_activity_at'] ?? null),
            enrolledAt: $this->date($row['enrolled_at'] ?? null),
            courseTitle: $this->text($row['course_title'] ?? null),
            userName: $this->text($row['user_name'] ?? null),
            userEmail: $this->text($row['user_email'] ?? null),
        );
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
