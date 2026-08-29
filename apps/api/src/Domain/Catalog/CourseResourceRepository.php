<?php

declare(strict_types=1);

namespace Central\Api\Domain\Catalog;

/**
 * The downloadable resources attached to a course.
 */
interface CourseResourceRepository
{
    /**
     * Every live resource on a course, in display order.
     *
     * @return list<CourseResource>
     */
    public function forCourse(string $tenantId, string $courseId): array;

    /** One resource, or null if it is missing or soft-deleted. */
    public function find(string $tenantId, string $id): ?CourseResource;

    public function add(CourseResource $resource): CourseResource;

    /** Soft-deletes a resource; returns the storage key to remove, or null. */
    public function remove(string $tenantId, string $id): ?string;
}
