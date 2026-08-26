<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The unified assessment core.
 *
 * One set of tables serves quizzes inside courses, standalone exams and — once
 * the extension lands — proctored assessments. `proctoring_level` lives on
 * `assessments` with a default of `none`, so proctoring is a setting on the
 * same row rather than a parallel family of tables.
 *
 * **A correction to `docs/migration/schema-assessments.md`.** That document
 * called the proctored side "the actively developed one" and planned the merge
 * around `proc_*` as the source of truth. That was a count of *files*, not of
 * rows. Every one of the fourteen `proc_*` tables is empty: no proctored
 * assessment has ever been created. All the live data is in the generic
 * tables — 19 assessments, 21 questions, 72 options, 14 attempts and 40
 * answers. The `proc_*` *schema* is still the better shape and is what this
 * migration follows, but the data migration is only from the generic side,
 * which makes it far smaller than that document feared.
 *
 * The document also worried that answers might live in two places and need
 * reconciling. They do not, and the answer is now settled:
 *
 *  - `user_answers` — 0 rows.
 *  - `proc_attempt_answers` — 0 rows.
 *  - `assessment_attempts.answers`, a `longtext` "Stores all answers and
 *    results as JSON" — NULL in 8 of 14 rows and the string `'0'` in the other
 *    6. It has never held an answer.
 *  - **`question_answers` — 40 rows.** This is the real store, and the one
 *    table the document did not mention.
 *
 * Dropped, on the same evidence-first basis as the enrolments port:
 *
 *  - **`total_questions` is dropped.** It disagrees with reality in 16 of the
 *    19 live assessments — rows claim 10, 20 or 25 questions when only 21
 *    questions exist in the entire database. A count is a query.
 *  - **`total_points`, `total_attempts`, `total_completions`, `avg_score` and
 *    `pass_rate` are dropped** for the same reason: all five are aggregates of
 *    rows this schema already holds.
 *  - **`answers` (the longtext blob) is dropped** — see above.
 *  - **`has_time_limit` is dropped.** `time_limit_minutes IS NULL` already
 *    means "no limit"; its own column comment says so.
 *  - **`is_active` is dropped** from assessments, duplicating `status`.
 *
 * Kept deliberately: **`attempts.passed`**. It looks like the same mistake as
 * the enrolments `is_completed` flag, and it is not. `is_completed` duplicated
 * `status` — both described the same instant, so recomputing always agreed.
 * `passed` depends on the assessment's pass mark, which an author can change
 * afterwards; recomputing it later would silently turn last year's pass into a
 * fail. It is stamped once, when the attempt is graded.
 *
 * Not built here: `assessment_sections` and `assessment_random_rules`. Both
 * are empty in the legacy data and have no generic equivalent, so there is
 * nothing to port and no caller to serve. `question_selection` is constrained
 * to `manual` until the rules table exists, rather than accepting a value the
 * engine cannot honour.
 */
