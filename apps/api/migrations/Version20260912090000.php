<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One table for what confers a skill, whatever the thing is.
 *
 * `course_skills` said which skills a course teaches. A training programme
 * teaches skills too — the screen for saying so has been there all along with
 * nothing behind it — and so does a learning path. Three tables with the same
 * four columns is three places for "what does 'primary' mean here" to drift,
 * and three joins in every query that asks what somebody knows.
 *
 * The skills a person holds stay derived rather than stored. Nobody is awarded
 * a skill; they hold it because they finished something that teaches it, and
 * that is a fact recomputed on every read. A stored award is wrong the moment
 * a completion is corrected, and nothing goes back to fix it.
 */
final class Version20260912090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let training programmes and paths confer skills, not only courses';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE subject_skills (
                subject_kind TEXT NOT NULL,
                subject_id UUID NOT NULL,
                skill_id UUID NOT NULL,
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,

                -- How well somebody knows it after finishing this. A course
                -- and a two-day workshop on the same subject do not leave
                -- people equally able.
                proficiency TEXT NOT NULL DEFAULT 'intermediate',

                -- The one skill this is mostly about, for a catalogue that
                -- has to name a subject in a word.
                is_primary BOOLEAN NOT NULL DEFAULT FALSE,

                -- Whether finishing this actually gives somebody the skill.
                -- An optional one is a subject the course touches on; saying
                -- everybody who attended now holds it is the overstatement
                -- that makes a skills register useless to the person reading
                -- it. Defaults true, which is what every existing row means.
                is_required BOOLEAN NOT NULL DEFAULT TRUE,

                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                PRIMARY KEY (subject_kind, subject_id, skill_id),

                CONSTRAINT subject_skills_skill_fk
                    FOREIGN KEY (skill_id, tenant_id) REFERENCES skills (id, tenant_id) ON DELETE CASCADE,

                CONSTRAINT subject_skills_kind_known CHECK (
                    subject_kind IN ('course', 'training', 'path')
                ),
                CONSTRAINT subject_skills_proficiency_known CHECK (
                    proficiency IN ('beginner', 'intermediate', 'advanced', 'expert')
                )
            )
        SQL);

        // One headline skill per subject, as courses already had.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX subject_skills_one_primary
                ON subject_skills (subject_kind, subject_id) WHERE is_primary
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX subject_skills_skill_idx ON subject_skills (tenant_id, skill_id)
        SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO subject_skills
                (subject_kind, subject_id, skill_id, tenant_id, proficiency, is_primary,
                 is_required, created_at)
            SELECT 'course', course_id, skill_id, tenant_id, proficiency, is_primary,
                   TRUE, created_at
            FROM course_skills
        SQL);

        $this->addSql('DROP TABLE course_skills');

        // --- the two things every skill question needs -----------------------
        //
        // Views, not tables: both are readings of rows that already exist, and
        // a copy of them would be wrong the moment somebody finished
        // something. Every skill query joins these, so "what counts as
        // finished" is answered once instead of once per query.

        $this->addSql(<<<'SQL'
            CREATE VIEW teachable_subjects AS
                SELECT 'course' AS subject_kind, id AS subject_id, tenant_id, title, deleted_at
                FROM courses
                UNION ALL
                SELECT 'training', id, tenant_id, title, deleted_at
                FROM live_trainings
                UNION ALL
                SELECT 'path', id, tenant_id, title, deleted_at
                FROM learning_paths
        SQL);

        // The three enrolment tables spell "gave up" three different ways.
        // Normalised here so no query has to remember which.
        $this->addSql(<<<'SQL'
            CREATE VIEW subject_completions AS
                SELECT 'course' AS subject_kind, course_id AS subject_id, user_id, tenant_id,
                       status = 'completed' AS is_complete,
                       status = 'dropped' AS is_abandoned,
                       completed_at
                FROM enrollments WHERE deleted_at IS NULL
                UNION ALL
                SELECT 'training', training_id, user_id, tenant_id,
                       status = 'completed', status = 'dropped', completed_at
                FROM training_enrollments WHERE deleted_at IS NULL
                UNION ALL
                SELECT 'path', path_id, user_id, tenant_id,
                       status = 'completed', status = 'withdrawn', completed_at
                FROM learning_path_enrollments WHERE deleted_at IS NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP VIEW IF EXISTS subject_completions');
        $this->addSql('DROP VIEW IF EXISTS teachable_subjects');

        $this->addSql(<<<'SQL'
            CREATE TABLE course_skills (
                course_id UUID NOT NULL,
                skill_id UUID NOT NULL,
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                proficiency TEXT NOT NULL DEFAULT 'intermediate',
                is_primary BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (course_id, skill_id),
                CONSTRAINT course_skills_course_fk
                    FOREIGN KEY (course_id, tenant_id) REFERENCES courses (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT course_skills_skill_fk
                    FOREIGN KEY (skill_id, tenant_id) REFERENCES skills (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT course_skills_proficiency_known CHECK (
                    proficiency IN ('beginner', 'intermediate', 'advanced', 'expert')
                )
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX course_skills_one_primary
                ON course_skills (course_id) WHERE is_primary
        SQL);
        $this->addSql('CREATE INDEX course_skills_skill_idx ON course_skills (skill_id)');

        $this->addSql(<<<'SQL'
            INSERT INTO course_skills (course_id, skill_id, tenant_id, proficiency, is_primary, created_at)
            SELECT subject_id, skill_id, tenant_id, proficiency, is_primary, created_at
            FROM subject_skills WHERE subject_kind = 'course'
        SQL);

        $this->addSql('DROP TABLE IF EXISTS subject_skills');
    }
}
