<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The skills an organisation teaches, and which courses teach them.
 *
 * Three tables where the legacy had five. The two that are missing are the
 * interesting part.
 *
 * **`user_skills_acquired` and `user_skills_log` are not ported.** Both hold
 * zero rows, and — unlike the other empty tables in this port — nothing reads
 * them either. `Skills::getLearnerSkills()` derives a person's skills by
 * joining their completed enrolments to `course_skills`, which is the design
 * that works: finishing the fire-safety course is what makes somebody
 * fire-safety trained, and a second table saying so can only ever disagree
 * with it. The tables were built for both ends of a feature and wired at
 * neither.
 *
 * Two things that derivation cannot express, and what each would need:
 *
 *  - **A skill granted by hand** — `source_type` allowed `manual`, `external`
 *    and `certification`, and no endpoint ever granted one. It needs a table
 *    of grants that sits *beside* the derived set rather than replacing it, so
 *    the two are distinguishable.
 *  - **A skill that expires** — `expires_at` was never written. Recertifying
 *    is a real requirement and the certificate module already models validity;
 *    a skill's expiry should follow from the certificate that evidences it
 *    rather than from a date typed into a second place.
 *
 * `live_training` was one of the allowed sources, and `course_skills` links
 * only courses. Instructor-led training teaching a skill is a real gap; it
 * needs the same link table pointed at `trainings`.
 */
final class Version20260829180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Skills, their categories, and what teaches them';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'PostgreSQL only.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE skill_categories (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                name        TEXT NOT NULL,
                description TEXT,
                colour      TEXT NOT NULL DEFAULT '#6c757d',
                is_active   BOOLEAN NOT NULL DEFAULT TRUE,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at  TIMESTAMPTZ,

                CONSTRAINT skill_categories_name_present CHECK (btrim(name) <> ''),
                CONSTRAINT skill_categories_colour_is_hex CHECK (colour ~* '^#([0-9a-f]{3}|[0-9a-f]{6})$')
            )
        SQL);

        $this->addSql('CREATE UNIQUE INDEX skill_categories_id_tenant_uniq ON skill_categories (id, tenant_id)');

        // Two categories with the same name in one organisation is a mistake,
        // not a choice. Per tenant and case-insensitive, because "Technical"
        // and "technical" are the same category to everybody but the database.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX skill_categories_name_uniq
                ON skill_categories (tenant_id, lower(btrim(name)))
                WHERE deleted_at IS NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE skills (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                category_id UUID,

                name        TEXT NOT NULL,
                description TEXT,

                -- What holding this skill at all means. A course may teach it
                -- to a higher level; see course_skills.
                level       TEXT NOT NULL DEFAULT 'intermediate',

                icon        TEXT,
                colour      TEXT NOT NULL DEFAULT '#c9a227',

                is_active   BOOLEAN NOT NULL DEFAULT TRUE,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at  TIMESTAMPTZ,

                CONSTRAINT skills_name_present CHECK (btrim(name) <> ''),
                CONSTRAINT skills_level_known
                    CHECK (level IN ('beginner', 'intermediate', 'advanced', 'expert')),
                CONSTRAINT skills_colour_is_hex CHECK (colour ~* '^#([0-9a-f]{3}|[0-9a-f]{6})$'),

                -- A skill cannot be filed under another organisation's
                -- category. The legacy joined on `category_id` alone.
                CONSTRAINT skills_category_fk
                    FOREIGN KEY (category_id, tenant_id)
                    REFERENCES skill_categories (id, tenant_id) ON DELETE SET NULL
            )
        SQL);

        $this->addSql('CREATE UNIQUE INDEX skills_id_tenant_uniq ON skills (id, tenant_id)');

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX skills_name_uniq
                ON skills (tenant_id, lower(btrim(name)))
                WHERE deleted_at IS NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX skills_category_idx ON skills (category_id) WHERE deleted_at IS NULL
        SQL);

        // What a course teaches. The whole feature rests on this table: a
        // learner's skills are the skills of the courses they have finished.
        $this->addSql(<<<'SQL'
            CREATE TABLE course_skills (
                course_id   UUID NOT NULL,
                skill_id    UUID NOT NULL,
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,

                -- The level finishing this course demonstrates, which may be
                -- higher or lower than the skill's own default.
                proficiency TEXT NOT NULL DEFAULT 'intermediate',

                -- The one the course is really about, for a card that shows a
                -- single badge.
                is_primary  BOOLEAN NOT NULL DEFAULT FALSE,

                created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),

                PRIMARY KEY (course_id, skill_id),

                CONSTRAINT course_skills_proficiency_known
                    CHECK (proficiency IN ('beginner', 'intermediate', 'advanced', 'expert')),

                CONSTRAINT course_skills_course_fk
                    FOREIGN KEY (course_id, tenant_id) REFERENCES courses (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT course_skills_skill_fk
                    FOREIGN KEY (skill_id, tenant_id) REFERENCES skills (id, tenant_id) ON DELETE CASCADE
            )
        SQL);

        $this->addSql('CREATE INDEX course_skills_skill_idx ON course_skills (skill_id)');

        // At most one primary skill per course. The legacy let every row set
        // the flag, so a course could be "really about" four things.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX course_skills_one_primary
                ON course_skills (course_id)
                WHERE is_primary
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS course_skills');
        $this->addSql('DROP TABLE IF EXISTS skills');
        $this->addSql('DROP TABLE IF EXISTS skill_categories');
    }
}