final class Version20260826170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The unified assessment core: banks, questions, assessments, attempts and answers.';
    }

    public function up(Schema $schema): void
    {
        $this->questionBanks();
        $this->questions();
        $this->questionOptions();
        $this->assessments();
        $this->assessmentQuestions();
        $this->attempts();
        $this->attemptAnswers();
    }

    /**
     * The reuse win. Legacy questions were bound to one assessment by
     * `questions.assessment_id`, so the same question had to be retyped for
     * every quiz that wanted it. Here a question belongs to a bank and an
     * assessment draws from banks.
     */
    private function questionBanks(): void
    {
        $this->addSql('
            CREATE TABLE question_banks (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id   BIGINT,
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                name        TEXT NOT NULL,
                description TEXT,

                -- Optional grouping, reusing the shared lookup table rather
                -- than a private category list.
                category_id   UUID,
                category_kind TEXT GENERATED ALWAYS AS (\'category\') STORED,

                is_active   BOOLEAN NOT NULL DEFAULT TRUE,
                created_by  UUID REFERENCES users (id) ON DELETE SET NULL,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMPTZ,
                deleted_at  TIMESTAMPTZ,

                CONSTRAINT question_banks_name_not_blank CHECK (length(btrim(name)) > 0),
                CONSTRAINT question_banks_category_fk
                    FOREIGN KEY (category_id, category_kind) REFERENCES lookups (id, kind),
                CONSTRAINT question_banks_id_tenant_uniq UNIQUE (id, tenant_id)
            )
        ');

        $this->addSql('
            CREATE UNIQUE INDEX question_banks_tenant_name_uniq
                ON question_banks (tenant_id, lower(name)) WHERE deleted_at IS NULL
        ');
        $this->addSql('
            CREATE UNIQUE INDEX question_banks_legacy_uniq
                ON question_banks (legacy_id) WHERE legacy_id IS NOT NULL
        ');
    }

    private function questions(): void
    {
        $this->addSql("
            CREATE TABLE questions (
                id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id     BIGINT,
                tenant_id     UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                bank_id       UUID NOT NULL,

                type          TEXT NOT NULL,
                text          TEXT NOT NULL,

                -- Default worth. An assessment may override it per question;
                -- see assessment_questions.points_override.
                points        NUMERIC(6,2) NOT NULL DEFAULT 1,

                explanation   TEXT,
                hint          TEXT,
                media_url     TEXT,
                difficulty    TEXT,

                -- For numeric questions: the accepted answer and its tolerance.
                -- Held here rather than as a single option row because a
                -- numeric answer is a range, not a choice.
                numeric_answer    NUMERIC(18,6),
                numeric_tolerance NUMERIC(18,6),

                -- Short and long answers keep their model answer here; the
                -- grader compares against it, and a human sees it while marking.
                model_answer  TEXT,

                is_active     BOOLEAN NOT NULL DEFAULT TRUE,
                created_by    UUID REFERENCES users (id) ON DELETE SET NULL,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at    TIMESTAMPTZ,
                deleted_at    TIMESTAMPTZ,

                CONSTRAINT questions_text_not_blank CHECK (length(btrim(text)) > 0),
                CONSTRAINT questions_points_check CHECK (points >= 0),
                CONSTRAINT questions_difficulty_check
                    CHECK (difficulty IS NULL OR difficulty IN ('beginner', 'intermediate', 'professional')),
                CONSTRAINT questions_type_check CHECK (type IN (
                    'multiple_choice_single', 'multiple_choice_multiple', 'true_false',
                    'fill_in_blanks', 'short_answer', 'long_answer', 'matching',
                    'ordering', 'hotspot', 'numeric', 'file_upload'
                )),
                -- A tolerance without a value to apply it to is meaningless.
                CONSTRAINT questions_numeric_consistent
                    CHECK (numeric_tolerance IS NULL OR numeric_answer IS NOT NULL),

                CONSTRAINT questions_bank_fk
                    FOREIGN KEY (bank_id, tenant_id) REFERENCES question_banks (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT questions_id_tenant_uniq UNIQUE (id, tenant_id)
            )
        ");

        $this->addSql('CREATE INDEX questions_bank_idx ON questions (bank_id) WHERE deleted_at IS NULL AND is_active');
        $this->addSql('CREATE INDEX questions_type_idx ON questions (tenant_id, type) WHERE deleted_at IS NULL');
        $this->addSql('CREATE UNIQUE INDEX questions_legacy_uniq ON questions (legacy_id) WHERE legacy_id IS NOT NULL');
    }

    private function questionOptions(): void
    {
        $this->addSql('
            CREATE TABLE question_options (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id   BIGINT,
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                question_id UUID NOT NULL,

                text        TEXT NOT NULL,
                is_correct  BOOLEAN NOT NULL DEFAULT FALSE,
                feedback    TEXT,
                sort_order  INTEGER NOT NULL DEFAULT 0,

                -- Matching pairs and ordering both need a second value per
                -- row: the right-hand term to match, or the correct position.
                -- One nullable column rather than two tables, because both are
                -- "the other half of this option".
                match_text  TEXT,

                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMPTZ,

                CONSTRAINT question_options_text_not_blank CHECK (length(btrim(text)) > 0),
                CONSTRAINT question_options_question_fk
                    FOREIGN KEY (question_id, tenant_id) REFERENCES questions (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT question_options_id_question_uniq UNIQUE (id, question_id)
            )
        ');

        $this->addSql('CREATE INDEX question_options_question_idx ON question_options (question_id, sort_order)');
        $this->addSql('
            CREATE UNIQUE INDEX question_options_legacy_uniq
                ON question_options (legacy_id) WHERE legacy_id IS NOT NULL
        ');
    }

    private function assessments(): void
    {
        $this->addSql("
            CREATE TABLE assessments (
                id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id     BIGINT,
                tenant_id     UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,

                title         TEXT NOT NULL,
                description   TEXT,
                instructions  TEXT,
                kind          TEXT NOT NULL DEFAULT 'quiz',
                status        TEXT NOT NULL DEFAULT 'draft',
                difficulty    TEXT,

                -- Where it hangs. A course quiz names its course and usually
                -- its module; a standalone exam names neither. `live_training_id`
                -- is deliberately absent until the ILT tables exist — a column
                -- referencing nothing is how the legacy schema accumulated
                -- relationships enforced only by convention.
                course_id     UUID,
                module_id     UUID,

                category_id   UUID,
                category_kind TEXT GENERATED ALWAYS AS ('category') STORED,

                -- NULL means no limit; that is why there is no has_time_limit.
                time_limit_minutes  INTEGER,
                available_from      TIMESTAMPTZ,
                available_until     TIMESTAMPTZ,
                grace_period_minutes INTEGER NOT NULL DEFAULT 0,

                -- NULL means unlimited. The legacy column used 0 for that in
                -- one table and NULL in another; one meaning, one spelling.
                max_attempts        INTEGER,
                attempts_gap_hours  INTEGER NOT NULL DEFAULT 0,

                passing_score NUMERIC(5,2) NOT NULL DEFAULT 70,
                passing_type  TEXT NOT NULL DEFAULT 'percentage',

                question_selection TEXT NOT NULL DEFAULT 'manual',
                shuffle_questions  BOOLEAN NOT NULL DEFAULT FALSE,
                shuffle_options    BOOLEAN NOT NULL DEFAULT FALSE,

                reveal_answers       TEXT NOT NULL DEFAULT 'after_all_attempts',
                show_score_immediately BOOLEAN NOT NULL DEFAULT TRUE,
                show_explanations    BOOLEAN NOT NULL DEFAULT TRUE,
                allow_backward_navigation BOOLEAN NOT NULL DEFAULT TRUE,

                -- Set when the assessment holds a question no machine can
                -- mark. Derived on save from the questions attached to it.
                requires_manual_grading BOOLEAN NOT NULL DEFAULT FALSE,

                proctoring_level TEXT NOT NULL DEFAULT 'none',

                created_by    UUID REFERENCES users (id) ON DELETE SET NULL,
                published_at  TIMESTAMPTZ,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at    TIMESTAMPTZ,
                deleted_at    TIMESTAMPTZ,

                CONSTRAINT assessments_title_not_blank CHECK (length(btrim(title)) > 0),
                CONSTRAINT assessments_kind_check CHECK (kind IN ('quiz', 'exam', 'assignment', 'survey')),
                CONSTRAINT assessments_status_check
                    CHECK (status IN ('draft', 'published', 'closed', 'archived')),
                CONSTRAINT assessments_difficulty_check
                    CHECK (difficulty IS NULL OR difficulty IN ('beginner', 'intermediate', 'professional')),
                CONSTRAINT assessments_passing_type_check CHECK (passing_type IN ('percentage', 'points')),
                CONSTRAINT assessments_reveal_check
                    CHECK (reveal_answers IN ('never', 'immediately', 'after_deadline', 'after_all_attempts')),
                CONSTRAINT assessments_proctoring_check
                    CHECK (proctoring_level IN ('none', 'basic', 'standard', 'advanced')),

                -- Random and pool selection need assessment_random_rules,
                -- which is not built yet. Accepting the value would promise an
                -- engine that cannot deliver it.
                CONSTRAINT assessments_selection_check CHECK (question_selection IN ('manual')),

                CONSTRAINT assessments_time_limit_check
                    CHECK (time_limit_minutes IS NULL OR time_limit_minutes > 0),
                CONSTRAINT assessments_max_attempts_check
                    CHECK (max_attempts IS NULL OR max_attempts > 0),
                CONSTRAINT assessments_gap_check
                    CHECK (attempts_gap_hours >= 0 AND grace_period_minutes >= 0),
                CONSTRAINT assessments_passing_score_check CHECK (passing_score >= 0),
                CONSTRAINT assessments_window_check
                    CHECK (available_from IS NULL OR available_until IS NULL OR available_until >= available_from),

                -- A percentage pass mark above 100 can never be met.
                CONSTRAINT assessments_percentage_bound
                    CHECK (passing_type <> 'percentage' OR passing_score <= 100),

                -- A module quiz must name the course the module is in.
                CONSTRAINT assessments_module_needs_course
                    CHECK (module_id IS NULL OR course_id IS NOT NULL),

                CONSTRAINT assessments_course_fk
                    FOREIGN KEY (course_id, tenant_id) REFERENCES courses (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT assessments_module_fk
                    FOREIGN KEY (module_id, course_id) REFERENCES course_modules (id, course_id) ON DELETE CASCADE,
                CONSTRAINT assessments_category_fk
                    FOREIGN KEY (category_id, category_kind) REFERENCES lookups (id, kind),
                CONSTRAINT assessments_id_tenant_uniq UNIQUE (id, tenant_id)
            )
        ");

        $this->addSql('
            CREATE INDEX assessments_course_idx
                ON assessments (course_id) WHERE deleted_at IS NULL AND course_id IS NOT NULL
        ');
        $this->addSql("
            CREATE INDEX assessments_tenant_status_idx
                ON assessments (tenant_id, status) WHERE deleted_at IS NULL
        ");
        $this->addSql('
            CREATE UNIQUE INDEX assessments_legacy_uniq
                ON assessments (legacy_id) WHERE legacy_id IS NOT NULL
        ');
    }

    /**
     * Which questions an assessment asks, in what order and for how much.
     */
    private function assessmentQuestions(): void
    {
        $this->addSql('
            CREATE TABLE assessment_questions (
                id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id      UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                assessment_id  UUID NOT NULL,
                question_id    UUID NOT NULL,

                -- What this question is worth *here*. NULL falls back to the
                -- question\'s own value, so a bank question can be worth more
                -- in a final exam than in a practice quiz without being copied.
                points_override NUMERIC(6,2),

                sort_order     INTEGER NOT NULL DEFAULT 0,
                is_required    BOOLEAN NOT NULL DEFAULT TRUE,
                created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT assessment_questions_points_check
                    CHECK (points_override IS NULL OR points_override >= 0),
                CONSTRAINT assessment_questions_assessment_fk
                    FOREIGN KEY (assessment_id, tenant_id) REFERENCES assessments (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT assessment_questions_question_fk
                    FOREIGN KEY (question_id, tenant_id) REFERENCES questions (id, tenant_id) ON DELETE CASCADE
            )
        ');

        // A question appears once in an assessment. Asking the same question
        // twice is always a mistake, and it would double its weight silently.
        $this->addSql('
            CREATE UNIQUE INDEX assessment_questions_uniq
                ON assessment_questions (assessment_id, question_id)
        ');
        $this->addSql('
            CREATE INDEX assessment_questions_order_idx
                ON assessment_questions (assessment_id, sort_order)
        ');
    }

    private function attempts(): void
    {
        $this->addSql("
            CREATE TABLE attempts (
                id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id      BIGINT,
                tenant_id      UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                assessment_id  UUID NOT NULL,
                user_id        UUID NOT NULL,

                -- Stored rather than derived, so the unique index below can
                -- stop two concurrent starts becoming the same attempt.
                attempt_number INTEGER NOT NULL,

                status         TEXT NOT NULL DEFAULT 'in_progress',

                raw_score      NUMERIC(10,2),
                max_score      NUMERIC(10,2),
                percentage     NUMERIC(5,2),

                -- Stamped once at grading, never recomputed. The pass mark can
                -- be changed afterwards, and recalculating would turn a
                -- historical pass into a fail. See the class docblock.
                passed         BOOLEAN,

                time_spent_seconds INTEGER NOT NULL DEFAULT 0,

                -- When the clock runs out for this attempt: start + limit +
                -- grace, resolved once so a mid-attempt edit to the assessment
                -- cannot shorten a sitting already underway.
                expires_at     TIMESTAMPTZ,

                started_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                submitted_at   TIMESTAMPTZ,
                graded_at      TIMESTAMPTZ,
                graded_by      UUID REFERENCES users (id) ON DELETE SET NULL,
                created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at     TIMESTAMPTZ,

                CONSTRAINT attempts_status_check CHECK (status IN (
                    'not_started', 'in_progress', 'submitted',
                    'auto_submitted', 'grading', 'graded', 'abandoned'
                )),
                CONSTRAINT attempts_number_check CHECK (attempt_number > 0),
                CONSTRAINT attempts_time_check CHECK (time_spent_seconds >= 0),
                CONSTRAINT attempts_percentage_check
                    CHECK (percentage IS NULL OR percentage BETWEEN 0 AND 100),
                CONSTRAINT attempts_score_check
                    CHECK (raw_score IS NULL OR raw_score >= 0),

                -- A graded attempt has a result; an ungraded one has none.
                -- This is the constraint the legacy table lacked, where a row
                -- could sit at status 'completed' with a NULL score.
                CONSTRAINT attempts_graded_consistent CHECK (
                    (status = 'graded' AND graded_at IS NOT NULL AND passed IS NOT NULL
                        AND raw_score IS NOT NULL AND percentage IS NOT NULL)
                    OR (status <> 'graded' AND passed IS NULL)
                ),
                CONSTRAINT attempts_submitted_consistent CHECK (
                    status IN ('not_started', 'in_progress', 'abandoned') OR submitted_at IS NOT NULL
                ),

                CONSTRAINT attempts_assessment_fk
                    FOREIGN KEY (assessment_id, tenant_id) REFERENCES assessments (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT attempts_user_fk
                    FOREIGN KEY (user_id, tenant_id) REFERENCES users (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT attempts_id_user_uniq UNIQUE (id, user_id)
            )
        ");

        // Two clicks on Start must not produce two attempt 3s.
        $this->addSql('
            CREATE UNIQUE INDEX attempts_number_uniq
                ON attempts (assessment_id, user_id, attempt_number)
        ');

        // One open attempt per learner per assessment: resuming must find the
        // sitting they left, not begin another beside it.
        $this->addSql("
            CREATE UNIQUE INDEX attempts_one_open_uniq
                ON attempts (assessment_id, user_id)
                WHERE status = 'in_progress'
        ");

        $this->addSql('CREATE INDEX attempts_user_idx ON attempts (tenant_id, user_id, status)');
        $this->addSql('CREATE INDEX attempts_assessment_idx ON attempts (assessment_id, status)');
        $this->addSql('CREATE UNIQUE INDEX attempts_legacy_uniq ON attempts (legacy_id) WHERE legacy_id IS NOT NULL');
    }

    private function attemptAnswers(): void
    {
        $this->addSql('
            CREATE TABLE attempt_answers (
                id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id    BIGINT,
                tenant_id    UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                attempt_id   UUID NOT NULL,
                user_id      UUID NOT NULL,
                question_id  UUID NOT NULL,

                -- Every question type in one column. The legacy pair of
                -- `selected_option_id` and `answer_text` cannot express
                -- multiple selections, a set of matches or an ordering, which
                -- is why five of the eleven types had nowhere to be stored.
                answer       JSONB NOT NULL DEFAULT \'{}\'::jsonb,

                is_correct   BOOLEAN,
                points_earned NUMERIC(6,2),
                points_possible NUMERIC(6,2) NOT NULL DEFAULT 0,

                -- Set by a human marker for the types no machine can grade.
                feedback     TEXT,
                graded_by    UUID REFERENCES users (id) ON DELETE SET NULL,
                graded_at    TIMESTAMPTZ,

                time_spent_seconds INTEGER NOT NULL DEFAULT 0,
                answered_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at   TIMESTAMPTZ,

                CONSTRAINT attempt_answers_points_check
                    CHECK (points_earned IS NULL OR (points_earned >= 0 AND points_earned <= points_possible)),
                CONSTRAINT attempt_answers_time_check CHECK (time_spent_seconds >= 0),

                CONSTRAINT attempt_answers_attempt_fk
                    FOREIGN KEY (attempt_id, user_id) REFERENCES attempts (id, user_id) ON DELETE CASCADE,
                CONSTRAINT attempt_answers_question_fk
                    FOREIGN KEY (question_id, tenant_id) REFERENCES questions (id, tenant_id) ON DELETE CASCADE
            )
        ');

        // One answer per question per attempt. Saving an answer is an upsert
        // onto this index, so changing a choice replaces it rather than
        // stacking a second row the grader would then have to choose between.
        $this->addSql('
            CREATE UNIQUE INDEX attempt_answers_uniq
                ON attempt_answers (attempt_id, question_id)
        ');
        $this->addSql('CREATE INDEX attempt_answers_attempt_idx ON attempt_answers (attempt_id)');
        $this->addSql('
            CREATE UNIQUE INDEX attempt_answers_legacy_uniq
                ON attempt_answers (legacy_id) WHERE legacy_id IS NOT NULL
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS attempt_answers');
        $this->addSql('DROP TABLE IF EXISTS attempts');
        $this->addSql('DROP TABLE IF EXISTS assessment_questions');
        $this->addSql('DROP TABLE IF EXISTS assessments');
        $this->addSql('DROP TABLE IF EXISTS question_options');
        $this->addSql('DROP TABLE IF EXISTS questions');
        $this->addSql('DROP TABLE IF EXISTS question_banks');
    }
}
