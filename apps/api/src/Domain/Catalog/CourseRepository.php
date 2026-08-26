<?php

declare(strict_types=1);

namespace Central\Api\Domain\Catalog;

/**
 * Storage for the course catalogue.
 *
 * As with reference data, every method takes a tenant id and none omits it.
 */
interface CourseRepository
{
    /**
     * Administration list — every status.
     *
     * @return array{items: list<Course>, total: int}
     */
    public function paginate(
        string $tenantId,
        int $page,
        int $perPage,
        ?string $search = null,
        ?CourseStatus $status = null,
        ?string $categoryId = null,
        string $sortBy = 'title',
        string $sortDirection = 'asc',
    ): array;

    /**
     * Learner-facing catalogue — published courses only.
     *
     * A separate method rather than a flag, so a caller cannot forget to
     * filter and accidentally expose drafts.
     *
     * @return array{items: list<Course>, total: int}
     */
    public function catalogue(string $tenantId, int $page, int $perPage, ?string $search = null): array;

    public function find(string $tenantId, string $id): ?Course;

    public function create(Course $course): Course;

    public function update(Course $course): Course;

    /**
     * Moves a course between draft, published and archived.
     *
     * Separate from `update()` because publishing is a distinct act with its
     * own permission and its own side effect (stamping `published_at`).
     */
    public function setStatus(string $tenantId, string $id, CourseStatus $status): ?Course;

    /**
     * Soft-deletes. Courses are never hard-deleted — enrolments, progress and
     * certificates all reference them, and a certificate for a course that no
     * longer exists is unverifiable.
     */
    public function softDelete(string $tenantId, string $id): bool;

    public function existsWithTitle(string $tenantId, string $title, ?string $exceptId = null): bool;

    /**
     * Headline counts for the course screen.
     *
     * Counted on demand. The legacy kept `total_enrollments` on the course row
     * and never decremented it, so a course that had been emptied still
     * claimed nineteen learners.
     *
     * @return array<string, int>
     */
    public function statistics(string $tenantId): array;

    /**
     * How many people are enrolled, so a delete can refuse rather than
     * quietly orphan them.
     */
    public function enrollmentCount(string $tenantId, string $courseId): int;

    /**
     * Whether the course has at least one active lesson.
     *
     * Used to refuse publishing an empty course — a learner enrolling on one
     * finds nothing to complete, and it counts against them in every
     * completion report.
     */
    public function hasActiveLessons(string $tenantId, string $courseId): bool;
}
