<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Enrolments and progress.
 *
 * Ported from `course_enrollments`, `lesson_progress`, `module_progress` and
 * `enrollment_logs`. As with the catalogue, the duplication is resolved from
 * what the live data actually says rather than from preference:
 *
 *  - **`is_started` and `is_completed` are dropped.** The legacy table carried
 *    `status`, `started_at`, `completed_at` *and* two booleans for the same
 *    facts. In the live data 16 of the 159 enrolments have
 *    `status = 'completed'` with `is_completed = 0` — every completed row
 *    disagrees with its own flag. `status` is the one representation kept, and
 *    a check constraint ties `completed_at` to it so they cannot drift again.
 *  - **`last_accessed_at` is dropped**, duplicating `last_activity_at`. Both
 *    were auto-updated and hold identical values in every row.
 *  - **`time_spent_minutes` is dropped** from lesson progress. It is 0 in all
 *    116 rows while `time_spent_seconds` is populated in all 116.
 *  - **`last_position` is dropped**, duplicating `last_position_seconds`. The
 *    comment on the latter ("For video/audio resume") says what both meant.
 *  - **`started_at` is dropped** from lesson progress: NULL in all 116 rows
 *    including the completed ones. `created_at` is when the learner first
 *    touched the lesson, which is the fact it was reaching for.
 *  - **`attempts` is dropped** from both tables — NULL everywhere. Assessment
 *    attempts belong to the assessments module, which records them properly.
 *  - **`module_progress` is not ported at all.** Every column in it is
 *    derivable from the lesson progress underneath: percentage, status and
 *    completion date are all `COUNT`s over `lesson_progress`. Storing them is
 *    the same mistake as `is_completed`, one level up. The API computes them.
 *
 * `enrollment_source` had four spellings across two conventions
 * ('Bulk Assignment', 'Team Assignment', 'Branch Assignment',
 * 'self_enrollment'). It becomes a constrained `source` column.
 *
 * This migration also restores the five organisational columns on `users`.
 * The legacy table had `branch`, `department`, `team`, `level` and
 * `job_title`; the identity slice dropped them, and without them the headline
 * feature here — assigning a course to a whole department, team or branch —
 * has nothing to resolve. They come back as composite foreign keys into
 * `lookups`, so a user's "department" cannot point at a job title.
 */
