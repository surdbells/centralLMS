<?php

declare(strict_types=1);

namespace Central\Api\Domain\Feedback;

interface BehaviourFeedbackRepository
{
    /**
     * A manager's worklist: each direct report's completed courses, with the
     * manager's existing behaviour-change rating where they have left one.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingFor(string $tenantId, string $managerId): array;

    /** Whether this person is a direct report of this manager. */
    public function isReport(string $tenantId, string $managerId, string $userId): bool;

    /**
     * Records (or updates) a manager's behaviour-change rating for a report on
     * one course. One standing rating per (manager, report, course).
     */
    public function saveForCourse(
        string $tenantId,
        string $managerId,
        string $userId,
        string $courseId,
        int $rating,
        ?string $comments,
    ): void;

    /**
     * The tenant-wide behaviour-change picture: average rating, count and the
     * 1–5 distribution — for the admin Analytics "business impact" section.
     *
     * @return array{average: float, count: int, distribution: array<int, int>}
     */
    public function summary(string $tenantId): array;
}
