<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Assessment\AssessmentAssignment;
use Central\Api\Domain\Assessment\AssessmentAssignmentRepository;
use Doctrine\DBAL\Connection;

final class DbalAssessmentAssignmentRepository implements AssessmentAssignmentRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function forAssessment(string $tenantId, string $assessmentId): array
    {
        // The target name is resolved here, across the two places a target can
        // live — a user, or a lookup (department, team, branch). Left joins so
        // a target deleted out from under an assignment still lists, unnamed,
        // rather than dropping the row.
        $rows = $this->connection->fetchAllAssociative(
            "SELECT a.id, a.assessment_id, a.assignment_type, a.target_id, a.due_date, a.created_at,
                    COALESCE(
                        NULLIF(TRIM(u.first_name || ' ' || u.last_name), ''),
                        l.name
                    ) AS target_name
             FROM assessment_assignments a
             LEFT JOIN users u ON u.id = a.target_id AND a.assignment_type = 'user'
             LEFT JOIN lookups l ON l.id = a.target_id AND a.assignment_type <> 'user'
             WHERE a.tenant_id = :tenant AND a.assessment_id = :assessment
             ORDER BY a.created_at",
            ['tenant' => $tenantId, 'assessment' => $assessmentId],
        );

        return array_map(
            fn (array $r): AssessmentAssignment => new AssessmentAssignment(
                id: (string) $r['id'],
                tenantId: $tenantId,
                assessmentId: (string) $r['assessment_id'],
                assignmentType: (string) $r['assignment_type'],
                targetId: $r['target_id'] === null ? null : (string) $r['target_id'],
                targetName: $r['target_name'] === null ? null : (string) $r['target_name'],
                dueDate: $this->date($r['due_date'] ?? null),
                createdAt: $this->date($r['created_at'] ?? null),
            ),
            $rows,
        );
    }

    public function create(
        string $tenantId,
        string $assessmentId,
        string $assignmentType,
        ?string $targetId,
        ?\DateTimeImmutable $dueDate,
        ?string $createdBy,
    ): AssessmentAssignment {
        $id = (string) $this->connection->fetchOne(
            'INSERT INTO assessment_assignments
                 (tenant_id, assessment_id, assignment_type, target_id, due_date, created_by)
             VALUES (:tenant, :assessment, :type, :target, :due, :createdBy)
             RETURNING id',
            [
                'tenant'     => $tenantId,
                'assessment' => $assessmentId,
                'type'       => $assignmentType,
                'target'     => $targetId,
                'due'        => $dueDate?->format(\DateTimeInterface::ATOM),
                'createdBy'  => $createdBy,
            ],
        );

        foreach ($this->forAssessment($tenantId, $assessmentId) as $assignment) {
            if ($assignment->id === $id) {
                return $assignment;
            }
        }

        throw new \RuntimeException('The assignment vanished immediately after being created.');
    }

    public function delete(string $tenantId, string $assessmentId, string $id): bool
    {
        return $this->connection->executeStatement(
            'DELETE FROM assessment_assignments
             WHERE id = :id AND assessment_id = :assessment AND tenant_id = :tenant',
            ['id' => $id, 'assessment' => $assessmentId, 'tenant' => $tenantId],
        ) > 0;
    }

    public function targets(string $tenantId): array
    {
        $users = $this->connection->fetchAllAssociative(
            "SELECT id, TRIM(first_name || ' ' || last_name) AS name
             FROM users
             WHERE tenant_id = :tenant AND status <> 'suspended' AND deleted_at IS NULL
             ORDER BY first_name, last_name
             LIMIT 500",
            ['tenant' => $tenantId],
        );

        return [
            'users'       => $this->shape($users),
            'departments' => $this->lookups($tenantId, 'department'),
            'teams'       => $this->lookups($tenantId, 'team'),
            'branches'    => $this->lookups($tenantId, 'branch'),
        ];
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function lookups(string $tenantId, string $kind): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name FROM lookups
             WHERE tenant_id = :tenant AND kind = :kind AND is_active AND deleted_at IS NULL
             ORDER BY sort_order, name',
            ['tenant' => $tenantId, 'kind' => $kind],
        );

        return $this->shape($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{id: string, name: string}>
     */
    private function shape(array $rows): array
    {
        return array_map(
            static fn (array $r): array => ['id' => (string) $r['id'], 'name' => (string) $r['name']],
            $rows,
        );
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        return is_string($value) ? new \DateTimeImmutable($value) : null;
    }
}
