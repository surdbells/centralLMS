<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Learning paths: an ordered programme of courses, assessments and sessions.
 *
 * Five tables where the legacy had ten plus two views, and one stored value in
 * place of nineteen.
 *
 * ## The counters disagreed with each other
 *
 * The single path in the database says `total_items = 2`. It has **three** item
 * rows, all of them deactivated, so the true count is **zero**. Every one of its
 * thirty-three enrolments carries `items_total = 4`. Three stored answers to one
 * question and none of them right. `total_enrollments` says 19 against 33 rows.
 * All nineteen counters on `learning_paths` and the six on the enrolment go; the
 * queries that maintained them go with them.
 *
 * ## An item's progress is the progress of the thing it points at
 *
 * `learning_path_item_progress` stored `status`, `progress_percentage`,
 * `best_score`, `passed` and `attempts` **beside** `course_enrollment_id` and
 * `assessment_attempt_id` — a second copy of what the course enrolment and the
 * assessment attempt already record. All fourteen of its rows say `locked` with
 * both foreign keys null: nothing ever advanced one.
 *
 * So it is not ported as a source of truth. If an item is a course, the
 * learner's enrolment on that course says whether it is done; if it is an
 * assessment, their attempts do; if it is a session, the register does. That
 * removes the drift, and it means a path credits work already finished before
 * somebody joined it — which the legacy could not express at all, since its item
 * progress began at `locked` on enrolment.
 *
 * `learning_path_item_completions` remains for the two kinds that have no
 * underlying record — an external link and a piece of media — and for an
 * administrator marking something done by hand.
 *
 * ## Not carried
 *
 *  - **`learning_path_reviews`** (0 rows), with `avg_rating` and
 *    `total_ratings`. Reviews are a feature, not a column; nothing rendered one.
 *  - **`learning_path_prerequisites`** (0 rows) — paths requiring other paths.
 *  - **`learning_path_section_progress`** (0 rows) — derivable from the items in
 *    the section.
 *  - **`learning_path_activity_log`** (3 rows) — an audit trail of the progress
 *    the progress rows already carry.
 *  - **`learning_path_assignments`** (1 row) — an enrolment is how somebody is
 *    put on a path.
 *  - **`vw_learning_path_stats`** and **`vw_user_path_progress`** — views, which
 *    is to say queries.
 *  - **Pricing** (`is_free`, `price`, `currency`) — there is no commerce here.
 *    The one path is free and priced in USD in a platform that bills in naira.
 *  - **The per-item unlock engine** — six `unlock_type` values, a JSON list of
 *    item ids, an unlock date and a minimum score. Every row in the database
 *    says `previous_item` with all four of the rest null. Unlocking follows
 *    from the path's own type, which is the rule anybody actually used.
 */
