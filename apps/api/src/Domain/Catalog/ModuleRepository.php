<?php

declare(strict_types=1);

namespace Central\Api\Domain\Catalog;

/**
 * Storage for course modules.
 *
 * Every method takes a tenant id and none omits it. `courseId` is required
 * alongside it wherever a module is addressed, so a module id belonging to
 * another course cannot be reached even within the right tenant.
 *
 * @see LessonRepository for the lessons inside them
 */
interface ModuleRepository
{
    /**
     * Ordered by `sortOrder`, then title.
     *
     * @return list<CourseModule>
     */
    public function forCourse(string $tenantId, string $courseId, bool $activeOnly = false): array;

    public function find(string $tenantId, string $courseId, string $id): ?CourseModule;

    public function create(CourseModule $module): CourseModule;

    public function update(CourseModule $module): CourseModule;

    public function setActive(string $tenantId, string $courseId, string $id, bool $isActive): ?CourseModule;

    /**
     * Soft-deletes the module and everything under it.
     *
     * Lessons go with it: a lesson whose module is gone is unreachable, and
     * leaving it behind makes it invisible to every list but still countable
     * in progress figures.
     */
    public function softDelete(string $tenantId, string $courseId, string $id): bool;

    /**
     * Applies a new order in one statement.
     *
     * Reordering by drag-and-drop otherwise means one request per module, and
     * a half-applied order is worse than none.
     *
     * @param list<string> $orderedIds every module in the course, in the new order
     *
     * @return int the number of modules actually moved
     */
    public function reorder(string $tenantId, string $courseId, array $orderedIds): int;

    /**
     * The ids that exist in this course, of those given.
     *
     * Lets a caller reject a reorder that names something it should not, in
     * one query rather than one per id.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public function existingIds(string $tenantId, string $courseId, array $ids): array;

    /**
     * The next free position, so a newly created module lands at the end
     * rather than colliding with an existing one at 0.
     */
    public function nextSortOrder(string $tenantId, string $courseId): int;
}
