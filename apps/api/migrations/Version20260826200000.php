<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sections, random selection, and the per-attempt paper.
 *
 * The previous migration left these out on the grounds that the legacy tables
 * were empty. That reasoning was wrong: the legacy proctored assessment was
 * never finished, so an empty table means an unbuilt feature, not an unwanted
 * one. These complete it.
 *
 * **`attempt_questions` is the load-bearing addition.** As soon as two
 * learners can be shown different questions, `assessment_questions` stops
 * being "the paper" and becomes "what the paper may be drawn from". Three
 * things then break unless each attempt records its own draw:
 *
 *  - a learner reloading mid-sitting would be dealt a fresh hand;
 *  - `max_score` taken from the assessment would not match the questions the
 *    learner actually saw, so a perfect score could read as 60%;
 *  - marking would have no way to know which questions were asked.
 *
 * So the draw happens once, at the start, and is written down. Manual
 * assessments write the same rows every time, which keeps one code path for
 * all three modes rather than a special case that only the untested branch
 * exercises.
 *
 * `random_question_count` returns to `assessments`: the previous migration
 * dropped it along with the genuinely derived aggregates, but it is an input,
 * not a total — how many questions to draw, which nothing else can supply.
 *
 * Dropped from the legacy shapes, on the same evidence-first basis:
 *
 *  - **`proc_assessment_sections.question_count` and `.total_points`** — the
 *    same stored aggregates as `assessments.total_questions`, which disagreed
 *    with reality in 16 of 19 rows. Counted instead.
 *  - **`proc_assessment_random_rules.tag_ids`** — there is no tag table in
 *    this schema and never was one in the legacy data. A JSON column holding
 *    ids of rows that do not exist is not a feature.
 *  - **`proc_assessment_questions.time_limit_override`** — per-question
 *    timing that the legacy player never implemented, and which
 *    `assessments.time_limit_minutes` already covers at the level people
 *    actually set it.
 */
