<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Did the training change anything on the job? (Kirkpatrick level 3.)
 *
 * Completion and a pass say somebody sat the course and could answer the
 * questions; neither says their work changed. That judgement belongs to the
 * one person who sees the work — the line manager — so this records a manager's
 * evaluation of a direct report after they finished a course or a learning
 * plan: a behaviour-change rating and, optionally, what changed.
 *
 * One standing evaluation per (manager, report, course) and per (manager,
 * report, plan): re-submitting updates it rather than stacking, so the average
 * is of people, not of edits.
 */
final class Version20261111020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Supervisor behaviour-change feedback (L3) on completed training';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE behaviour_feedback (
                id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id  UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                -- The learner whose on-the-job behaviour is being evaluated…
                user_id    UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                -- …and the manager doing the evaluating.
                manager_id UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                -- The training it is about: a course or a learning plan.
                course_id  UUID REFERENCES courses (id) ON DELETE SET NULL,
                plan_id    UUID REFERENCES learning_plans (id) ON DELETE SET NULL,

                rating     SMALLINT NOT NULL,
                comments   TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ,

                CONSTRAINT behaviour_feedback_rating_range CHECK (rating BETWEEN 1 AND 5),
                CONSTRAINT behaviour_feedback_context
                    CHECK (course_id IS NOT NULL OR plan_id IS NOT NULL)
            )
        SQL);

        $this->addSql(
            'CREATE UNIQUE INDEX behaviour_feedback_course_uniq
             ON behaviour_feedback (manager_id, user_id, course_id)
             WHERE course_id IS NOT NULL',
        );

        $this->addSql(
            'CREATE UNIQUE INDEX behaviour_feedback_plan_uniq
             ON behaviour_feedback (manager_id, user_id, plan_id)
             WHERE plan_id IS NOT NULL',
        );

        $this->addSql(
            'CREATE INDEX behaviour_feedback_tenant_idx
             ON behaviour_feedback (tenant_id, created_at)',
        );

        $this->addSql(
            'CREATE INDEX behaviour_feedback_subject_idx
             ON behaviour_feedback (user_id)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS behaviour_feedback');
    }
}
