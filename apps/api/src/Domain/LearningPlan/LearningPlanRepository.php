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
     * The plan's SHARED items (user_id IS NULL) in order, each with its course
     * title when it is a course. Per-person items are paged by personalItems().
     *
     * @return list<array<string, mixed>>
     */
    public function items(string $tenantId, string $planId): array;

    /**
     * Adds or updates an item. $input: {id?, courseId?, title, description?,
     * isMandatory?, sortOrder?, userId?, category?, provider?, scheduleText?,
     * plannedStart?, plannedEnd?}. With userId the item belongs to that person,
     * who is assigned to the plan if they are not already.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     *
     * @throws \Central\Api\Domain\Exception\ValidationFailed
     */
    public function saveItem(string $tenantId, string $planId, array $input): array;

    /**
     * Per-person items, paged by person (ordered by name), with each item's
     * derived status, and item counts per category (ignoring $category).
     *
     * @return array{people: list<array<string, mixed>>, total: int, page: int, perPage: int, categories: array<string, int>}
     */
    public function personalItems(
        string $tenantId,
        string $planId,
        ?string $userId,
        ?string $category,
        ?string $q,
        int $page,
        int $perPage,
    ): array;

    // --- import ---------------------------------------------------------------

    /** The live plan for that year whose title matches (case- and dash-insensitive). */
    public function findByYearTitle(string $tenantId, int $year, string $title): ?LearningPlan;

    /**
     * The plan's imported items, keyed by import_key.
     *
     * @return array<string, array{id: string, user_id: ?string, title: string, category: ?string, provider: ?string, schedule_text: ?string, planned_start: ?string, planned_end: ?string, sort_order: int}>
     */
    public function importedItems(string $tenantId, string $planId): array;

    /**
     * Inserts or updates one imported item by (plan, import_key). Never touches
     * anyone's progress.
     *
     * @param array{importKey: string, userId: string, title: string, category: string, provider: ?string, scheduleText: ?string, plannedStart: ?string, plannedEnd: ?string, sortOrder: int} $row
     *
     * @return 'created'|'updated'
     */
    public function upsertImportedItem(string $tenantId, string $planId, array $row): string;

    /**
     * @param list<string> $ids
     */
    public function deleteItemsByIds(string $tenantId, string $planId, array $ids): int;

    /**
     * The tenant's live users by lower-cased, trimmed e-mail.
     *
     * @return array<string, array{id: string, name: string, status: string}>
     */
    public function userEmailIndex(string $tenantId): array;

    /**
     * Runs $work in one database transaction (rolled back if it throws).
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function transactional(\Closure $work): mixed;

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

    /**
     * Whether the person may set a date/status on this item: its plan is
     * published and live, they are a member, and the item is shared or theirs.
     */
    public function canTouchItem(string $tenantId, string $userId, string $itemId): bool;

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