final class Version20260826200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Assessment sections, random selection rules, and the per-attempt question paper.';
    }

    public function up(Schema $schema): void
    {
        $this->sections();
        $this->sectionOnAssessmentQuestions();
        $this->randomRules();
        $this->randomQuestionCount();
        $this->attemptQuestions();
        $this->allowAllSelectionModes();
    }

    private function sections(): void
    {
        $this->addSql("
            CREATE TABLE assessment_sections (
                id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id     BIGINT,
                tenant_id     UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                assessment_id UUID NOT NULL,

                title         TEXT NOT NULL,
                description   TEXT,
                instructions  TEXT,

                -- A section may run to its own clock. NULL means it shares the
                -- assessment's.
                time_limit_minutes INTEGER,

                -- Whether every question in the section is asked, or a sample.
                selection_type TEXT NOT NULL DEFAULT 'all',
                random_count   INTEGER,

                sort_order    INTEGER NOT NULL DEFAULT 0,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at    TIMESTAMPTZ,

                CONSTRAINT assessment_sections_title_not_blank CHECK (length(btrim(title)) > 0),
                CONSTRAINT assessment_sections_selection_check
                    CHECK (selection_type IN ('all', 'random')),
                CONSTRAINT assessment_sections_time_check
                    CHECK (time_limit_minutes IS NULL OR time_limit_minutes > 0),

                -- Drawing a sample needs a size, and a size means nothing
                -- without a sample. The legacy pair allowed either alone.
                CONSTRAINT assessment_sections_random_consistent CHECK (
                    (selection_type = 'random' AND random_count IS NOT NULL AND random_count > 0)
                    OR (selection_type = 'all' AND random_count IS NULL)
                ),

                CONSTRAINT assessment_sections_assessment_fk
                    FOREIGN KEY (assessment_id, tenant_id)
                    REFERENCES assessments (id, tenant_id) ON DELETE CASCADE,

                -- Referenced by assessment_questions, so a question cannot be
                -- filed under a section of a different assessment.
                CONSTRAINT assessment_sections_id_assessment_uniq UNIQUE (id, assessment_id)
            )
        ");

        $this->addSql('
            CREATE INDEX assessment_sections_order_idx
                ON assessment_sections (assessment_id, sort_order)
        ');
        $this->addSql('
            CREATE UNIQUE INDEX assessment_sections_legacy_uniq
                ON assessment_sections (legacy_id) WHERE legacy_id IS NOT NULL
        ');
    }

    /**
     * Files a question under a section, or leaves it loose.
     *
     * Nullable because a single-section assessment needs no section row at
     * all — the common case stays free of ceremony.
     */
    private function sectionOnAssessmentQuestions(): void
    {
        $this->addSql('ALTER TABLE assessment_questions ADD COLUMN section_id UUID');
        $this->addSql('
            ALTER TABLE assessment_questions ADD CONSTRAINT assessment_questions_section_fk
                FOREIGN KEY (section_id, assessment_id)
                REFERENCES assessment_sections (id, assessment_id) ON DELETE SET NULL
        ');
        $this->addSql('
            CREATE INDEX assessment_questions_section_idx
                ON assessment_questions (section_id, sort_order) WHERE section_id IS NOT NULL
        ');
    }

    private function randomRules(): void
    {
        $this->addSql("
            CREATE TABLE assessment_random_rules (
                id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id     BIGINT,
                tenant_id     UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                assessment_id UUID NOT NULL,

                -- A rule may fill one section or the whole paper.
                section_id    UUID,

                -- Where to draw from.
                bank_id       UUID NOT NULL,

                -- Narrowing, all optional. NULL means no restriction.
                category_id   UUID,
                category_kind TEXT GENERATED ALWAYS AS ('category') STORED,
                difficulty    TEXT,
                question_types JSONB,

                question_count      INTEGER NOT NULL,
                points_per_question NUMERIC(6,2) NOT NULL DEFAULT 1,
                sort_order          INTEGER NOT NULL DEFAULT 0,

                created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at    TIMESTAMPTZ,

                CONSTRAINT assessment_random_rules_count_check CHECK (question_count > 0),
                CONSTRAINT assessment_random_rules_points_check CHECK (points_per_question >= 0),
                CONSTRAINT assessment_random_rules_difficulty_check
                    CHECK (difficulty IS NULL OR difficulty IN ('beginner', 'intermediate', 'professional')),

                -- An array, or nothing. An object here would be a silent
                -- mismatch the draw could not act on.
                CONSTRAINT assessment_random_rules_types_check
                    CHECK (question_types IS NULL OR jsonb_typeof(question_types) = 'array'),

                CONSTRAINT assessment_random_rules_assessment_fk
                    FOREIGN KEY (assessment_id, tenant_id)
                    REFERENCES assessments (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT assessment_random_rules_section_fk
                    FOREIGN KEY (section_id, assessment_id)
                    REFERENCES assessment_sections (id, assessment_id) ON DELETE CASCADE,
                CONSTRAINT assessment_random_rules_bank_fk
                    FOREIGN KEY (bank_id, tenant_id)
                    REFERENCES question_banks (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT assessment_random_rules_category_fk
                    FOREIGN KEY (category_id, category_kind) REFERENCES lookups (id, kind)
            )
        ");

        $this->addSql('
            CREATE INDEX assessment_random_rules_order_idx
                ON assessment_random_rules (assessment_id, sort_order)
        ');
        $this->addSql('
            CREATE UNIQUE INDEX assessment_random_rules_legacy_uniq
                ON assessment_random_rules (legacy_id) WHERE legacy_id IS NOT NULL
        ');
    }

    /**
     * How many questions `random` mode draws from the attached list.
     *
     * An input rather than a total, which is why it comes back while
     * `total_questions` stays gone.
     */
    private function randomQuestionCount(): void
    {
        $this->addSql('ALTER TABLE assessments ADD COLUMN random_question_count INTEGER');
        $this->addSql("
            ALTER TABLE assessments ADD CONSTRAINT assessments_random_count_check
                CHECK (random_question_count IS NULL OR random_question_count > 0)
        ");

        // Random mode without a count would draw nothing and score everyone
        // zero out of zero, which the pass rules then read as a pass.
        $this->addSql("
            ALTER TABLE assessments ADD CONSTRAINT assessments_random_needs_count
                CHECK (question_selection <> 'random' OR random_question_count IS NOT NULL)
        ");
    }

    /**
     * The questions this attempt was actually dealt.
     *
     * See the class docblock: without it, random selection cannot be resumed,
     * scored or marked.
     */
    private function attemptQuestions(): void
    {
        // Added before the table that references it: a composite
        // foreign key needs a matching unique constraint to exist first.
        $this->addSql('ALTER TABLE attempts ADD CONSTRAINT attempts_id_tenant_uniq UNIQUE (id, tenant_id)');

        $this->addSql('
            CREATE TABLE attempt_questions (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                attempt_id  UUID NOT NULL,
                question_id UUID NOT NULL,
                section_id  UUID REFERENCES assessment_sections (id) ON DELETE SET NULL,

                -- Frozen at the draw. An author changing a question\'s worth
                -- mid-sitting must not change what the learner in front of it
                -- is being marked out of.
                points_possible NUMERIC(6,2) NOT NULL,
                sort_order      INTEGER NOT NULL DEFAULT 0,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT attempt_questions_points_check CHECK (points_possible >= 0),
                CONSTRAINT attempt_questions_attempt_fk
                    FOREIGN KEY (attempt_id, tenant_id)
                    REFERENCES attempts (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT attempt_questions_question_fk
                    FOREIGN KEY (question_id, tenant_id)
                    REFERENCES questions (id, tenant_id) ON DELETE CASCADE
            )
        ');

        // A question is dealt once per attempt.
        $this->addSql('
            CREATE UNIQUE INDEX attempt_questions_uniq
                ON attempt_questions (attempt_id, question_id)
        ');
        $this->addSql('
            CREATE INDEX attempt_questions_order_idx
                ON attempt_questions (attempt_id, sort_order)
        ');
    }

    /**
     * The previous migration pinned `question_selection` to `manual` because
     * the machinery for the other two did not exist. It does now.
     */
    private function allowAllSelectionModes(): void
    {
        $this->addSql('ALTER TABLE assessments DROP CONSTRAINT assessments_selection_check');
        $this->addSql("
            ALTER TABLE assessments ADD CONSTRAINT assessments_selection_check
                CHECK (question_selection IN ('manual', 'random', 'pool'))
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assessments DROP CONSTRAINT IF EXISTS assessments_selection_check');
        $this->addSql("
            ALTER TABLE assessments ADD CONSTRAINT assessments_selection_check
                CHECK (question_selection IN ('manual'))
        ");

        $this->addSql('ALTER TABLE attempts DROP CONSTRAINT IF EXISTS attempts_id_tenant_uniq');
        $this->addSql('DROP TABLE IF EXISTS attempt_questions');

        $this->addSql('ALTER TABLE assessments DROP CONSTRAINT IF EXISTS assessments_random_needs_count');
        $this->addSql('ALTER TABLE assessments DROP CONSTRAINT IF EXISTS assessments_random_count_check');
        $this->addSql('ALTER TABLE assessments DROP COLUMN IF EXISTS random_question_count');

        $this->addSql('DROP TABLE IF EXISTS assessment_random_rules');

        $this->addSql('ALTER TABLE assessment_questions DROP CONSTRAINT IF EXISTS assessment_questions_section_fk');
        $this->addSql('DROP INDEX IF EXISTS assessment_questions_section_idx');
        $this->addSql('ALTER TABLE assessment_questions DROP COLUMN IF EXISTS section_id');

        $this->addSql('DROP TABLE IF EXISTS assessment_sections');
    }
}
