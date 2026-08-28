<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Assessment\GradingAssignment;
use Central\Api\Domain\Assessment\GradingAssignmentRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class DbalGradingAssignmentRepository implements GradingAssignmentRepository
{
    private const COLUMNS = 'g.id, g.tenant_id, g.attempt_id, g.assigned_to, g.assigned_by,
                             g.priority, g.skipped_at, g.skip_reason, g.notes,
                             g.created_at, g.updated_at,
                             TRIM(CONCAT(u.first_name, \' \', u.last_name)) AS assignee_name';

    private const JOINS = 'LEFT JOIN users u ON u.id = g.assigned_to';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function forAttempts(string $tenantId, array $attemptIds): array
    {
        if ($attemptIds === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s FROM grading_assignments g %s
                 WHERE g.tenant_id = :tenant AND g.attempt_id IN (:ids)',
                self::COLUMNS,
                self::JOINS,
            ),
            ['tenant' => $tenantId, 'ids' => array_values(array_unique($attemptIds))],
            ['ids' => ArrayParameterType::STRING],
        );

        $byAttempt = [];

        foreach ($rows as $row) {
            $byAttempt[(string) $row['attempt_id']] = $this->hydrate($row);
        }

        return $byAttempt;
    }

    public function find(string $tenantId, string $attemptId): ?GradingAssignment
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM grading_assignments g %s
                 WHERE g.tenant_id = :tenant AND g.attempt_id = :attempt',
                self::COLUMNS,
                self::JOINS,
            ),
            ['tenant' => $tenantId, 'attempt' => $attemptId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function assign(
        string $tenantId,
        string $attemptId,
        ?string $assignedTo,
        ?string $assignedBy,
        int $priority = 0,
        ?string $notes = null,
    ): GradingAssignment {
        $this->connection->executeStatement(
            'INSERT INTO grading_assignments
                 (id, tenant_id, attempt_id, assigned_to, assigned_by, priority, notes)
             VALUES (gen_random_uuid(), :tenant, :attempt, :to, :by, :priority, :notes)
             ON CONFLICT (attempt_id) DO UPDATE SET
                 assigned_to = EXCLUDED.assigned_to,
                 assigned_by = EXCLUDED.assigned_by,
                 priority    = EXCLUDED.priority,
                 notes       = EXCLUDED.notes,
                 -- Handing it to somebody else clears the previous skip: the
                 -- new marker has not set it aside, and leaving the flag would
                 -- hide it from the person who now owns it.
                 skipped_at  = NULL,
                 skip_reason = NULL,
                 updated_at  = NOW()',
            [
                'tenant'   => $tenantId,
                'attempt'  => $attemptId,
                'to'       => $assignedTo,
                'by'       => $assignedBy,
                'priority' => $priority,
                'notes'    => $notes,
            ],
        );

        return $this->find($tenantId, $attemptId)
            ?? throw new \RuntimeException('The assignment did not save.');
    }

    public function release(string $tenantId, string $attemptId): bool
    {
        // Removing the row is how something returns to the general queue —
        // there is no "unassigned" state to set, and so none to forget.
        return $this->connection->executeStatement(
            'DELETE FROM grading_assignments WHERE tenant_id = :tenant AND attempt_id = :attempt',
            ['tenant' => $tenantId, 'attempt' => $attemptId],
        ) > 0;
    }

    public function skip(string $tenantId, string $attemptId, ?string $reason): ?GradingAssignment
    {
        $affected = $this->connection->executeStatement(
            'UPDATE grading_assignments
             SET skipped_at = NOW(), skip_reason = :reason, updated_at = NOW()
             WHERE tenant_id = :tenant AND attempt_id = :attempt',
            ['tenant' => $tenantId, 'attempt' => $attemptId, 'reason' => $reason],
        );

        return $affected > 0 ? $this->find($tenantId, $attemptId) : null;
    }

    public function setPriority(string $tenantId, string $attemptId, int $priority): ?GradingAssignment
    {
        $affected = $this->connection->executeStatement(
            'UPDATE grading_assignments SET priority = :priority, updated_at = NOW()
             WHERE tenant_id = :tenant AND attempt_id = :attempt',
            ['tenant' => $tenantId, 'attempt' => $attemptId, 'priority' => $priority],
        );

        return $affected > 0 ? $this->find($tenantId, $attemptId) : null;
    }

    public function assignEvenly(
        string $tenantId,
        array $attemptIds,
        array $markerIds,
        ?string $assignedBy,
    ): int {
        if ($attemptIds === [] || $markerIds === []) {
            return 0;
        }

        $markers = array_values($markerIds);
        $assigned = 0;

        $this->connection->transactional(function () use (
            $tenantId,
            $attemptIds,
            $markers,
            $assignedBy,
            &$assigned,
        ): void {
            foreach (array_values($attemptIds) as $position => $attemptId) {
                // Round-robin — see the interface for why this is not
                // "whoever has least", which needs a count that two
                // coordinators assigning at once would both read as stale.
                $this->assign(
                    $tenantId,
                    $attemptId,
                    $markers[$position % count($markers)],
                    $assignedBy,
                );

                $assigned++;
            }
        });

        return $assigned;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): GradingAssignment
    {
        $name = is_string($row['assignee_name'] ?? null) ? trim($row['assignee_name']) : '';

        return new GradingAssignment(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            attemptId: (string) $row['attempt_id'],
            assignedTo: $row['assigned_to'] === null ? null : (string) $row['assigned_to'],
            assignedBy: $row['assigned_by'] === null ? null : (string) $row['assigned_by'],
            priority: (int) $row['priority'],
            skippedAt: is_string($row['skipped_at']) ? new \DateTimeImmutable($row['skipped_at']) : null,
            skipReason: $row['skip_reason'] === null ? null : (string) $row['skip_reason'],
            notes: $row['notes'] === null ? null : (string) $row['notes'],
            createdAt: is_string($row['created_at']) ? new \DateTimeImmutable($row['created_at']) : null,
            updatedAt: is_string($row['updated_at']) ? new \DateTimeImmutable($row['updated_at']) : null,
            assigneeName: $name === '' ? null : $name,
        );
    }
}
