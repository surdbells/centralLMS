<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Enrollment\Enrollment;
use Central\Api\Domain\Enrollment\EnrollmentRepository;
use Central\Api\Domain\Enrollment\LessonProgress;
use Central\Api\Domain\Enrollment\LessonProgressRepository;
use Central\Api\Domain\Enrollment\ProgressStatus;
use Doctrine\DBAL\Connection;

final class DbalLessonProgressRepository implements LessonProgressRepository
{
    private const COLUMNS = 'id, tenant_id, enrollment_id, user_id, lesson_id, status,
                             progress_percentage, score, time_spent_seconds,
                             last_position_seconds, completed_at, last_activity_at, created_at';

    /**
     * What "done" is measured against.
     *
     * Mandatory, active, undeleted lessons — an optional lesson should not
     * hold a learner's completion hostage, and a deactivated one should stop
     * counting the moment it is switched off rather than stranding everyone at
     * 90% forever.
     *
     * The COALESCE handles a course whose lessons are all optional: falling
     * back to every active lesson keeps the denominator meaningful instead of
     * dividing by zero and calling it 100%.
     */
    private const DENOMINATOR = "
        SELECT COALESCE(
            NULLIF(COUNT(*) FILTER (WHERE l.is_mandatory), 0),
            COUNT(*)
        )
        FROM lessons l
        WHERE l.course_id = {a}.course_id
          AND l.is_active
          AND l.deleted_at IS NULL
    ";

    private const NUMERATOR = "
        SELECT COUNT(*)
        FROM lesson_progress lp
        JOIN lessons l ON l.id = lp.lesson_id
        WHERE lp.enrollment_id = {a}.id
          AND lp.status = 'completed'
          AND l.is_active
          AND l.deleted_at IS NULL
          AND (l.is_mandatory OR NOT EXISTS (
              SELECT 1 FROM lessons m
              WHERE m.course_id = {a}.course_id AND m.is_mandatory
                AND m.is_active AND m.deleted_at IS NULL
          ))
    ";

    public function __construct(
        private readonly Connection $connection,
        private readonly EnrollmentRepository $enrollments,
    ) {
    }