final class Version20260826120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enrolments, lesson progress and the enrolment audit trail; org columns on users.';
    }

    public function up(Schema $schema): void
    {
        $this->organisationalColumnsOnUsers();
        $this->tenantScopedKeys();
        $this->enrollments();
        $this->lessonProgress();
        $this->enrollmentEvents();
    }

    /**
     * Makes `(id, tenant_id)` a key on the three tables enrolments point at.
     *
     * Without this, `enrollments.course_id` references `courses (id)` alone,
     * and nothing stops a row with one tenant's `tenant_id` and another
     * tenant's `course_id`. The HTTP layer resolves the course within the
     * caller's tenant first, so it cannot happen through the API — but
     * "unreachable through today's actions" is not the same as
     * "unrepresentable", and it is the second one that survives the next
     * import script.
     */
    private function tenantScopedKeys(): void
    {
        $this->addSql('ALTER TABLE courses ADD CONSTRAINT courses_id_tenant_uniq UNIQUE (id, tenant_id)');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT users_id_tenant_uniq UNIQUE (id, tenant_id)');
        $this->addSql('ALTER TABLE lessons ADD CONSTRAINT lessons_id_tenant_uniq UNIQUE (id, tenant_id)');
    }

    /**
     * Each column is a pair: the id, and a generated column fixed at the kind
     * it must be. The composite foreign key into `lookups (id, kind)` then
     * makes "this user's department is actually a branch" unrepresentable.
     *
     * Same shape as `courses.category_id`, and `NO ACTION` for the same
     * reason: PostgreSQL cannot apply `SET NULL` to a key containing a
     * generated column.
     */
    private function organisationalColumnsOnUsers(): void
    {
        foreach (['branch', 'department', 'team', 'level', 'job_title'] as $kind) {
            $this->addSql(sprintf('ALTER TABLE users ADD COLUMN %s_id UUID', $kind));
            $this->addSql(sprintf(
                "ALTER TABLE users ADD COLUMN %s_kind TEXT GENERATED ALWAYS AS ('%s') STORED",
                $kind,
                $kind,
            ));
            $this->addSql(sprintf(
                'ALTER TABLE users ADD CONSTRAINT users_%s_fk
                    FOREIGN KEY (%s_id, %s_kind) REFERENCES lookups (id, kind)',
                $kind,
                $kind,
                $kind,
            ));
            $this->addSql(sprintf(
                'CREATE INDEX users_%s_idx ON users (%s_id) WHERE %s_id IS NOT NULL AND deleted_at IS NULL',
                $kind,
                $kind,
                $kind,
            ));
        }
    }

    private function enrollments(): void
    {
        $this->addSql("
            CREATE TABLE enrollments (
                id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id           BIGINT,
                tenant_id           UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                course_id           UUID NOT NULL,
                user_id             UUID NOT NULL,

                status              TEXT NOT NULL DEFAULT 'not_started',
                source              TEXT NOT NULL DEFAULT 'direct',

                -- Who assigned it. NULL for self-enrolment, and kept if that
                -- admin later leaves: ON DELETE SET NULL, not CASCADE, or
                -- removing an administrator would erase the record of every
                -- assignment they ever made.
                assigned_by         UUID REFERENCES users (id) ON DELETE SET NULL,

                is_mandatory        BOOLEAN NOT NULL DEFAULT TRUE,
                due_date            DATE,

                -- Maintained only by the progress recorder, inside the same
                -- transaction as the lesson_progress write it derives from.
                -- Nothing else may write it; see the check constraints below.
                progress_percentage NUMERIC(5,2) NOT NULL DEFAULT 0,
                final_score         NUMERIC(5,2),
                time_spent_seconds  INTEGER NOT NULL DEFAULT 0,

                -- Where to drop the learner back in.
                last_lesson_id      UUID REFERENCES lessons (id) ON DELETE SET NULL,

                started_at          TIMESTAMPTZ,
                completed_at        TIMESTAMPTZ,
                last_activity_at    TIMESTAMPTZ,
                enrolled_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMPTZ,
                deleted_at          TIMESTAMPTZ,

                -- The course and the learner must both belong to the same
                -- tenant as the enrolment. Composite rather than simple
                -- foreign keys, so a cross-tenant row cannot be written.
                CONSTRAINT enrollments_course_fk
                    FOREIGN KEY (course_id, tenant_id) REFERENCES courses (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT enrollments_user_fk
                    FOREIGN KEY (user_id, tenant_id) REFERENCES users (id, tenant_id) ON DELETE CASCADE,

                CONSTRAINT enrollments_status_check
                    CHECK (status IN ('not_started', 'in_progress', 'completed', 'dropped')),
                CONSTRAINT enrollments_source_check
                    CHECK (source IN ('self', 'direct', 'bulk', 'team', 'branch', 'department')),
                CONSTRAINT enrollments_percentage_check
                    CHECK (progress_percentage BETWEEN 0 AND 100),
                CONSTRAINT enrollments_score_check
                    CHECK (final_score IS NULL OR final_score BETWEEN 0 AND 100),
                CONSTRAINT enrollments_time_check
                    CHECK (time_spent_seconds >= 0),

                -- The constraint the legacy booleans failed to be. A completed
                -- enrolment has a completion date and full progress; anything
                -- else does not have a completion date at all.
                CONSTRAINT enrollments_completion_consistent CHECK (
                    (status = 'completed' AND completed_at IS NOT NULL AND progress_percentage = 100)
                    OR (status <> 'completed' AND completed_at IS NULL)
                ),

                -- Progress implies a start. Dropped is excluded on purpose:
                -- someone can be taken off a course they never opened, and
                -- requiring a start date for them would make unenrolling them
                -- impossible.
                CONSTRAINT enrollments_started_consistent CHECK (
                    status NOT IN ('in_progress', 'completed') OR started_at IS NOT NULL
                )
            )
        ");

        // One live enrolment per learner per course. Unenrolling moves the row
        // to 'dropped' rather than deleting it, so re-enrolling the same person
        // restores their progress instead of starting a second row beside it.
        $this->addSql('
            CREATE UNIQUE INDEX enrollments_course_user_uniq
                ON enrollments (course_id, user_id)
                WHERE deleted_at IS NULL
        ');

        $this->addSql('CREATE UNIQUE INDEX enrollments_legacy_uniq ON enrollments (legacy_id) WHERE legacy_id IS NOT NULL');

        // The learner's own list: "my courses", newest activity first.
        $this->addSql('
            CREATE INDEX enrollments_user_idx
                ON enrollments (tenant_id, user_id, status)
                WHERE deleted_at IS NULL
        ');

        // The administrator\'s list: who is on this course.
        $this->addSql('
            CREATE INDEX enrollments_course_idx
                ON enrollments (course_id, status)
                WHERE deleted_at IS NULL
        ');

        // Overdue sweeps and the reminder job.
        $this->addSql("
            CREATE INDEX enrollments_due_idx
                ON enrollments (tenant_id, due_date)
                WHERE deleted_at IS NULL AND due_date IS NOT NULL AND status <> 'completed'
        ");

        // Referenced by lesson_progress, so a progress row cannot claim an
        // enrolment belongs to a different learner than it does.
        $this->addSql('ALTER TABLE enrollments ADD CONSTRAINT enrollments_id_user_uniq UNIQUE (id, user_id)');
        $this->addSql('ALTER TABLE enrollments ADD CONSTRAINT enrollments_id_course_uniq UNIQUE (id, course_id)');
    }

    private function lessonProgress(): void
    {
        $this->addSql("
            CREATE TABLE lesson_progress (
                id                    UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id             BIGINT,
                tenant_id             UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,

                enrollment_id         UUID NOT NULL,
                user_id               UUID NOT NULL,
                lesson_id             UUID NOT NULL,

                status                TEXT NOT NULL DEFAULT 'not_started',
                progress_percentage   NUMERIC(5,2) NOT NULL DEFAULT 0,
                score                 NUMERIC(5,2),
                time_spent_seconds    INTEGER NOT NULL DEFAULT 0,

                -- Video and audio resume position.
                last_position_seconds INTEGER NOT NULL DEFAULT 0,

                completed_at          TIMESTAMPTZ,
                last_activity_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMPTZ,

                CONSTRAINT lesson_progress_lesson_fk
                    FOREIGN KEY (lesson_id, tenant_id) REFERENCES lessons (id, tenant_id) ON DELETE CASCADE,

                CONSTRAINT lesson_progress_status_check
                    CHECK (status IN ('not_started', 'in_progress', 'completed')),
                CONSTRAINT lesson_progress_percentage_check
                    CHECK (progress_percentage BETWEEN 0 AND 100),
                CONSTRAINT lesson_progress_score_check
                    CHECK (score IS NULL OR score BETWEEN 0 AND 100),
                CONSTRAINT lesson_progress_time_check
                    CHECK (time_spent_seconds >= 0 AND last_position_seconds >= 0),

                CONSTRAINT lesson_progress_completion_consistent CHECK (
                    (status = 'completed' AND completed_at IS NOT NULL AND progress_percentage = 100)
                    OR (status <> 'completed' AND completed_at IS NULL)
                ),

                -- The learner on the progress row is the learner on the
                -- enrolment, enforced rather than assumed.
                CONSTRAINT lesson_progress_enrollment_user_fk
                    FOREIGN KEY (enrollment_id, user_id)
                    REFERENCES enrollments (id, user_id) ON DELETE CASCADE
            )
        ");

        // One progress row per lesson per enrolment. Recording progress is an
        // upsert onto this index, so a double-click cannot create two rows
        // that then disagree about whether the lesson is done.
        $this->addSql('
            CREATE UNIQUE INDEX lesson_progress_enrollment_lesson_uniq
                ON lesson_progress (enrollment_id, lesson_id)
        ');

        $this->addSql('
            CREATE UNIQUE INDEX lesson_progress_legacy_uniq
                ON lesson_progress (legacy_id) WHERE legacy_id IS NOT NULL
        ');

        // Recalculating an enrolment reads every row for it.
        $this->addSql('CREATE INDEX lesson_progress_enrollment_idx ON lesson_progress (enrollment_id, status)');

        // Completion reporting, per lesson across learners.
        $this->addSql('CREATE INDEX lesson_progress_lesson_idx ON lesson_progress (tenant_id, lesson_id, status)');
    }

    /**
     * The audit trail, from `enrollment_logs` (196 rows).
     *
     * Kept as an append-only record rather than folded into the enrolment row:
     * "when was this person taken off the course, by whom, and why" is a
     * question the current state cannot answer, and it is exactly the question
     * asked when a compliance deadline is missed.
     */
    private function enrollmentEvents(): void
    {
        $this->addSql("
            CREATE TABLE enrollment_events (
                id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id     BIGINT,
                tenant_id     UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,

                -- Not a foreign key to enrollments: the point of the log is to
                -- survive the row it describes.
                course_id     UUID NOT NULL,
                user_id       UUID NOT NULL,

                action        TEXT NOT NULL,
                source        TEXT,
                actor_id      UUID REFERENCES users (id) ON DELETE SET NULL,
                notes         TEXT,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT enrollment_events_course_fk
                    FOREIGN KEY (course_id, tenant_id) REFERENCES courses (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT enrollment_events_user_fk
                    FOREIGN KEY (user_id, tenant_id) REFERENCES users (id, tenant_id) ON DELETE CASCADE,

                CONSTRAINT enrollment_events_action_check
                    CHECK (action IN ('enrolled', 'unenrolled', 'completed', 'reinstated', 'expired')),
                CONSTRAINT enrollment_events_source_check
                    CHECK (source IS NULL OR source IN ('self', 'direct', 'bulk', 'team', 'branch', 'department'))
            )
        ");

        $this->addSql('
            CREATE UNIQUE INDEX enrollment_events_legacy_uniq
                ON enrollment_events (legacy_id) WHERE legacy_id IS NOT NULL
        ');

        $this->addSql('
            CREATE INDEX enrollment_events_course_user_idx
                ON enrollment_events (tenant_id, course_id, user_id, created_at DESC)
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS enrollment_events');
        $this->addSql('DROP TABLE IF EXISTS lesson_progress');
        $this->addSql('DROP TABLE IF EXISTS enrollments');

        $this->addSql('ALTER TABLE lessons DROP CONSTRAINT IF EXISTS lessons_id_tenant_uniq');
        $this->addSql('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_id_tenant_uniq');
        $this->addSql('ALTER TABLE courses DROP CONSTRAINT IF EXISTS courses_id_tenant_uniq');

        foreach (['branch', 'department', 'team', 'level', 'job_title'] as $kind) {
            $this->addSql(sprintf('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_%s_fk', $kind));
            $this->addSql(sprintf('DROP INDEX IF EXISTS users_%s_idx', $kind));
            $this->addSql(sprintf('ALTER TABLE users DROP COLUMN IF EXISTS %s_kind', $kind));
            $this->addSql(sprintf('ALTER TABLE users DROP COLUMN IF EXISTS %s_id', $kind));
        }
    }
}