final class Version20260830090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Learning paths, their contents and who is on them';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'PostgreSQL only.',
        );

        $this->paths();
        $this->sections();
        $this->items();
        $this->enrollments();
        $this->completions();
    }

    private function paths(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE learning_paths (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id   BIGINT,
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,

                title       TEXT NOT NULL,
                slug        TEXT NOT NULL,
                summary     TEXT,
                description TEXT,

                thumbnail_url TEXT,
                category_id   UUID,
                category_kind TEXT GENERATED ALWAYS AS ('category') STORED,

                level       TEXT NOT NULL DEFAULT 'all_levels',

                -- How the contents unlock. `sequential` means one after
                -- another, `flexible` means any order, `milestone` means the
                -- sections gate each other. These three are the whole unlock
                -- engine; see the class docblock for the six per-item rules
                -- that are not carried.
                pacing      TEXT NOT NULL DEFAULT 'sequential',

                is_mandatory BOOLEAN NOT NULL DEFAULT FALSE,

                -- What counts as finishing it.
                completion_rule      TEXT NOT NULL DEFAULT 'all_required_items',
                completion_threshold NUMERIC(5,2) NOT NULL DEFAULT 100,

                certificate_template_id UUID,
                certificate_validity_months INTEGER,

                estimated_hours NUMERIC(6,2),

                status      TEXT NOT NULL DEFAULT 'draft',
                visibility  TEXT NOT NULL DEFAULT 'private',

                enrolment_type TEXT NOT NULL DEFAULT 'open',
                max_enrolments INTEGER,

                tags                JSONB NOT NULL DEFAULT '[]'::jsonb,
                learning_objectives JSONB NOT NULL DEFAULT '[]'::jsonb,

                created_by  UUID REFERENCES users (id) ON DELETE SET NULL,
                published_at TIMESTAMPTZ,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at  TIMESTAMPTZ,

                CONSTRAINT learning_paths_title_present CHECK (btrim(title) <> ''),
                CONSTRAINT learning_paths_slug_present CHECK (btrim(slug) <> ''),
                CONSTRAINT learning_paths_pacing_known
                    CHECK (pacing IN ('sequential', 'flexible', 'milestone')),
                CONSTRAINT learning_paths_level_known
                    CHECK (level IN ('beginner', 'intermediate', 'advanced', 'expert', 'all_levels')),
                CONSTRAINT learning_paths_status_known
                    CHECK (status IN ('draft', 'published', 'archived')),
                CONSTRAINT learning_paths_visibility_known
                    CHECK (visibility IN ('public', 'private', 'enrolled_only')),
                CONSTRAINT learning_paths_enrolment_type_known
                    CHECK (enrolment_type IN ('open', 'invitation_only')),
                CONSTRAINT learning_paths_completion_rule_known
                    CHECK (completion_rule IN ('all_required_items', 'percentage')),
                CONSTRAINT learning_paths_threshold_sane
                    CHECK (completion_threshold > 0 AND completion_threshold <= 100),
                CONSTRAINT learning_paths_max_enrolments_sane
                    CHECK (max_enrolments IS NULL OR max_enrolments > 0),
                CONSTRAINT learning_paths_tags_are_a_list CHECK (jsonb_typeof(tags) = 'array'),
                CONSTRAINT learning_paths_objectives_are_a_list
                    CHECK (jsonb_typeof(learning_objectives) = 'array'),

                -- A published path has a date; a draft has not. The legacy let
                -- `status = 'published'` sit beside a null `published_at`, so
                -- "when did this go live" had no answer.
                CONSTRAINT learning_paths_published_consistent
                    CHECK (status <> 'published' OR published_at IS NOT NULL),

                -- Pins the reference to a category, so a department id cannot
                -- be stored here. The same pair `courses` uses.
                -- No ON DELETE: PostgreSQL refuses one on a foreign key that
                -- contains a generated column, which is the price of pinning
                -- the kind this way.
                CONSTRAINT learning_paths_category_fk
                    FOREIGN KEY (category_id, category_kind)
                    REFERENCES lookups (id, kind),
                CONSTRAINT learning_paths_template_fk
                    FOREIGN KEY (certificate_template_id, tenant_id)
                    REFERENCES certificate_templates (id, tenant_id) ON DELETE SET NULL
            )
        SQL);

        $this->addSql('CREATE UNIQUE INDEX learning_paths_id_tenant_uniq ON learning_paths (id, tenant_id)');

        // A slug is an address. Two paths sharing one in a tenant means a URL
        // that resolves to whichever the database returns first.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX learning_paths_slug_uniq
                ON learning_paths (tenant_id, lower(slug))
                WHERE deleted_at IS NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX learning_paths_catalogue_idx
                ON learning_paths (tenant_id, status, created_at DESC)
                WHERE deleted_at IS NULL
        SQL);
    }

    private function sections(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE learning_path_sections (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                path_id     UUID NOT NULL,

                title       TEXT NOT NULL,
                description TEXT,
                sort_order  INTEGER NOT NULL DEFAULT 0,

                -- A milestone section gates the ones after it when the path is
                -- paced by milestone. `unlock_after_section_id` and the four
                -- unlock types it came with are not carried: the order is the
                -- gate, and a section that unlocks a section out of order is a
                -- graph nobody drew.
                is_milestone BOOLEAN NOT NULL DEFAULT FALSE,

                -- How many of its items must be finished. Null means all the
                -- required ones.
                min_items_to_complete INTEGER,

                created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at  TIMESTAMPTZ,

                CONSTRAINT learning_path_sections_title_present CHECK (btrim(title) <> ''),
                CONSTRAINT learning_path_sections_minimum_sane
                    CHECK (min_items_to_complete IS NULL OR min_items_to_complete > 0),

                CONSTRAINT learning_path_sections_path_fk
                    FOREIGN KEY (path_id, tenant_id)
                    REFERENCES learning_paths (id, tenant_id) ON DELETE CASCADE
            )
        SQL);

        $this->addSql(
            'CREATE UNIQUE INDEX learning_path_sections_id_path_uniq ON learning_path_sections (id, path_id)',
        );

        $this->addSql(<<<'SQL'
            CREATE INDEX learning_path_sections_order_idx
                ON learning_path_sections (path_id, sort_order)
                WHERE deleted_at IS NULL
        SQL);
    }

    private function items(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE learning_path_items (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                path_id     UUID NOT NULL,
                section_id  UUID,

                kind        TEXT NOT NULL,

                -- Exactly one of these is set, and the check below keeps it
                -- that way. The legacy had five nullable ids and nothing
                -- stopping a row from setting three of them.
                course_id     UUID,
                assessment_id UUID,
                session_id    UUID,
                media_id      UUID,
                external_url  TEXT,

                -- Only a link needs its own title; everything else has one
                -- already, and copying it is how a course renamed in the
                -- catalogue keeps its old name inside a path.
                title       TEXT,
                description TEXT,

                sort_order  INTEGER NOT NULL DEFAULT 0,
                is_required BOOLEAN NOT NULL DEFAULT TRUE,

                points_value INTEGER NOT NULL DEFAULT 0,

                -- Either a fixed date or a number of days from enrolment.
                due_date                 DATE,
                due_days_from_enrolment  INTEGER,

                estimated_minutes INTEGER,

                created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at  TIMESTAMPTZ,

                CONSTRAINT learning_path_items_kind_known
                    CHECK (kind IN ('course', 'assessment', 'session', 'media', 'link')),
                CONSTRAINT learning_path_items_points_sane CHECK (points_value >= 0),
                CONSTRAINT learning_path_items_due_days_sane
                    CHECK (due_days_from_enrolment IS NULL OR due_days_from_enrolment > 0),

                -- One deadline, not two.
                CONSTRAINT learning_path_items_one_deadline
                    CHECK (due_date IS NULL OR due_days_from_enrolment IS NULL),

                -- The kind and the reference agree, and exactly one reference
                -- is set.
                CONSTRAINT learning_path_items_points_at_one_thing CHECK (
                    (kind = 'course'     AND course_id     IS NOT NULL AND assessment_id IS NULL
                        AND session_id IS NULL AND media_id IS NULL AND external_url IS NULL)
                    OR (kind = 'assessment' AND assessment_id IS NOT NULL AND course_id IS NULL
                        AND session_id IS NULL AND media_id IS NULL AND external_url IS NULL)
                    OR (kind = 'session'    AND session_id    IS NOT NULL AND course_id IS NULL
                        AND assessment_id IS NULL AND media_id IS NULL AND external_url IS NULL)
                    OR (kind = 'media'      AND media_id      IS NOT NULL AND course_id IS NULL
                        AND assessment_id IS NULL AND session_id IS NULL AND external_url IS NULL)
                    OR (kind = 'link'       AND external_url  IS NOT NULL AND btrim(external_url) <> ''
                        AND course_id IS NULL AND assessment_id IS NULL AND session_id IS NULL
                        AND media_id IS NULL)
                ),

                -- A link has to say what it is; nothing else does.
                CONSTRAINT learning_path_items_link_has_a_title
                    CHECK (kind <> 'link' OR (title IS NOT NULL AND btrim(title) <> '')),

                CONSTRAINT learning_path_items_path_fk
                    FOREIGN KEY (path_id, tenant_id)
                    REFERENCES learning_paths (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT learning_path_items_section_fk
                    FOREIGN KEY (section_id, path_id)
                    REFERENCES learning_path_sections (id, path_id) ON DELETE SET NULL,

                -- Every reference is composite, so a path cannot contain
                -- another organisation's course.
                CONSTRAINT learning_path_items_course_fk
                    FOREIGN KEY (course_id, tenant_id) REFERENCES courses (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT learning_path_items_assessment_fk
                    FOREIGN KEY (assessment_id, tenant_id)
                    REFERENCES assessments (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT learning_path_items_session_fk
                    FOREIGN KEY (session_id, tenant_id)
                    REFERENCES training_sessions (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT learning_path_items_media_fk
                    FOREIGN KEY (media_id, tenant_id) REFERENCES media (id, tenant_id) ON DELETE CASCADE
            )
        SQL);

        $this->addSql('CREATE UNIQUE INDEX learning_path_items_id_path_uniq ON learning_path_items (id, path_id)');
        $this->addSql('CREATE UNIQUE INDEX learning_path_items_id_tenant_uniq ON learning_path_items (id, tenant_id)');

        $this->addSql(<<<'SQL'
            CREATE INDEX learning_path_items_order_idx
                ON learning_path_items (path_id, sort_order)
                WHERE deleted_at IS NULL
        SQL);

        // The same course twice in one path is a mistake, and it would be
        // counted twice in every derived figure.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX learning_path_items_course_once
                ON learning_path_items (path_id, course_id)
                WHERE course_id IS NOT NULL AND deleted_at IS NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX learning_path_items_assessment_once
                ON learning_path_items (path_id, assessment_id)
                WHERE assessment_id IS NOT NULL AND deleted_at IS NULL
        SQL);
    }

    private function enrollments(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE learning_path_enrollments (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id   BIGINT,
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                path_id     UUID NOT NULL,
                user_id     UUID NOT NULL,

                status      TEXT NOT NULL DEFAULT 'active',
                source      TEXT NOT NULL DEFAULT 'direct',
                assigned_by UUID REFERENCES users (id) ON DELETE SET NULL,

                is_mandatory BOOLEAN NOT NULL DEFAULT FALSE,
                due_date     DATE,

                enrolled_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                started_at   TIMESTAMPTZ,
                completed_at TIMESTAMPTZ,

                -- The certificate issued for finishing, if the path issues
                -- one. The only thing worth storing about a completion beyond
                -- when it happened.
                certificate_id UUID,

                created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at  TIMESTAMPTZ,

                -- Everything the legacy stored here is derived instead:
                -- progress_percentage, items_completed, items_total,
                -- current_item_id, total_points_earned, avg_assessment_score.
                -- `items_total` was 4 on every one of the thirty-three rows,
                -- for a path with two claimed items and none active.

                CONSTRAINT learning_path_enrollments_status_known
                    CHECK (status IN ('active', 'completed', 'withdrawn')),
                CONSTRAINT learning_path_enrollments_source_known
                    CHECK (source IN ('self', 'direct', 'bulk', 'team', 'branch', 'department')),
                CONSTRAINT learning_path_enrollments_completed_consistent
                    CHECK ((status = 'completed') = (completed_at IS NOT NULL)),

                CONSTRAINT learning_path_enrollments_path_fk
                    FOREIGN KEY (path_id, tenant_id)
                    REFERENCES learning_paths (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT learning_path_enrollments_user_fk
                    FOREIGN KEY (user_id, tenant_id) REFERENCES users (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT learning_path_enrollments_certificate_fk
                    FOREIGN KEY (certificate_id, tenant_id)
                    REFERENCES certificates (id, tenant_id) ON DELETE SET NULL
            )
        SQL);

        $this->addSql(
            'CREATE UNIQUE INDEX learning_path_enrollments_id_tenant_uniq
                ON learning_path_enrollments (id, tenant_id)',
        );

        // On a path once. The legacy had no such index, and enrolling in bulk
        // twice put the same person on twice.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX learning_path_enrollments_once
                ON learning_path_enrollments (path_id, user_id)
                WHERE deleted_at IS NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX learning_path_enrollments_mine_idx
                ON learning_path_enrollments (tenant_id, user_id, status)
                WHERE deleted_at IS NULL
        SQL);
    }

    private function completions(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE learning_path_item_completions (
                enrollment_id UUID NOT NULL,
                item_id       UUID NOT NULL,
                tenant_id     UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,

                -- `viewed` is a learner opening a link or a document, which is
                -- the only evidence those two kinds can produce. `manual` is an
                -- administrator saying it happened.
                source        TEXT NOT NULL,
                completed_by  UUID REFERENCES users (id) ON DELETE SET NULL,
                note          TEXT,
                completed_at  TIMESTAMPTZ NOT NULL DEFAULT now(),

                PRIMARY KEY (enrollment_id, item_id),

                CONSTRAINT learning_path_item_completions_source_known
                    CHECK (source IN ('viewed', 'manual')),
                -- Somebody has to have said so.
                CONSTRAINT learning_path_item_completions_manual_has_an_author
                    CHECK (source <> 'manual' OR completed_by IS NOT NULL),

                CONSTRAINT learning_path_item_completions_enrollment_fk
                    FOREIGN KEY (enrollment_id, tenant_id)
                    REFERENCES learning_path_enrollments (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT learning_path_item_completions_item_fk
                    FOREIGN KEY (item_id, tenant_id)
                    REFERENCES learning_path_items (id, tenant_id) ON DELETE CASCADE
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS learning_path_item_completions');
        $this->addSql('DROP TABLE IF EXISTS learning_path_enrollments');
        $this->addSql('DROP TABLE IF EXISTS learning_path_items');
        $this->addSql('DROP TABLE IF EXISTS learning_path_sections');
        $this->addSql('DROP TABLE IF EXISTS learning_paths');
    }
}
