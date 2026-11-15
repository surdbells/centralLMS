<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-person learning-plan items, and what an Excel import needs to keep them.
 *
 * Until now every item of a plan was shared by all of its members. A yearly
 * Learning Plan workbook is the opposite: one row per person, each with their
 * own trainings, providers and dates. So an item may now belong to one person
 * (`user_id`; NULL keeps today's "shared by every member" meaning), carry a
 * category (functional, conference, …), a provider and the planned dates, and
 * remember where it came from:
 *
 *  - `schedule_text` keeps the date as written ("November 17 - 19, 2026",
 *    "TBA"), because many cells hold no parseable date at all;
 *  - `planned_start` / `planned_end` are the parsed dates, when there are any;
 *  - `source` + `import_key` let a re-import update rather than duplicate: the
 *    key is a hash of (email, category, normalised title), unique per plan.
 *
 * The learner's own target date still lives in learning_plan_progress and
 * wins over the planned start when both are set.
 */
final class Version20261115100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Learning plans: per-person items with category, provider, planned dates and import identity';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration targets PostgreSQL.',
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE learning_plan_items
                ADD COLUMN user_id       UUID REFERENCES users (id) ON DELETE CASCADE,
                ADD COLUMN category      TEXT,
                ADD COLUMN provider      TEXT,
                ADD COLUMN schedule_text TEXT,
                ADD COLUMN planned_start DATE,
                ADD COLUMN planned_end   DATE,
                ADD COLUMN source        TEXT NOT NULL DEFAULT 'manual',
                ADD COLUMN import_key    TEXT,
                ADD COLUMN updated_at    TIMESTAMPTZ
        SQL);

        $this->addSql(
            "ALTER TABLE learning_plan_items ADD CONSTRAINT lpi_category_known
             CHECK (category IS NULL OR category IN
                 ('functional','conference','certification','behavioural','organisational','leadership','other'))",
        );
        $this->addSql(
            "ALTER TABLE learning_plan_items ADD CONSTRAINT lpi_source_known
             CHECK (source IN ('manual','import'))",
        );
        $this->addSql(
            'ALTER TABLE learning_plan_items ADD CONSTRAINT lpi_planned_range
             CHECK (planned_end IS NULL OR planned_start IS NULL OR planned_end >= planned_start)',
        );

        $this->addSql(
            'CREATE UNIQUE INDEX learning_plan_items_import_key_uniq
             ON learning_plan_items (plan_id, import_key)
             WHERE import_key IS NOT NULL',
        );
        $this->addSql(
            'CREATE INDEX learning_plan_items_plan_user_idx
             ON learning_plan_items (plan_id, user_id, sort_order)',
        );
        $this->addSql(
            'CREATE INDEX learning_plan_items_user_idx
             ON learning_plan_items (user_id)
             WHERE user_id IS NOT NULL',
        );
        $this->addSql(
            'CREATE INDEX learning_plans_tenant_year_title_idx
             ON learning_plans (tenant_id, year, lower(title))
             WHERE deleted_at IS NULL',
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration targets PostgreSQL.',
        );

        // Per-person items must not survive as shared ones: once user_id is
        // gone every member would see everyone's trainings. Their progress
        // rows cascade.
        $this->addSql('DELETE FROM learning_plan_items WHERE user_id IS NOT NULL');

        $this->addSql('DROP INDEX IF EXISTS learning_plans_tenant_year_title_idx');
        $this->addSql('DROP INDEX IF EXISTS learning_plan_items_user_idx');
        $this->addSql('DROP INDEX IF EXISTS learning_plan_items_plan_user_idx');
        $this->addSql('DROP INDEX IF EXISTS learning_plan_items_import_key_uniq');

        $this->addSql('ALTER TABLE learning_plan_items DROP CONSTRAINT IF EXISTS lpi_planned_range');
        $this->addSql('ALTER TABLE learning_plan_items DROP CONSTRAINT IF EXISTS lpi_source_known');
        $this->addSql('ALTER TABLE learning_plan_items DROP CONSTRAINT IF EXISTS lpi_category_known');

        $this->addSql(<<<'SQL'
            ALTER TABLE learning_plan_items
                DROP COLUMN IF EXISTS updated_at,
                DROP COLUMN IF EXISTS import_key,
                DROP COLUMN IF EXISTS source,
                DROP COLUMN IF EXISTS planned_end,
                DROP COLUMN IF EXISTS planned_start,
                DROP COLUMN IF EXISTS schedule_text,
                DROP COLUMN IF EXISTS provider,
                DROP COLUMN IF EXISTS category,
                DROP COLUMN IF EXISTS user_id
        SQL);
    }
}
