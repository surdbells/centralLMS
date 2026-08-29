<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

/**
 * Who is required to take which assessment.
 */
interface AssessmentAssignmentRepository
{
    /**
     * The assignments on one assessment, each with its target resolved.
     *
     * @return list<AssessmentAssignment>
     */
    public function forAssessment(string $tenantId, string $assessmentId): array;

    /**
     * Records an assignment. `targetId` is null only for the 'all' type.
     *
     * @throws \Doctrine\DBAL\Exception\UniqueConstraintViolationException when the target is already assigned
     */
    public function create(
        string $tenantId,
        string $assessmentId,
        string $assignmentType,
        ?string $targetId,
        ?\DateTimeImmutable $dueDate,
        ?string $createdBy,
    ): AssessmentAssignment;

    public function delete(string $tenantId, string $assessmentId, string $id): bool;

    /**
     * The people and groups an assessment can be assigned to.
     *
     * @return array{
     *     users: list<array{id: string, name: string}>,
     *     departments: list<array{id: string, name: string}>,
     *     teams: list<array{id: string, name: string}>,
     *     branches: list<array{id: string, name: string}>
     * }
     */
    public function targets(string $tenantId): array;
}
