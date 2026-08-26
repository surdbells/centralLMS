<?php

declare(strict_types=1);

namespace Central\Api\Domain\Catalog;

/**
 * Storage for lessons.
 *
 * As with modules, every method is scoped by tenant, and a lesson is always
 * addressed through the module it belongs to.
 */
interface LessonRepository
{
    /**
     * Ordered by `sortOrder`, then title.
     *
     * @return list<Lesson>
     */
    public function forModule(string $tenantId, string $moduleId, bool $activeOnly = false): array;

    /**
     * Every lesson in a course, keyed by module id.
     *
     * This is what makes the course outline two queries instead of one per
     * module — the legacy screen issued exactly that N+1.
     *
     * @return array<string, list<Lesson>>
     */
    public function forCourseByModule(string $tenantId, string $courseId, bool $activeOnly = false): array;

    public function find(string $tenantId, string $moduleId, string $id): ?Lesson;

    /**
     * Finds a lesson by its course rather than its module.
     *
     * The course player knows the course and the lesson it is showing; making
     * it also track the module id would be bookkeeping for its own sake. The
     * denormalised `course_id` column exists so this costs one query.
     */
    public function findInCourse(string $tenantId, string $courseId, string $id): ?Lesson;

    public function create(Lesson $lesson): Lesson;

    public function update(Lesson $lesson): Lesson;

    public function setActive(string $tenantId, string $moduleId, string $id, bool $isActive): ?Lesson;

    public function softDelete(string $tenantId, string $moduleId, string $id): bool;

    /**
     * @param list<string> $orderedIds every lesson in the module, in the new order
     *
     * @return int the number of lessons actually moved
     */
    public function reorder(string $tenantId, string $moduleId, array $orderedIds): int;

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public function existingIds(string $tenantId, string $moduleId, array $ids): array;

    public function nextSortOrder(string $tenantId, string $moduleId): int;
}