    public function forEnrollment(string $tenantId, string $enrollmentId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s FROM lesson_progress
                 WHERE enrollment_id = :enrollment AND tenant_id = :tenant',
                self::COLUMNS,
            ),
            ['enrollment' => $enrollmentId, 'tenant' => $tenantId],
        );

        $byLesson = [];

        foreach ($rows as $row) {
            $byLesson[(string) $row['lesson_id']] = $this->hydrate($row);
        }

        return $byLesson;
    }

    public function find(string $tenantId, string $enrollmentId, string $lessonId): ?LessonProgress
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM lesson_progress
                 WHERE enrollment_id = :enrollment AND lesson_id = :lesson AND tenant_id = :tenant',
                self::COLUMNS,
            ),
            ['enrollment' => $enrollmentId, 'lesson' => $lessonId, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function record(string $tenantId, Enrollment $enrollment, string $lessonId, array $report): array
    {
        /** @var array{progress: LessonProgress, enrollment: Enrollment} $result */
        $result = $this->connection->transactional(
            function (Connection $tx) use ($tenantId, $enrollment, $lessonId, $report): array {
                // One upsert, so a double-click cannot create two rows that
                // then disagree about whether the lesson is done.
                $tx->executeStatement(
                    "INSERT INTO lesson_progress
                         (tenant_id, enrollment_id, user_id, lesson_id, status,
                          progress_percentage, score, time_spent_seconds,
                          last_position_seconds, completed_at, last_activity_at)
                     VALUES
                         (:tenant, :enrollment, :user, :lesson, :status,
                          :percentage, :score, :seconds,
                          COALESCE(:position, 0),
                          CASE WHEN :status2 = 'completed' THEN NOW() END,
                          NOW())
                     ON CONFLICT (enrollment_id, lesson_id) DO UPDATE SET
                         -- Progress never goes backwards: a player reporting
                         -- 30% after the learner reached 80% is reporting a
                         -- re-watch, not a regression.
                         progress_percentage = GREATEST(
                             lesson_progress.progress_percentage,
                             EXCLUDED.progress_percentage
                         ),
                         status = CASE
                             WHEN GREATEST(lesson_progress.progress_percentage,
                                           EXCLUDED.progress_percentage) >= 100 THEN 'completed'
                             WHEN GREATEST(lesson_progress.progress_percentage,
                                           EXCLUDED.progress_percentage) > 0 THEN 'in_progress'
                             ELSE 'not_started'
                         END,
                         completed_at = CASE
                             WHEN GREATEST(lesson_progress.progress_percentage,
                                           EXCLUDED.progress_percentage) >= 100
                                 -- Kept from the first completion: when they
                                 -- finished it is a fact, and a re-watch does
                                 -- not move it.
                                 THEN COALESCE(lesson_progress.completed_at, NOW())
                             ELSE NULL
                         END,
                         -- The best score stands. Re-taking a quiz badly should
                         -- not erase a pass that a certificate already cites.
                         score = CASE
                             WHEN EXCLUDED.score IS NULL THEN lesson_progress.score
                             ELSE GREATEST(COALESCE(lesson_progress.score, 0), EXCLUDED.score)
                         END,
                         -- Time accumulates; position is a current fact.
                         time_spent_seconds = lesson_progress.time_spent_seconds
                                              + EXCLUDED.time_spent_seconds,
                         last_position_seconds = CASE
                             WHEN :positionGiven THEN EXCLUDED.last_position_seconds
                             ELSE lesson_progress.last_position_seconds
                         END,
                         last_activity_at = NOW(),
                         updated_at = NOW()",
                    [
                        'tenant'        => $tenantId,
                        'enrollment'    => $enrollment->id,
                        'user'          => $enrollment->userId,
                        'lesson'        => $lessonId,
                        'status'        => $report['status']->value,
                        'status2'       => $report['status']->value,
                        'percentage'    => $report['percentage'],
                        'score'         => $report['score'],
                        'seconds'       => $report['secondsToAdd'],
                        'position'      => $report['position'],
                        'positionGiven' => $report['position'] !== null ? 'true' : 'false',
                    ],
                );

                // Same transaction. Updating the enrolment separately is how
                // the legacy `is_completed` flag drifted from `status`.
                $updated = $this->recalculateWithin($tx, $tenantId, $enrollment->id, $lessonId);

                $progress = $this->find($tenantId, $enrollment->id, $lessonId);

                if ($progress === null || $updated === null) {
                    throw new \RuntimeException('Progress could not be read back after recording.');
                }

                return ['progress' => $progress, 'enrollment' => $updated];
            },
        );

        return $result;
    }

    public function recalculate(string $tenantId, string $enrollmentId): ?Enrollment
    {
        return $this->recalculateWithin($this->connection, $tenantId, $enrollmentId, null);
    }

    public function recalculateCourse(string $tenantId, string $courseId): int
    {
        return (int) $this->connection->executeStatement(
            sprintf(
                "UPDATE enrollments AS e
                 SET progress_percentage = calc.percentage,
                     status = CASE
                         WHEN e.status = 'dropped' THEN 'dropped'
                         WHEN calc.percentage >= 100 THEN 'completed'
                         WHEN calc.percentage > 0 THEN 'in_progress'
                         ELSE 'not_started'
                     END,
                     started_at = CASE
                         WHEN calc.percentage > 0 THEN COALESCE(e.started_at, NOW())
                         ELSE e.started_at
                     END,
                     completed_at = CASE
                         WHEN e.status <> 'dropped' AND calc.percentage >= 100
                             THEN COALESCE(e.completed_at, NOW())
                         ELSE NULL
                     END,
                     time_spent_seconds = calc.seconds,
                     updated_at = NOW()
                 FROM (
                     SELECT e2.id,
                            %s AS percentage,
                            COALESCE((
                                SELECT SUM(lp.time_spent_seconds)
                                FROM lesson_progress lp WHERE lp.enrollment_id = e2.id
                            ), 0) AS seconds
                     FROM enrollments e2
                     WHERE e2.course_id = :course AND e2.tenant_id = :tenant AND e2.deleted_at IS NULL
                 ) AS calc
                 WHERE e.id = calc.id",
                $this->percentageExpression('e2'),
            ),
            ['course' => $courseId, 'tenant' => $tenantId],
        );
    }

    // --- internals --------------------------------------------------------

    /**
     * The completion percentage, correlated to whatever the enrolments table
     * is aliased as in the surrounding statement.
     *
     * `$alias` is chosen by this class from two literals, never from input.
     */
    private function percentageExpression(string $alias): string
    {
        return str_replace('{a}', $alias, sprintf(
            'LEAST(100, ROUND((%s)::numeric * 100 / GREATEST((%s), 1), 2))',
            self::NUMERATOR,
            self::DENOMINATOR,
        ));
    }

    /**
     * Recalculates one enrolment on the given connection.
     *
     * Takes the connection rather than using `$this->connection` so it can run
     * inside `record()`'s transaction — the whole point being that the lesson
     * row and the enrolment it feeds are never visible out of step.
     */
    private function recalculateWithin(
        Connection $connection,
        string $tenantId,
        string $enrollmentId,
        ?string $lastLessonId,
    ): ?Enrollment {
        $affected = $connection->executeStatement(
            sprintf(
                "UPDATE enrollments AS e
                 SET progress_percentage = sub.percentage,
                     status = CASE
                         -- A dropped learner stays dropped. Recording progress
                         -- does not put someone back on a course they were
                         -- taken off; only an assignment does that.
                         WHEN e.status = 'dropped' THEN 'dropped'
                         WHEN sub.percentage >= 100 THEN 'completed'
                         WHEN sub.percentage > 0 THEN 'in_progress'
                         ELSE 'not_started'
                     END,
                     started_at = CASE
                         WHEN sub.percentage > 0 THEN COALESCE(e.started_at, NOW())
                         ELSE e.started_at
                     END,
                     completed_at = CASE
                         WHEN e.status <> 'dropped' AND sub.percentage >= 100
                             THEN COALESCE(e.completed_at, NOW())
                         ELSE NULL
                     END,
                     time_spent_seconds = sub.seconds,
                     last_lesson_id = COALESCE(:lastLesson, e.last_lesson_id),
                     last_activity_at = NOW(),
                     updated_at = NOW()
                 FROM (
                     SELECT %s AS percentage,
                            COALESCE((
                                SELECT SUM(lp.time_spent_seconds)
                                FROM lesson_progress lp WHERE lp.enrollment_id = e2.id
                            ), 0) AS seconds
                     FROM enrollments e2
                     WHERE e2.id = :id
                 ) AS sub
                 WHERE e.id = :id AND e.tenant_id = :tenant AND e.deleted_at IS NULL",
                $this->percentageExpression('e2'),
            ),
            ['id' => $enrollmentId, 'tenant' => $tenantId, 'lastLesson' => $lastLessonId],
        );

        if ($affected === 0) {
            return null;
        }

        $this->logCompletion($connection, $tenantId, $enrollmentId);

        return $this->enrollments->find($tenantId, $enrollmentId);
    }

    /**
     * Writes a `completed` event the first time an enrolment finishes.
     *
     * Guarded by "no completed event yet", so re-watching a lesson on a
     * finished course does not append a second completion to the audit trail.
     */
    private function logCompletion(Connection $connection, string $tenantId, string $enrollmentId): void
    {
        $connection->executeStatement(
            "INSERT INTO enrollment_events (tenant_id, course_id, user_id, action, source)
             SELECT e.tenant_id, e.course_id, e.user_id, 'completed', e.source
             FROM enrollments e
             WHERE e.id = :id AND e.tenant_id = :tenant AND e.status = 'completed'
               AND NOT EXISTS (
                   SELECT 1 FROM enrollment_events ev
                   WHERE ev.course_id = e.course_id AND ev.user_id = e.user_id
                     AND ev.action = 'completed'
               )",
            ['id' => $enrollmentId, 'tenant' => $tenantId],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): LessonProgress
    {
        $status = ProgressStatus::tryFrom((string) $row['status']);

        if ($status === null) {
            throw new \RuntimeException(sprintf('Unknown progress status "%s".', $row['status']));
        }

        return new LessonProgress(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            enrollmentId: (string) $row['enrollment_id'],
            userId: (string) $row['user_id'],
            lessonId: (string) $row['lesson_id'],
            status: $status,
            progressPercentage: (float) $row['progress_percentage'],
            score: isset($row['score']) ? (float) $row['score'] : null,
            timeSpentSeconds: (int) $row['time_spent_seconds'],
            lastPositionSeconds: (int) $row['last_position_seconds'],
            completedAt: $this->date($row['completed_at'] ?? null),
            lastActivityAt: $this->date($row['last_activity_at'] ?? null),
            createdAt: $this->date($row['created_at'] ?? null),
        );
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
