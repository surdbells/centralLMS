<?php

declare(strict_types=1);

namespace Central\Api\Domain\Catalog;

interface CourseReviewRepository
{
    /**
     * The published reviews of one course, newest first.
     *
     * @return array{items: list<CourseReview>, total: int}
     */
    public function published(string $tenantId, string $courseId, int $page, int $perPage): array;

    public function find(string $tenantId, string $id): ?CourseReview;

    /** The review this enrolment left, if any. */
    public function forEnrolment(string $tenantId, string $enrollmentId): ?CourseReview;

    /**
     * Every review this person has left, newest first — so a learner's own
     * screens can show which courses they have already rated.
     *
     * @return list<CourseReview>
     */
    public function forUser(string $tenantId, string $userId): array;

    /**
     * Writes a review and recomputes the course's rating average and count
     * from its published reviews. The average is never trusted as stored
     * state: it changes whenever anybody reviews, re-reviews or is hidden.
     */
    public function save(CourseReview $review): CourseReview;

    /**
     * Hides or republishes a review (moderation), recomputing the course
     * average. Hiding keeps the row — the rating survives for audit — but
     * drops it out of the public average.
     */
    public function setPublished(string $tenantId, string $id, bool $published, string $moderatorId): ?CourseReview;

    public function delete(string $tenantId, string $id, string $byUserId, bool $isModerator): bool;

    /**
     * The rating summary for one course, from its published reviews: average,
     * count and the 1–5 star distribution.
     *
     * @return array<string, mixed>
     */
    public function summaryFor(string $tenantId, string $courseId): array;
}
