<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The catalogue: courses, their modules, and the lessons inside them.
 *
 * Ported from `courses`, `modules` and `lessons`, with the duplication those
 * tables accumulated resolved from evidence rather than preference:
 *
 *  - **`pass_mark` is dropped.** The legacy schema had both `pass_mark` and
 *    `pass_percentage`. Live code references the latter 49 times and the
 *    former 9, so `pass_percentage` wins.
 *  - **`total_ratings` is dropped.** `average_rating`, `rating_count` and
 *    `total_ratings` were three columns for two facts. Two remain, and both
 *    are a cache of `course_reviews` — the review table stays the source of
 *    truth.
 *  - **`column_name` is dropped.** A column literally named `column_name`,
 *    referenced nowhere.
 *  - **`level` becomes a constrained value.** It was free text and had already
 *    drifted to `beginner`, `Beginner`, `Intermediate` and `''` across
 *    fourteen rows. It now uses the same three-point scale as assessments
 *    (`Domain\Shared\Difficulty`).
 *  - **`course_type` is kept** as `kind`. Every row says `Regular`, but
 *    `CourseService` filters on it, so it is a real dimension that happens to
 *    have one value today.
 *
 * Two composite foreign keys do work that would otherwise need trigger code:
 *
 *  1. `courses.category_id` can only ever point at a `category` lookup, never
 *     at a branch or a job title.
 *  2. `lessons.course_id` can never disagree with its module's course. The
 *     column is denormalised — `LearnerReport` queries lessons by course
 *     directly — but it cannot drift.
 */
