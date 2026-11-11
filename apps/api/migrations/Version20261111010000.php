<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The per-year Learning Plan: what an organisation intends its people to learn
 * this year.
 *
 * A plan belongs to a year and holds an ordered list of items — each a course
 * or a free-text intervention (a workshop, a secondment, a reading). People are
 * assigned to a plan, and each assignee has a status per item: Not Scheduled,
 * Scheduled (a target date is set), In Progress or Completed.
 *
 * Status is not stored twice. For a course item it is read from the person's
 * enrolment at display time — storing it here would drift the moment they make
 * progress. The per-person row keeps only what the enrolment cannot say: the
 * target date, and, for a non-course intervention (which has no enrolment), the
 * status the person or an administrator set by hand.
 */
final class Version20261111010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Per-year learning plans: items, assignees and per-person scheduling';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE learning_plans (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                year        SMALLINT NOT NULL,
                title       TEXT NOT NULL,
                description TEXT,
                status      TEXT NOT NULL DEFAULT 'draft',
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMPTZ,
                deleted_at  TIMESTAMPTZ,

                CONSTRAINT learning_plans_status_known
                    CHECK (status IN ('draft', 'published', 'archived')),
                CONSTRAINT learning_plans_year_sane
                    CHECK (year BETWEEN 2000 AND 2100)
            )
        SQL);

        $this->addSql(
            'CREATE INDEX learning_plans_tenant_year_idx
             ON learning_plans (tenant_id, year)
             WHERE deleted_at IS NULL',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE learning_plan_items (
                id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id    UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                plan_id      UUID NOT NULL REFERENCES learning_plans (id) ON DELETE CASCADE,
                -- A course the item points at, or null for a non-course
                -- intervention described by title/description.
                course_id    UUID REFERENCES courses (id) ON DELETE SET NULL,
                title        TEXT NOT NULL,
                description  TEXT,
                is_mandatory BOOLEAN NOT NULL DEFAULT FALSE,
                sort_order   INTEGER NOT NULL DEFAULT 0,
                created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        SQL);

        $this->addSql(
            'CREATE INDEX learning_plan_items_plan_idx
             ON learning_plan_items (plan_id, sort_order)',
        );

        // Who the plan is for. Explicit membership: an administrator assigns
        // people (directly or a whole department, expanded at assign time), so
        // "who has this plan" is a join, not an audience re-evaluated on read.
        $this->addSql(<<<'SQL'
            CREATE TABLE learning_plan_members (
                plan_id     UUID NOT NULL REFERENCES learning_plans (id) ON DELETE CASCADE,
                user_id     UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                assigned_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                PRIMARY KEY (plan_id, user_id)
            )
        SQL);

        $this->addSql(
            'CREATE INDEX learning_plan_members_user_idx
             ON learning_plan_members (user_id)',
        );

        // Per-person, per-item: the target date, and the hand-set status for a
        // non-course intervention. A course item leaves status null and takes
        // it from the enrolment instead.
        $this->addSql(<<<'SQL'
            CREATE TABLE learning_plan_progress (
                id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id      UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                plan_item_id   UUID NOT NULL REFERENCES learning_plan_items (id) ON DELETE CASCADE,
                user_id        UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                scheduled_date DATE,
                -- For interventions only: not_scheduled / scheduled / in_progress / completed.
                status         TEXT,
                completed_at   TIMESTAMPTZ,
                updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT lpp_status_known
                    CHECK (status IS NULL OR status IN ('not_scheduled', 'scheduled', 'in_progress', 'completed')),
                CONSTRAINT lpp_item_user_uniq UNIQUE (plan_item_id, user_id)
            )
        SQL);

        $this->addSql(
            'CREATE INDEX learning_plan_progress_user_idx
             ON learning_plan_progress (user_id)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS learning_plan_progress');
        $this->addSql('DROP TABLE IF EXISTS learning_plan_members');
        $this->addSql('DROP TABLE IF EXISTS learning_plan_items');
        $this->addSql('DROP TABLE IF EXISTS learning_plans');
    }
}
