<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Enrollment;

use Central\Api\Domain\Enrollment\RuleSubject;
use Central\Api\Domain\Enrollment\SubjectEnroller;
use Central\Api\Domain\LearningPath\LearningPathRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * A standing rule putting people onto a learning path.
 *
 * The enrolment itself goes through {@see LearningPathRepository::enrol},
 * which also puts them on the path's courses — a path whose members cannot
 * open its courses is a reading list — and skips anybody already there.
 */
final class PathSubjectEnroller implements SubjectEnroller
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LearningPathRepository $paths,
    ) {
    }

    public function subject(): RuleSubject
    {
        return RuleSubject::Path;
    }

    public function exists(string $tenantId, string $subjectId): bool
    {
        return $this->paths->find($tenantId, $subjectId) !== null;
    }

    public function titleOf(string $tenantId, string $subjectId): ?string
    {
        return $this->paths->find($tenantId, $subjectId)?->title;
    }

    public function acceptsEnrolments(string $tenantId, string $subjectId): bool
    {
        // An administrator may put somebody on a draft path deliberately —
        // reviewers, for one — so a path takes people whatever its status.
        return $this->exists($tenantId, $subjectId);
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
        return $this->paths->enrol(
            $tenantId,
            $subjectId,
            $userIds,
            'rule',
            $actorId,
            $dueDate,
            $isMandatory,
            $ruleId,
        )['enrolled'];
    }

    public function countEnrolled(string $tenantId, string $subjectId, array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM learning_path_enrollments
             WHERE tenant_id = :tenant AND path_id = :path
               AND user_id IN (:ids) AND deleted_at IS NULL',
            ['tenant' => $tenantId, 'path' => $subjectId, 'ids' => $userIds],
            ['ids' => ArrayParameterType::STRING],
        );
    }

    public function statistics(string $tenantId, string $ruleId): array
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
             WHERE e.rule_id = :rule AND e.tenant_id = :tenant AND e.deleted_at IS NULL",
            ['rule' => $ruleId, 'tenant' => $tenantId],
        );

        return RuleStatistics::from($row === false ? [] : $row);
    }

    public function pendingLearners(string $tenantId, string $ruleId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT e.id AS enrollment_id, e.user_id, e.due_date, e.started_at,
                    TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS name, u.email
             FROM learning_path_enrollments e
             JOIN users u ON u.id = e.user_id
             WHERE e.rule_id = :rule AND e.tenant_id = :tenant
               AND e.deleted_at IS NULL AND e.status <> 'completed'
               AND u.status = 'active' AND u.deleted_at IS NULL
             ORDER BY e.due_date NULLS LAST, u.first_name",
            ['rule' => $ruleId, 'tenant' => $tenantId],
        );

        return RuleStatistics::pending($rows);
    }
}
