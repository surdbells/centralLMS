<?php

declare(strict_types=1);

namespace Central\Api\Domain\Enrollment;

/**
 * Storage for lesson progress, and the place enrolment progress is derived.
 */
interface LessonProgressRepository
{
    /**
     * Every progress row for one enrolment, keyed by lesson id.
     *
     * @return array<string, LessonProgress>
     */
    public function forEnrollment(string $tenantId, string $enrollmentId): array;

    public function find(string $tenantId, string $enrollmentId, string $lessonId): ?LessonProgress;

    /**
     * Records a report from the player and recalculates the enrolment.
     *
     * Both happen in one transaction. If the enrolment percentage were updated
     * separately it would drift from the lesson rows exactly as the legacy
     * `is_completed` flag drifted from `status` — 16 of 159 rows disagreeing.
     *
     * Progress never goes backwards: a player that reports 30% after the
     * learner already reached 80% is reporting a re-watch, not a regression.
     * Time and position do move, because those are current facts.
     *
     * @param array{
     *     percentage: float, status: ProgressStatus, score: ?float,
     *     secondsToAdd: int, position: ?int
     * } $report
     *
     * @return array{progress: LessonProgress, enrollment: Enrollment}
     */
    public function record(
        string $tenantId,
        Enrollment $enrollment,
        string $lessonId,
        array $report,
    ): array;

    /**
     * Recalculates one enrolment from its lesson rows.
     *
     * Exposed for the cases where the denominator changes without any progress
     * being reported — a lesson added, deactivated or deleted moves everyone's
     * percentage on that course.
     */
    public function recalculate(string $tenantId, string $enrollmentId): ?Enrollment;

    /**
     * Recalculates every live enrolment on a course.
     *
     * @return int the number of enrolments updated
     */
    public function recalculateCourse(string $tenantId, string $courseId): int;
}
