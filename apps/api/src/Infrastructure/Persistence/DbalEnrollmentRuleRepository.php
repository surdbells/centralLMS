<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Audience\AudienceResolver;
use Central\Api\Domain\Audience\AudienceTarget;
use Central\Api\Domain\Enrollment\EnrollmentRule;
use Central\Api\Domain\Enrollment\EnrollmentRuleRepository;
use Central\Api\Domain\Enrollment\RulePriority;
use Central\Api\Domain\Enrollment\RuleStatus;
use Central\Api\Domain\Enrollment\RuleSubject;
use Central\Api\Domain\Enrollment\SubjectEnroller;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class DbalEnrollmentRuleRepository implements EnrollmentRuleRepository
{
    private const COLUMNS = "r.id, r.tenant_id, r.subject_kind, r.subject_id,
                             r.target_type, r.target_criteria,
                             r.due_date, r.due_days_from_enrollment, r.priority, r.is_mandatory,
                             r.send_notification, r.send_reminder, r.reminder_days_before,
                             r.notes, r.status, r.created_by, r.created_at, r.last_applied_at,
                             r.updated_at,
                             TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS created_by_name";

    /**
     * The subject's own title, from whichever table holds it.
     *
     * A join would need one per kind; a CASE keeps the rule query one query
     * however many kinds there come to be.
     */
    private const TITLE = "CASE r.subject_kind
                               WHEN 'path' THEN (
                                   SELECT p.title FROM learning_paths p WHERE p.id = r.subject_id
                               )
                               WHEN 'training' THEN (
                                   SELECT t.title FROM live_trainings t WHERE t.id = r.subject_id
                               )
                           END AS subject_title";

    /**
     * What became of the people this rule enrolled, counted at read time.
     *
     * Both tables, because a rule knows which one it wrote to and the counts
     * should not have to. Only one of the two can match a given rule id.
     */
    private const COUNTS = "(
                                SELECT COUNT(*) FROM learning_path_enrollments e
                                WHERE e.rule_id = r.id AND e.deleted_at IS NULL
                            ) + (
                                SELECT COUNT(*) FROM training_enrollments e
                                WHERE e.rule_id = r.id AND e.deleted_at IS NULL
                            ) AS enrolled_count,
                            (
                                SELECT COUNT(*) FROM learning_path_enrollments e
                                WHERE e.rule_id = r.id AND e.deleted_at IS NULL
                                  AND e.status = 'completed'
                            ) + (
                                SELECT COUNT(*) FROM training_enrollments e
                                WHERE e.rule_id = r.id AND e.deleted_at IS NULL
                                  AND e.status = 'completed'
                            ) AS completed_count,
                            (
                                SELECT COUNT(*) FROM learning_path_enrollments e
                                WHERE e.rule_id = r.id AND e.deleted_at IS NULL
                                  AND e.status <> 'completed'
                                  AND e.due_date IS NOT NULL AND e.due_date < CURRENT_DATE
                            ) + (
                                SELECT COUNT(*) FROM training_enrollments e
                                WHERE e.rule_id = r.id AND e.deleted_at IS NULL
                                  AND e.status <> 'completed'
                                  AND e.due_date IS NOT NULL AND e.due_date < CURRENT_DATE
                            ) AS overdue_count";

    /** @var array<string, SubjectEnroller> */
    private readonly array $enrollers;

    /**
     * @param iterable<SubjectEnroller> $enrollers
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly AudienceResolver $audience,
        iterable $enrollers,
    ) {
        $byKind = [];

        foreach ($enrollers as $enroller) {
            $byKind[$enroller->subject()->value] = $enroller;
        }

        $this->enrollers = $byKind;
    }

    public function forSubject(string $tenantId, RuleSubject $kind, string $subjectId): array
    {
        return $this->all(
            'r.tenant_id = :tenant AND r.subject_kind = :kind AND r.subject_id = :subject
             AND r.deleted_at IS NULL',
            ['tenant' => $tenantId, 'kind' => $kind->value, 'subject' => $subjectId],
        );
    }

    public function running(?string $tenantId = null): array
    {
        if ($tenantId === null) {
            return $this->all("r.status = 'active' AND r.deleted_at IS NULL", []);
        }

        return $this->all(
            "r.tenant_id = :tenant AND r.status = 'active' AND r.deleted_at IS NULL",
            ['tenant' => $tenantId],
        );
    }

    public function find(string $tenantId, string $id): ?EnrollmentRule
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, %s, %s FROM enrollment_rules r
                 LEFT JOIN users u ON u.id = r.created_by
                 WHERE r.id = :id AND r.tenant_id = :tenant AND r.deleted_at IS NULL',
                self::COLUMNS,
                self::TITLE,
                self::COUNTS,
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function save(EnrollmentRule $rule): EnrollmentRule
    {
        $this->connection->executeStatement(
            'INSERT INTO enrollment_rules
                 (id, tenant_id, subject_kind, subject_id, target_type, target_criteria,
                  due_date, due_days_from_enrollment, priority, is_mandatory,
                  send_notification, send_reminder, reminder_days_before, notes, status,
                  created_by)
             VALUES (:id, :tenant, :kind, :subject, :target, :criteria::jsonb,
                     :dueDate::date, :offset, :priority, :mandatory,
                     :notify, :remind, :remindDays, :notes, :status, :by)
             ON CONFLICT (id) DO UPDATE SET
                 target_type     = EXCLUDED.target_type,
                 target_criteria = EXCLUDED.target_criteria,
                 due_date                 = EXCLUDED.due_date,
                 due_days_from_enrollment = EXCLUDED.due_days_from_enrollment,
                 priority     = EXCLUDED.priority,
                 is_mandatory = EXCLUDED.is_mandatory,
                 send_notification    = EXCLUDED.send_notification,
                 send_reminder        = EXCLUDED.send_reminder,
                 reminder_days_before = EXCLUDED.reminder_days_before,
                 notes  = EXCLUDED.notes,
                 status = EXCLUDED.status,
                 updated_at = NOW()',
            [
                'id'        => $rule->id,
                'tenant'    => $rule->tenantId,
                'kind'      => $rule->subjectKind->value,
                'subject'   => $rule->subjectId,
                'target'    => $rule->targetType->value,
                'criteria'  => json_encode($rule->targetCriteria, JSON_THROW_ON_ERROR),
                'dueDate'   => $rule->dueDate,
                'offset'    => $rule->dueDaysFromEnrollment,
                'priority'  => $rule->priority->value,
                'mandatory' => $rule->isMandatory,
                'notify'    => $rule->sendNotification,
                'remind'    => $rule->sendReminder,
                'remindDays' => $rule->reminderDaysBefore,
                'notes'     => $rule->notes,
                'status'    => $rule->status->value,
                'by'        => $rule->createdBy,
            ],
            [
                'mandatory' => ParameterType::BOOLEAN,
                'notify'    => ParameterType::BOOLEAN,
                'remind'    => ParameterType::BOOLEAN,
            ],
        );

        return $this->find($rule->tenantId, $rule->id) ?? $rule;
    }

    public function setStatus(string $tenantId, string $id, RuleStatus $status): ?EnrollmentRule
    {
        // Nothing here touches the enrolments the rule already made. Taking
        // something away from somebody halfway through it is a different
        // decision, and not one anybody made by pausing a rule.
        $affected = $this->connection->executeStatement(
            'UPDATE enrollment_rules SET status = :status, updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId, 'status' => $status->value],
        );

        return $affected > 0 ? $this->find($tenantId, $id) : null;
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->connection->executeStatement(
            'UPDATE enrollment_rules SET deleted_at = NOW()
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

        $enroller = $this->enrollers[$rule->subjectKind->value] ?? null;

        if ($enroller === null || !$enroller->exists($tenantId, $rule->subjectId)) {
            // A rule whose subject has been deleted is skipped rather than
            // failed: an archived path must not stop every other rule in the
            // organisation from running.
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

        return $enroller->enrol(
            $tenantId,
            $rule->subjectId,
            $userIds,
            $rule->id,
            $due === null ? null : new \DateTimeImmutable($due),
            $rule->isMandatory,
            $rule->createdBy,
        );
    }

    public function preview(string $tenantId, string $id): array
    {
        $empty = [
            'covered'          => 0,
            'alreadyEnrolled'  => 0,
            'wouldEnrol'       => 0,
            'subjectAccepting' => false,
        ];
        $rule = $this->find($tenantId, $id);

        if ($rule === null) {
            return $empty;
        }

        $enroller = $this->enrollers[$rule->subjectKind->value] ?? null;
        $userIds = $this->audience->resolve($tenantId, $rule->targetType, $rule->targetCriteria);

        if ($enroller === null) {
            return $empty;
        }

        // A draft programme takes nobody, so a rule pointed at one enrols
        // nobody until it is published. Saying "would enrol 412" and then
        // enrolling none is the kind of answer that costs an afternoon.
        $accepting = $enroller->acceptsEnrolments($tenantId, $rule->subjectId);

        if ($userIds === []) {
            return [...$empty, 'subjectAccepting' => $accepting];
        }

        $already = $enroller->countEnrolled($tenantId, $rule->subjectId, $userIds);

        return [
            'covered'          => count($userIds),
            'alreadyEnrolled'  => $already,
            'wouldEnrol'       => $accepting ? count($userIds) - $already : 0,
            'subjectAccepting' => $accepting,
        ];
    }

    // --- what the rule has done -----------------------------------------------

    public function statistics(string $tenantId, string $id): array
    {
        $rule = $this->find($tenantId, $id);
        $enroller = $rule === null ? null : ($this->enrollers[$rule->subjectKind->value] ?? null);

        if ($enroller === null) {
            return [
                'enrolled'   => 0,
                'completed'  => 0,
                'inProgress' => 0,
                'notStarted' => 0,
                'overdue'    => 0,
                'completionRate'        => null,
                'averageProgress'       => null,
                'averageDaysToComplete' => null,
            ];
        }

        return $enroller->statistics($tenantId, $id);
    }

    public function pendingLearners(string $tenantId, string $id): array
    {
        $rule = $this->find($tenantId, $id);
        $enroller = $rule === null ? null : ($this->enrollers[$rule->subjectKind->value] ?? null);

        return $enroller === null ? [] : $enroller->pendingLearners($tenantId, $id);
    }

    // --- internals ------------------------------------------------------------

    private function stampApplied(string $tenantId, string $id): void
    {
        $this->connection->executeStatement(
            'UPDATE enrollment_rules SET last_applied_at = NOW()
             WHERE id = :id AND tenant_id = :tenant',
            ['id' => $id, 'tenant' => $tenantId],
        );
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<EnrollmentRule>
     */
    private function all(string $where, array $params): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s, %s, %s FROM enrollment_rules r
                 LEFT JOIN users u ON u.id = r.created_by
                 WHERE %s ORDER BY r.created_at DESC',
                self::COLUMNS,
                self::TITLE,
                self::COUNTS,
                $where,
            ),
            $params,
        );

        return array_map(fn (array $row): EnrollmentRule => $this->hydrate($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): EnrollmentRule
    {
        $decoded = json_decode((string) ($row['target_criteria'] ?? '{}'), true);
        $name = is_string($row['created_by_name'] ?? null) ? trim($row['created_by_name']) : '';

        return new EnrollmentRule(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            subjectKind: RuleSubject::from((string) $row['subject_kind']),
            subjectId: (string) $row['subject_id'],
            targetType: AudienceTarget::from((string) $row['target_type']),
            targetCriteria: is_array($decoded) ? $decoded : [],
            dueDate: $row['due_date'] === null ? null : (string) $row['due_date'],
            dueDaysFromEnrollment: $row['due_days_from_enrollment'] === null
                ? null
                : (int) $row['due_days_from_enrollment'],
            priority: RulePriority::from((string) $row['priority']),
            isMandatory: (bool) $row['is_mandatory'],
            sendNotification: (bool) $row['send_notification'],
            sendReminder: (bool) $row['send_reminder'],
            reminderDaysBefore: (int) $row['reminder_days_before'],
            notes: $this->text($row['notes']),
            status: RuleStatus::from((string) $row['status']),
            createdBy: $this->text($row['created_by']),
            createdAt: $this->at($row['created_at']),
            lastAppliedAt: $this->at($row['last_applied_at']),
            updatedAt: $this->at($row['updated_at']),
            subjectTitle: $this->text($row['subject_title'] ?? null),
            createdByName: $name === '' ? null : $name,
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
