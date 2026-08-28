<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Enrollment;

use Central\Api\Domain\Enrollment\EnrollmentSource;
use Central\Api\Domain\Enrollment\RuleSubject;
use Central\Api\Domain\Enrollment\SubjectEnroller;
use Central\Api\Domain\Ilt\TrainingRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * A standing rule putting people onto a live training programme.
 *
 * Different from a path in one way that matters: a programme has a capacity,
 * so a rule can cover more people than there are places. The enrolment goes
 * through {@see TrainingRepository::enrol}, which fills the places and puts
 * the rest on the waiting list — and the rule counts only the ones who got a
 * place, because a rule reporting "enrolled 50" for 10 places and 40 waiting
 * would be describing something that did not happen.
 */
final class TrainingSubjectEnroller implements SubjectEnroller
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TrainingRepository $trainings,
    ) {
    }

    public function subject(): RuleSubject
    {
        return RuleSubject::Training;
    }

    public function exists(string $tenantId, string $subjectId): bool
    {
        return $this->trainings->find($tenantId, $subjectId) !== null;
    }

    public function titleOf(string $tenantId, string $subjectId): ?string
    {
        return $this->trainings->find($tenantId, $subjectId)?->title;
    }

    public function acceptsEnrolments(string $tenantId, string $subjectId): bool
    {
        $training = $this->trainings->find($tenantId, $subjectId);

        // A draft programme takes nobody. A rule pointed at one is a
        // reasonable thing to set up early; it simply enrols nobody until the
        // programme is published, and the preview says so.
        return $training !== null && $training->admits(0)['allowed'];
    }

    public function enrol(
        string $tenantId,
        string $subjectId,
        array $userIds,
        string $ruleId,
        ?\DateTimeImmutable $dueDate,
        bool $isMandatory,
        ?string $actorId,
    ): int {
        $training = $this->trainings->find($tenantId, $subjectId);

        if ($training === null) {
            return 0;
        }

        $result = $this->trainings->enrol(
            $tenantId,
            $training,
            $userIds,
            EnrollmentSource::Rule,
            $actorId,
            $ruleId,
            $dueDate,
            $isMandatory,
        );

        // People given a place. The waiting list is reported separately by the
        // programme's own screens; counting it here would tell an
        // administrator forty people are attending when ten are.
        return count($result['enrolled']);
    }

    public function countEnrolled(string $tenantId, string $subjectId, array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }

        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM training_enrollments
             WHERE tenant_id = :tenant AND training_id = :training
               AND user_id IN (:ids) AND deleted_at IS NULL AND status <> 'dropped'",
            ['tenant' => $tenantId, 'training' => $subjectId, 'ids' => $userIds],
            ['ids' => ArrayParameterType::STRING],
        );
    }

    public function statistics(string $tenantId, string $ruleId): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT COUNT(*) AS enrolled,
                    COUNT(*) FILTER (WHERE e.status = 'completed') AS completed,
                    COUNT(*) FILTER (WHERE e.status = 'in_progress') AS in_progress,
                    COUNT(*) FILTER (WHERE e.status = 'not_started') AS not_started,
                    COUNT(*) FILTER (
                        WHERE e.status <> 'completed'
                          AND e.due_date IS NOT NULL AND e.due_date < CURRENT_DATE
                    ) AS overdue,
                    AVG(
                        EXTRACT(EPOCH FROM (e.completed_at - e.enrolled_at)) / 86400
                    ) FILTER (WHERE e.completed_at IS NOT NULL) AS avg_days,
                    -- A programme's progress is how much of it somebody has
                    -- turned up to, which is already kept per enrolment.
                    AVG(e.attendance_percentage) AS avg_progress
             FROM training_enrollments e
             WHERE e.rule_id = :rule AND e.tenant_id = :tenant AND e.deleted_at IS NULL",
            ['rule' => $ruleId, 'tenant' => $tenantId],
        );

        return RuleStatistics::from($row === false ? [] : $row);
    }

    public function pendingLearners(string $tenantId, string $ruleId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT e.id AS enrollment_id, e.user_id, e.due_date,
                    CASE WHEN e.sessions_attended > 0 THEN e.enrolled_at END AS started_at,
                    TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS name, u.email
             FROM training_enrollments e
             JOIN users u ON u.id = e.user_id
             WHERE e.rule_id = :rule AND e.tenant_id = :tenant
               AND e.deleted_at IS NULL AND e.status NOT IN ('completed', 'dropped')
               AND u.status = 'active' AND u.deleted_at IS NULL
             ORDER BY e.due_date NULLS LAST, u.first_name",
            ['rule' => $ruleId, 'tenant' => $tenantId],
        );

        return RuleStatistics::pending($rows);
    }
}
