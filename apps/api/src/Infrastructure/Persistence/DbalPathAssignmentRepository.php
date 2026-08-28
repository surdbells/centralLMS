<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Audience\AudienceResolver;
use Central\Api\Domain\Audience\AudienceTarget;
use Central\Api\Domain\LearningPath\AssignmentPriority;
use Central\Api\Domain\LearningPath\AssignmentStatus;
use Central\Api\Domain\LearningPath\LearningPathRepository;
use Central\Api\Domain\LearningPath\PathAssignment;
use Central\Api\Domain\LearningPath\PathAssignmentRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class DbalPathAssignmentRepository implements PathAssignmentRepository
{
    private const COLUMNS = "a.id, a.tenant_id, a.path_id, a.target_type, a.target_criteria,
                             a.due_date, a.due_days_from_assignment, a.priority, a.is_mandatory,
                             a.send_notification, a.send_reminder, a.reminder_days_before,
                             a.notes, a.status, a.assigned_by, a.assigned_at, a.last_applied_at,
                             a.created_at, a.updated_at,
                             p.title AS path_title,
                             TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS assigned_by_name";

    /**
     * What became of the people this rule enrolled, counted at read time.
     *
     * A rule's figures change every time somebody finishes an item; a stored
     * count is wrong from the first of those and never right again.
     */
    private const COUNTS = "(
                                SELECT COUNT(*) FROM learning_path_enrollments e
                                WHERE e.assignment_id = a.id AND e.deleted_at IS NULL
                            ) AS enrolled_count,
                            (
                                SELECT COUNT(*) FROM learning_path_enrollments e
                                WHERE e.assignment_id = a.id AND e.deleted_at IS NULL
                                  AND e.status = 'completed'
                            ) AS completed_count,
                            (
                                SELECT COUNT(*) FROM learning_path_enrollments e
                                WHERE e.assignment_id = a.id AND e.deleted_at IS NULL
                                  AND e.status <> 'completed'
                                  AND e.due_date IS NOT NULL AND e.due_date < CURRENT_DATE
                            ) AS overdue_count";

    private const JOINS = 'LEFT JOIN learning_paths p ON p.id = a.path_id
                           LEFT JOIN users u ON u.id = a.assigned_by';

    public function __construct(
        private readonly Connection $connection,
        private readonly AudienceResolver $audience,
        private readonly LearningPathRepository $paths,
    ) {
    }

    public function forPath(string $tenantId, string $pathId): array
    {
        return $this->all(
            'a.tenant_id = :tenant AND a.path_id = :path AND a.deleted_at IS NULL',
            ['tenant' => $tenantId, 'path' => $pathId],
        );
    }

    public function running(?string $tenantId = null): array
    {
        if ($tenantId === null) {
            return $this->all("a.status = 'active' AND a.deleted_at IS NULL", []);
        }

        return $this->all(
            "a.tenant_id = :tenant AND a.status = 'active' AND a.deleted_at IS NULL",
            ['tenant' => $tenantId],
        );
    }

    public function find(string $tenantId, string $id): ?PathAssignment
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, %s FROM learning_path_assignments a %s
                 WHERE a.id = :id AND a.tenant_id = :tenant AND a.deleted_at IS NULL',
                self::COLUMNS,
                self::COUNTS,
                self::JOINS,
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function save(PathAssignment $assignment): PathAssignment
    {
        $this->connection->executeStatement(
            'INSERT INTO learning_path_assignments
                 (id, tenant_id, path_id, target_type, target_criteria, due_date,
                  due_days_from_assignment, priority, is_mandatory, send_notification,
                  send_reminder, reminder_days_before, notes, status, assigned_by)
             VALUES (:id, :tenant, :path, :target, :criteria::jsonb, :dueDate::date,
                     :offset, :priority, :mandatory, :notify,
                     :remind, :remindDays, :notes, :status, :by)
             ON CONFLICT (id) DO UPDATE SET
                 target_type     = EXCLUDED.target_type,
                 target_criteria = EXCLUDED.target_criteria,
                 due_date                 = EXCLUDED.due_date,
                 due_days_from_assignment = EXCLUDED.due_days_from_assignment,
                 priority     = EXCLUDED.priority,
                 is_mandatory = EXCLUDED.is_mandatory,
                 send_notification    = EXCLUDED.send_notification,
                 send_reminder        = EXCLUDED.send_reminder,
                 reminder_days_before = EXCLUDED.reminder_days_before,
                 notes  = EXCLUDED.notes,
                 status = EXCLUDED.status,
                 updated_at = NOW()',
            [
                'id'        => $assignment->id,
                'tenant'    => $assignment->tenantId,
                'path'      => $assignment->pathId,
                'target'    => $assignment->targetType->value,
                'criteria'  => json_encode($assignment->targetCriteria, JSON_THROW_ON_ERROR),
                'dueDate'   => $assignment->dueDate,
                'offset'    => $assignment->dueDaysFromAssignment,
                'priority'  => $assignment->priority->value,
                'mandatory' => $assignment->isMandatory,
                'notify'    => $assignment->sendNotification,
                'remind'    => $assignment->sendReminder,
                'remindDays' => $assignment->reminderDaysBefore,
                'notes'     => $assignment->notes,
                'status'    => $assignment->status->value,
                'by'        => $assignment->assignedBy,
            ],
            [
                'mandatory' => ParameterType::BOOLEAN,
                'notify'    => ParameterType::BOOLEAN,
                'remind'    => ParameterType::BOOLEAN,
            ],
        );

        return $this->find($assignment->tenantId, $assignment->id) ?? $assignment;
    }

    public function setStatus(string $tenantId, string $id, AssignmentStatus $status): ?PathAssignment
    {
        // Nothing here touches the enrolments the rule already made. Taking a
        // path away from somebody halfway through it is a different decision,
        // and not one anybody made by pausing a rule.
        $affected = $this->connection->executeStatement(
            'UPDATE learning_path_assignments
             SET status = :status, updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId, 'status' => $status->value],
        );

        return $affected > 0 ? $this->find($tenantId, $id) : null;
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->connection->executeStatement(
            'UPDATE learning_path_assignments SET deleted_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId],
        ) > 0;
    }

    // --- running a rule -------------------------------------------------------

    public function apply(string $tenantId, string $id): int
    {
        $rule = $this->find($tenantId, $id);

        if ($rule === null || !$rule->status->isRunning()) {
            return 0;
        }

        $userIds = $this->audience->resolve($tenantId, $rule->targetType, $rule->targetCriteria);

        // Stamped whether or not anybody matched: "the sweep has looked and
        // found nobody new" and "the sweep has never run" are different
        // states, and the screen shows which.
        $this->stampApplied($tenantId, $id);

        if ($userIds === []) {
            return 0;
        }

        $due = $rule->dueDateFor(new \DateTimeImmutable());

        $result = $this->paths->enrol(
            $tenantId,
            $rule->pathId,
            $userIds,
            'assignment',
            $rule->assignedBy,
            $due === null ? null : new \DateTimeImmutable($due),
            $rule->isMandatory,
            $rule->id,
        );

        return $result['enrolled'];
    }

    public function preview(string $tenantId, string $id): array
    {
        $rule = $this->find($tenantId, $id);

        if ($rule === null) {
            return ['covered' => 0, 'alreadyEnrolled' => 0, 'wouldEnrol' => 0];
        }

        $userIds = $this->audience->resolve($tenantId, $rule->targetType, $rule->targetCriteria);

        if ($userIds === []) {
            return ['covered' => 0, 'alreadyEnrolled' => 0, 'wouldEnrol' => 0];
        }

        $already = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM learning_path_enrollments
             WHERE tenant_id = :tenant AND path_id = :path
               AND user_id IN (:ids) AND deleted_at IS NULL',
            ['tenant' => $tenantId, 'path' => $rule->pathId, 'ids' => $userIds],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        return [
            'covered'         => count($userIds),
            'alreadyEnrolled' => $already,
            'wouldEnrol'      => count($userIds) - $already,
        ];
    }

    // --- what the rule has done -----------------------------------------------

    public function statistics(string $tenantId, string $id): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT COUNT(*) AS enrolled,
                    COUNT(*) FILTER (WHERE e.status = 'completed') AS completed,
                    COUNT(*) FILTER (WHERE e.status = 'active' AND e.started_at IS NOT NULL) AS in_progress,
                    COUNT(*) FILTER (WHERE e.started_at IS NULL AND e.status <> 'completed') AS not_started,
                    COUNT(*) FILTER (
                        WHERE e.status <> 'completed'
                          AND e.due_date IS NOT NULL AND e.due_date < CURRENT_DATE
                    ) AS overdue,
                    -- How long the people who finished took. Only they have
                    -- an answer; averaging in the ones still going would make
                    -- a rule look faster the longer people take.
                    AVG(
                        EXTRACT(EPOCH FROM (e.completed_at - e.enrolled_at)) / 86400
                    ) FILTER (WHERE e.completed_at IS NOT NULL) AS avg_days,
                    -- Progress is not stored on an enrolment; it is how many
                    -- of the path's items the person has finished.
                    AVG(
                        CASE
                            WHEN items.total = 0 THEN NULL
                            ELSE (
                                SELECT COUNT(*) FROM learning_path_item_completions c
                                WHERE c.enrollment_id = e.id
                            )::numeric * 100 / items.total
                        END
                    ) AS avg_progress
             FROM learning_path_enrollments e
             CROSS JOIN LATERAL (
                 SELECT COUNT(*) AS total FROM learning_path_items i
                 WHERE i.path_id = e.path_id AND i.deleted_at IS NULL
             ) AS items
             WHERE e.assignment_id = :id AND e.tenant_id = :tenant AND e.deleted_at IS NULL",
            ['id' => $id, 'tenant' => $tenantId],
        );

        $row = $row === false ? [] : $row;
        $enrolled = (int) ($row['enrolled'] ?? 0);
        $completed = (int) ($row['completed'] ?? 0);

        return [
            'enrolled'    => $enrolled,
            'completed'   => $completed,
            'inProgress'  => (int) ($row['in_progress'] ?? 0),
            'notStarted'  => (int) ($row['not_started'] ?? 0),
            'overdue'     => (int) ($row['overdue'] ?? 0),
            // Null rather than zero when the rule has enrolled nobody: no
            // completion rate is not a completion rate of nought, and neither
            // is "nobody has finished yet" an average of nought days.
            'completionRate' => $enrolled === 0
                ? null
                : round(($completed / $enrolled) * 100, 1),
            'averageProgress' => $row['avg_progress'] === null
                ? null
                : round((float) $row['avg_progress'], 1),
            'averageDaysToComplete' => $row['avg_days'] === null
                ? null
                : round((float) $row['avg_days'], 1),
        ];
    }

    public function pendingLearners(string $tenantId, string $id): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT e.id AS enrollment_id, e.user_id, e.due_date, e.started_at,
                    TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS name, u.email
             FROM learning_path_enrollments e
             JOIN users u ON u.id = e.user_id
             WHERE e.assignment_id = :id AND e.tenant_id = :tenant
               AND e.deleted_at IS NULL AND e.status <> 'completed'
               AND u.status = 'active' AND u.deleted_at IS NULL
             ORDER BY e.due_date NULLS LAST, u.first_name",
            ['id' => $id, 'tenant' => $tenantId],
        );

        return array_map(static fn (array $row): array => [
            'enrollmentId' => (string) $row['enrollment_id'],
            'userId'    => (string) $row['user_id'],
            'name'      => trim((string) $row['name']),
            'email'     => (string) $row['email'],
            'dueDate'   => $row['due_date'] === null ? null : (string) $row['due_date'],
            'startedAt' => $row['started_at'] === null ? null : (string) $row['started_at'],
        ], $rows);
    }

    // --- internals ------------------------------------------------------------

    private function stampApplied(string $tenantId, string $id): void
    {
        $this->connection->executeStatement(
            'UPDATE learning_path_assignments SET last_applied_at = NOW()
             WHERE id = :id AND tenant_id = :tenant',
            ['id' => $id, 'tenant' => $tenantId],
        );
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<PathAssignment>
     */
    private function all(string $where, array $params): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s, %s FROM learning_path_assignments a %s
                 WHERE %s ORDER BY a.created_at DESC',
                self::COLUMNS,
                self::COUNTS,
                self::JOINS,
                $where,
            ),
            $params,
        );

        return array_map(fn (array $row): PathAssignment => $this->hydrate($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PathAssignment
    {
        $decoded = json_decode((string) ($row['target_criteria'] ?? '{}'), true);
        $name = is_string($row['assigned_by_name'] ?? null) ? trim($row['assigned_by_name']) : '';

        return new PathAssignment(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            pathId: (string) $row['path_id'],
            targetType: AudienceTarget::from((string) $row['target_type']),
            targetCriteria: is_array($decoded) ? $decoded : [],
            dueDate: $row['due_date'] === null ? null : (string) $row['due_date'],
            dueDaysFromAssignment: $row['due_days_from_assignment'] === null
                ? null
                : (int) $row['due_days_from_assignment'],
            priority: AssignmentPriority::from((string) $row['priority']),
            isMandatory: (bool) $row['is_mandatory'],
            sendNotification: (bool) $row['send_notification'],
            sendReminder: (bool) $row['send_reminder'],
            reminderDaysBefore: (int) $row['reminder_days_before'],
            notes: $this->text($row['notes']),
            status: AssignmentStatus::from((string) $row['status']),
            assignedBy: $this->text($row['assigned_by']),
            assignedAt: $this->at($row['assigned_at']),
            lastAppliedAt: $this->at($row['last_applied_at']),
            createdAt: $this->at($row['created_at']),
            updatedAt: $this->at($row['updated_at']),
            pathTitle: $this->text($row['path_title'] ?? null),
            assignedByName: $name === '' ? null : $name,
            enrolledCount: (int) ($row['enrolled_count'] ?? 0),
            completedCount: (int) ($row['completed_count'] ?? 0),
            overdueCount: (int) ($row['overdue_count'] ?? 0),
        );
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function at(mixed $value): ?\DateTimeImmutable
    {
        return is_string($value) ? new \DateTimeImmutable($value) : null;
    }
}
