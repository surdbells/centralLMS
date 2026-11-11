<?php

declare(strict_types=1);

namespace Central\Api\Domain\LearningPlan;

interface LearningPlanRepository
{
    // --- administration -------------------------------------------------------

    /**
     * Plans for the tenant, newest year first, with item and member counts.
     *
     * @return list<LearningPlan>
     */
    public function list(string $tenantId, ?int $year = null): array;

    public function find(string $tenantId, string $id): ?LearningPlan;

    public function save(LearningPlan $plan): LearningPlan;

    public function setStatus(string $tenantId, string $id, string $status): ?LearningPlan;

    public function delete(string $tenantId, string $id): bool;

    /**
     * The plan's items in order, each with its course title when it is a course.
     *
     * @return list<array<string, mixed>>
     */
    public function items(string $tenantId, string $planId): array;

    /**
     * Adds or updates an item. $input: {id?, courseId?, title, description?,
     * isMandatory?, sortOrder?}.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function saveItem(string $tenantId, string $planId, array $input): array;

    public function deleteItem(string $tenantId, string $planId, string $itemId): bool;

    /**
     * Assigns people to the plan (ignoring any already assigned).
     *
     * @param list<string> $userIds
     *
     * @return int how many were newly assigned
     */
    public function assignMembers(string $tenantId, string $planId, array $userIds): int;

    /**
     * Assigns everyone in a department to the plan.
     *
     * @return int how many were newly assigned
     */
    public function assignDepartment(string $tenantId, string $planId, string $departmentId): int;

    /**
     * Assigns every active learner in the tenant to the plan.
     *
     * @return int how many were newly assigned
     */
    public function assignEveryone(string $tenantId, string $planId): int;

    /**
     * The plan's assignees.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function members(string $tenantId, string $planId, int $page, int $perPage): array;

    public function removeMember(string $tenantId, string $planId, string $userId): bool;

    // --- the learner's own ----------------------------------------------------

    /**
     * The published plans this person is on, newest year first, each with its
     * items and the person's derived status per item (Not Scheduled / Scheduled
     * / In Progress / Completed).
     *
     * @return list<array<string, mixed>>
     */
    public function forLearner(string $tenantId, string $userId): array;

    /** Sets (or clears) the person's target date for one item. */
    public function setSchedule(string $tenantId, string $userId, string $itemId, ?string $date): void;

    /** Sets the person's status for a non-course intervention item. */
    public function setItemStatus(string $tenantId, string $userId, string $itemId, string $status): void;

    // --- completion stats -----------------------------------------------------

    /**
     * How much of the person's plan(s) is complete — for the learner report.
     * With $year, just that year; without, all years combined.
     *
     * @return array{total: int, completed: int, rate: float, byYear: list<array<string, mixed>>}
     */
    public function completionForLearner(string $tenantId, string $userId, ?int $year = null): array;
}