final class Version20260825160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the catalogue: courses, course_modules, lessons';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration targets PostgreSQL.',
        );

        // --- courses ---------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE courses (
                id                       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id                BIGINT,
                tenant_id                UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                title                    TEXT NOT NULL,
                summary                  TEXT,
                description              TEXT,
                objectives               TEXT,
                kind                     TEXT NOT NULL DEFAULT 'regular',
                status                   TEXT NOT NULL DEFAULT 'draft',
                level                    TEXT,
                thumbnail_url            TEXT,

                category_id              UUID,
                -- Pins the reference to a category. Without this, category_id
                -- is just a uuid and nothing stops it holding a branch's id.
                category_kind            TEXT GENERATED ALWAYS AS ('category') STORED,

                instructor_id            UUID REFERENCES users (id) ON DELETE SET NULL,
                -- No FK yet: certificate_templates arrives with the
                -- certificates module.
                certificate_template_id  UUID,

                pass_percentage          INTEGER NOT NULL DEFAULT 70,
                duration_minutes         INTEGER,
                enrollment_limit         INTEGER,

                is_mandatory             BOOLEAN NOT NULL DEFAULT FALSE,
                is_featured              BOOLEAN NOT NULL DEFAULT FALSE,
                is_onboarding            BOOLEAN NOT NULL DEFAULT FALSE,
                allow_self_enrollment    BOOLEAN NOT NULL DEFAULT FALSE,

                starts_on                DATE,
                ends_on                  DATE,

                -- A cache of course_reviews, which remains the source of truth.
                rating_average           NUMERIC(3,2),
                rating_count             INTEGER NOT NULL DEFAULT 0,

                created_by               UUID REFERENCES users (id) ON DELETE SET NULL,
                created_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at               TIMESTAMPTZ,
                published_at             TIMESTAMPTZ,
                deleted_at               TIMESTAMPTZ,

                CONSTRAINT courses_status_check
                    CHECK (status IN ('draft', 'published', 'archived')),
                CONSTRAINT courses_level_check
                    CHECK (level IS NULL OR level IN ('beginner', 'intermediate', 'professional')),
                CONSTRAINT courses_pass_percentage_check
                    CHECK (pass_percentage BETWEEN 0 AND 100),
                CONSTRAINT courses_title_not_blank
                    CHECK (length(btrim(title)) > 0),
                CONSTRAINT courses_dates_ordered
                    CHECK (starts_on IS NULL OR ends_on IS NULL OR ends_on >= starts_on),
                CONSTRAINT courses_rating_count_check
                    CHECK (rating_count >= 0),
                -- NO ACTION rather than SET NULL: PostgreSQL cannot null a
                -- generated column, and reference data is soft-deleted
                -- anyway, so a hard delete of an in-use category should be
                -- refused rather than silently detaching every course.
                CONSTRAINT courses_category_fk
                    FOREIGN KEY (category_id, category_kind)
                    REFERENCES lookups (id, kind)
            )
        SQL);

        $this->addSql('CREATE UNIQUE INDEX courses_legacy_uniq ON courses (legacy_id) WHERE legacy_id IS NOT NULL');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX courses_tenant_title_uniq
                ON courses (tenant_id, lower(title))
                WHERE deleted_at IS NULL
        SQL);
        // The catalogue query: published courses for a tenant.
        $this->addSql(<<<'SQL'
            CREATE INDEX courses_tenant_status_idx
                ON courses (tenant_id, status, title)
                WHERE deleted_at IS NULL
        SQL);
        $this->addSql('CREATE INDEX courses_category_idx ON courses (category_id) WHERE category_id IS NOT NULL');
        $this->addSql('CREATE INDEX courses_instructor_idx ON courses (instructor_id) WHERE instructor_id IS NOT NULL');

        // --- modules ---------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE course_modules (
                id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id    BIGINT,
                tenant_id    UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                course_id    UUID NOT NULL REFERENCES courses (id) ON DELETE CASCADE,
                title        TEXT NOT NULL,
                description  TEXT,
                sort_order   INTEGER NOT NULL DEFAULT 0,
                is_active    BOOLEAN NOT NULL DEFAULT TRUE,
                created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at   TIMESTAMPTZ,
                deleted_at   TIMESTAMPTZ,
                CONSTRAINT course_modules_title_not_blank CHECK (length(btrim(title)) > 0),
                -- Lets lessons reference (module, course) as a pair.
                CONSTRAINT course_modules_id_course_uniq UNIQUE (id, course_id)
            )
        SQL);

        $this->addSql('CREATE UNIQUE INDEX course_modules_legacy_uniq ON course_modules (legacy_id) WHERE legacy_id IS NOT NULL');
        $this->addSql(<<<'SQL'
            CREATE INDEX course_modules_course_order_idx
                ON course_modules (course_id, sort_order)
                WHERE deleted_at IS NULL
        SQL);

        // --- lessons ---------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE lessons (
                id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id         BIGINT,
                tenant_id         UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                module_id         UUID NOT NULL,
                course_id         UUID NOT NULL,
                title             TEXT NOT NULL,
                description       TEXT,
                content_type      TEXT NOT NULL,
                content_url       TEXT,
                content_text      TEXT,
                duration_minutes  INTEGER,
                sort_order        INTEGER NOT NULL DEFAULT 0,
                is_mandatory      BOOLEAN NOT NULL DEFAULT TRUE,
                is_preview        BOOLEAN NOT NULL DEFAULT FALSE,
                is_active         BOOLEAN NOT NULL DEFAULT TRUE,
                created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at        TIMESTAMPTZ,
                deleted_at        TIMESTAMPTZ,

                CONSTRAINT lessons_title_not_blank CHECK (length(btrim(title)) > 0),
                CONSTRAINT lessons_content_type_check
                    CHECK (content_type IN ('video', 'text', 'audio', 'pdf', 'scorm', 'html', 'quiz')),

                -- The important one: course_id is denormalised for direct
                -- "lessons in this course" queries, and this makes it
                -- impossible for it to disagree with the module's course.
                CONSTRAINT lessons_module_course_fk
                    FOREIGN KEY (module_id, course_id)
                    REFERENCES course_modules (id, course_id) ON DELETE CASCADE
            )
        SQL);

        $this->addSql('CREATE UNIQUE INDEX lessons_legacy_uniq ON lessons (legacy_id) WHERE legacy_id IS NOT NULL');
        $this->addSql(<<<'SQL'
            CREATE INDEX lessons_module_order_idx
                ON lessons (module_id, sort_order)
                WHERE deleted_at IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX lessons_course_idx
                ON lessons (course_id)
                WHERE deleted_at IS NULL AND is_active
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS lessons');
        $this->addSql('DROP TABLE IF EXISTS course_modules');
        $this->addSql('DROP TABLE IF EXISTS courses');
    }
}
