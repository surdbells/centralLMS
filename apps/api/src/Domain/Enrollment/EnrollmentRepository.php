<?php

declare(strict_types=1);

namespace Central\Api\Domain\Enrollment;

/**
 * Storage for enrolments.
 *
 * Every method takes a tenant id and none omits it.
 */
interface EnrollmentRepository
{
    /**
     * Who is on this course.
     *
     * @return array{items: list<Enrollment>, total: int}
     */
    public function forCourse(
        string $tenantId,
        string $courseId,
        int $page,
        int $perPage,
        ?EnrollmentStatus $status = null,
        ?string $search = null,
        bool $overdueOnly = false,
    ): array;

    /**
     * What this learner is on.
     *
     * @return array{items: list<Enrollment>, total: int}
     */
    public function forUser(
        string $tenantId,
        string $userId,
        int $page,
        int $perPage,
        ?EnrollmentStatus $status = null,
    ): array;

    public function find(string $tenantId, string $id): ?Enrollment;

    public function findForUserAndCourse(string $tenantId, string $userId, string $courseId): ?Enrollment;

    /**
     * Enrols everyone the request names who is not already on the course.
     *
     * One call rather than one per learner: the legacy version issued a query
     * per user inside a loop, so assigning a 200-person branch took 200 round
     * trips and could half-succeed. Already-enrolled learners are skipped
     * rather than failing the batch, and previously dropped ones are
     * reinstated with their progress intact.
     *
     * @return array{enrolled: list<string>, reinstated: list<string>, alreadyEnrolled: list<string>, notFound: list<string>}
     */
    public function assign(
        string $tenantId,
        string $courseId,
        AssignmentRequest $request,
        ?string $actorId,
    ): array;

    /**
     * Takes learners off a course.
     *
     * Moves them to `dropped` rather than deleting: their progress survives,
     * so reinstating them does not start them at zero, and the completion
     * history stays auditable.
     *
     * @param list<string> $userIds
     *
     * @return list<string> the users actually removed
     */
    public function unenroll(
        string $tenantId,
        string $courseId,
        array $userIds,
        ?string $actorId,
        ?string $reason = null,
    ): array;

    /**
     * Counts for the course dashboard.
     *
     * One grouped query rather than one count per status.
     *
     * @return array{
     *     total: int, notStarted: int, inProgress: int, completed: int,
     *     dropped: int, overdue: int, averageProgress: float
     * }
     */
    public function statsForCourse(string $tenantId, string $courseId): array;

    /**
     * The audit trail for one learner on one course, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function history(string $tenantId, string $courseId, string $userId): array;
}
