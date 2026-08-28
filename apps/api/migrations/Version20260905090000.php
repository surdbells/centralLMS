<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Marking rubrics, and who is marking what.
 *
 * **Rubrics** are how an essay gets marked the same way twice. A rubric is a
 * set of criteria — "structure", "evidence", "accuracy" — and each criterion
 * has levels a marker picks between, each worth a stated number of points. Two
 * markers working from the same rubric arrive at close to the same score, and
 * a learner asking why they got what they got has an answer beyond one
 * person's judgement. The legacy had a rubric screen and no table behind it.
 *
 * Three tables rather than one JSON column, because criteria are selected,
 * summed and reported on: "how did people do on evidence across this cohort"
 * is a query, and it is not one you can write against a blob.
 *
 * **Grading assignments** hold only what cannot be worked out. What needs
 * marking is derived — it is the attempts with an ungraded written answer, and
 * storing that would be a cache that goes stale the moment somebody submits.
 * What is *not* derivable is who a coordinator has given it to, what priority
 * they set, and whether a marker has set it aside. That is a human decision
 * about a derived row, and this table holds those decisions and nothing else.
 *
 * `attempt_id` is unique: an attempt is assigned to one marker at a time.
 * Removing the row is how something goes back into the general queue, which is
 * why there is no "unassigned" status to forget to set.
 */
final class Version20260905090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Marking rubrics, and who is marking what';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE rubrics (
                id          UUID PRIMARY KEY,
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                name        TEXT NOT NULL,
                description TEXT,
                -- analytic: every criterion scored separately and summed.
                -- holistic: one judgement against the whole piece.
                kind        TEXT NOT NULL DEFAULT 'analytic',
                is_active   BOOLEAN NOT NULL DEFAULT TRUE,
                created_by  UUID REFERENCES users (id) ON DELETE SET NULL,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMPTZ,
                deleted_at  TIMESTAMPTZ,

                CONSTRAINT rubrics_name_present CHECK (BTRIM(name) <> ''),
                CONSTRAINT rubrics_kind_known CHECK (kind IN ('analytic', 'holistic'))
            )
        SQL);

        // Two rubrics in one organisation may not share a name — a marker
        // choosing between two "Essay rubric"s is choosing at random. Partial,
        // so a deleted one does not hold its name for ever.
        $this->addSql(
            'CREATE UNIQUE INDEX rubrics_name_uniq ON rubrics (tenant_id, LOWER(name))
             WHERE deleted_at IS NULL',
        );

        $this->addSql('CREATE INDEX rubrics_tenant_idx ON rubrics (tenant_id, is_active)');

        $this->addSql(<<<'SQL'
            CREATE TABLE rubric_criteria (
                id          UUID PRIMARY KEY,
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                rubric_id   UUID NOT NULL REFERENCES rubrics (id) ON DELETE CASCADE,
                title       TEXT NOT NULL,
                description TEXT,
                -- What this criterion is worth. The rubric's total is the sum
                -- of these, counted at read time rather than stored, because a
                -- stored total drifts the first time a criterion is edited.
                max_points  NUMERIC(6, 2) NOT NULL DEFAULT 0,
                sort_order  INTEGER NOT NULL DEFAULT 0,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMPTZ,

                CONSTRAINT rubric_criteria_title_present CHECK (BTRIM(title) <> ''),
                CONSTRAINT rubric_criteria_points_sane CHECK (max_points >= 0)
            )
        SQL);

        $this->addSql(
            'CREATE INDEX rubric_criteria_rubric_idx ON rubric_criteria (rubric_id, sort_order)',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE rubric_levels (
                id           UUID PRIMARY KEY,
                tenant_id    UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                criterion_id UUID NOT NULL REFERENCES rubric_criteria (id) ON DELETE CASCADE,
                label        TEXT NOT NULL,
                description  TEXT,
                points       NUMERIC(6, 2) NOT NULL DEFAULT 0,
                sort_order   INTEGER NOT NULL DEFAULT 0,
                created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT rubric_levels_label_present CHECK (BTRIM(label) <> ''),
                CONSTRAINT rubric_levels_points_sane CHECK (points >= 0)
            )
        SQL);

        $this->addSql(
            'CREATE INDEX rubric_levels_criterion_idx ON rubric_levels (criterion_id, sort_order)',
        );

        // Which rubric a question is marked against. Nullable: most questions
        // are marked automatically and need none.
        $this->addSql(
            'ALTER TABLE questions ADD COLUMN IF NOT EXISTS rubric_id UUID
             REFERENCES rubrics (id) ON DELETE SET NULL',
        );

        // What a marker actually chose, per criterion, on one answer. This is
        // the evidence behind a score, and the reason the rubric is worth
        // having at all.
        $this->addSql(<<<'SQL'
            CREATE TABLE attempt_answer_criteria (
                id           UUID PRIMARY KEY,
                tenant_id    UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                answer_id    UUID NOT NULL REFERENCES attempt_answers (id) ON DELETE CASCADE,
                criterion_id UUID NOT NULL REFERENCES rubric_criteria (id) ON DELETE CASCADE,
                level_id     UUID REFERENCES rubric_levels (id) ON DELETE SET NULL,
                points       NUMERIC(6, 2) NOT NULL DEFAULT 0,
                comment      TEXT,
                graded_by    UUID REFERENCES users (id) ON DELETE SET NULL,
                graded_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT attempt_answer_criteria_points_sane CHECK (points >= 0)
            )
        SQL);

        // One judgement per criterion per answer. A second would mean two
        // scores for the same thing and no way to say which counts.
        $this->addSql(
            'CREATE UNIQUE INDEX attempt_answer_criteria_uniq
             ON attempt_answer_criteria (answer_id, criterion_id)',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE grading_assignments (
                id          UUID PRIMARY KEY,
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                attempt_id  UUID NOT NULL REFERENCES attempts (id) ON DELETE CASCADE,
                assigned_to UUID REFERENCES users (id) ON DELETE CASCADE,
                assigned_by UUID REFERENCES users (id) ON DELETE SET NULL,
                -- Higher is sooner. A plain integer rather than an enum: a
                -- coordinator ordering forty papers wants to say "this one
                -- first", not choose between three words.
                priority    INTEGER NOT NULL DEFAULT 0,
                -- Set when a marker puts one aside, with the reason. It stays
                -- in the queue; it is simply no longer theirs.
                skipped_at  TIMESTAMPTZ,
                skip_reason TEXT,
                notes       TEXT,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMPTZ
            )
        SQL);

        // One marker at a time. Handing a paper to somebody else replaces the
        // row rather than adding one, so there is never a question of which
        // assignment is current.
        $this->addSql(
            'CREATE UNIQUE INDEX grading_assignments_attempt_uniq
             ON grading_assignments (attempt_id)',
        );

        $this->addSql(
            'CREATE INDEX grading_assignments_marker_idx
             ON grading_assignments (tenant_id, assigned_to, priority DESC)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS grading_assignments');
        $this->addSql('DROP TABLE IF EXISTS attempt_answer_criteria');
        $this->addSql('ALTER TABLE questions DROP COLUMN IF EXISTS rubric_id');
        $this->addSql('DROP TABLE IF EXISTS rubric_levels');
        $this->addSql('DROP TABLE IF EXISTS rubric_criteria');
        $this->addSql('DROP TABLE IF EXISTS rubrics');
    }
}
