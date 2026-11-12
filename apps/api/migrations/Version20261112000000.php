<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backfill: an opened course is a started course.
 *
 * Until now an enrolment's status was derived only from its percentage, and
 * the percentage counts only COMPLETED mandatory lessons. A learner halfway
 * through their first video, or sitting in a SCORM package that reports
 * 'incomplete', therefore stayed `not_started` with no start date, and every
 * dashboard and My Learning list showed a course they had plainly opened as
 * not started. Lesson rows had the same problem one level down: a heartbeat
 * carrying time but no percentage left the lesson `not_started`.
 *
 * The progress recorder now treats any activity as a start. This brings the
 * rows written under the old rule into line with it:
 *
 *  - a lesson row with time on it or a playback position is `in_progress`;
 *  - an enrolment with any lesson row is `in_progress`, started when its first
 *    lesson row was created (the earliest evidence we have), not "now".
 *
 * Completed and dropped rows are not touched: only `not_started` moves.
 */
final class Version20261112000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill: enrolments and lesson rows with activity are in progress, not not-started';
    }

    public function isTransactional(): bool
    {
        // Both updates or neither: half a backfill would leave enrolments
        // and their lesson rows disagreeing about whether anything began.
        return true;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration targets PostgreSQL.',
        );

        // Lessons first. completed_at stays NULL, which the
        // lesson_progress_completion_consistent check requires of anything
        // that is not completed.
        $this->addSql(<<<'SQL'
            UPDATE lesson_progress
            SET status = 'in_progress',
                updated_at = NOW()
            WHERE status = 'not_started'
              AND (time_spent_seconds > 0 OR last_position_seconds > 0)
        SQL);

        // Then enrolments. started_at must be set in the same statement: the
        // enrollments_started_consistent check rejects an in-progress row
        // without one.
        $this->addSql(<<<'SQL'
            UPDATE enrollments e
            SET status = 'in_progress',
                started_at = COALESCE(
                    e.started_at,
                    (SELECT MIN(lp.created_at) FROM lesson_progress lp WHERE lp.enrollment_id = e.id),
                    NOW()
                ),
                updated_at = NOW()
            WHERE e.status = 'not_started'
              AND e.deleted_at IS NULL
              AND EXISTS (SELECT 1 FROM lesson_progress lp WHERE lp.enrollment_id = e.id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Irreversible by design, and deliberately a no-op rather than a
        // throw: which rows were `not_started` before is not recorded, and
        // putting opened courses back to "not started" would only reintroduce
        // the bug. Rolling back past this migration leaves the data as it is,
        // which the old code reads without complaint.
    }
}
