<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Standing assignment rules for learning paths.
 *
 * The screen has always offered them and the legacy never had a table, so
 * "assign this path to Operations" meant enrolling whoever was in Operations
 * that afternoon and nothing thereafter. A rule that does not outlive the
 * moment it was made is not a rule.
 *
 * Two things are kept apart deliberately:
 *
 * - The rule and the enrolments it produced. An enrolment names the rule that
 *   made it, so "who did this rule enrol" is a question with an answer, and
 *   cancelling a rule does not have to guess which enrolments to leave alone.
 * - `due_date` and `due_days_from_assignment`. A fixed date is the same for
 *   everybody; an offset is counted from each person's own enrolment. A rule
 *   carrying both has two answers to "when is this due", so the constraint
 *   below refuses it.
 */
final class Version20260909090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Standing assignment rules for learning paths';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE learning_path_assignments (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                path_id UUID NOT NULL,

                -- Who it covers, in the shared vocabulary every other feature
                -- that addresses a group uses.
                target_type TEXT NOT NULL DEFAULT 'all',
                target_criteria JSONB NOT NULL DEFAULT '{}'::jsonb,

                -- When it is due. One of the two, never both.
                due_date DATE,
                due_days_from_assignment INTEGER,

                priority TEXT NOT NULL DEFAULT 'normal',
                is_mandatory BOOLEAN NOT NULL DEFAULT FALSE,

                send_notification BOOLEAN NOT NULL DEFAULT TRUE,
                send_reminder BOOLEAN NOT NULL DEFAULT FALSE,
                reminder_days_before INTEGER NOT NULL DEFAULT 3,

                notes TEXT,
                status TEXT NOT NULL DEFAULT 'active',

                assigned_by UUID REFERENCES users (id) ON DELETE SET NULL,
                assigned_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                last_applied_at TIMESTAMPTZ,

                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ,
                deleted_at TIMESTAMPTZ,

                CONSTRAINT lp_assignments_path_fk
                    FOREIGN KEY (path_id, tenant_id)
                    REFERENCES learning_paths (id, tenant_id) ON DELETE CASCADE,

                CONSTRAINT lp_assignments_target_known CHECK (
                    target_type IN ('all', 'role', 'department', 'branch', 'team', 'course', 'path', 'custom')
                ),
                CONSTRAINT lp_assignments_priority_known CHECK (
                    priority IN ('low', 'normal', 'high', 'critical')
                ),
                CONSTRAINT lp_assignments_status_known CHECK (
                    status IN ('active', 'paused', 'completed', 'cancelled')
                ),

                -- Everything but "everybody" has to say who. Without this a
                -- department rule with no department silently covers the
                -- whole organisation.
                CONSTRAINT lp_assignments_target_named CHECK (
                    target_type = 'all' OR target_criteria <> '{}'::jsonb
                ),

                -- A fixed date or an offset, never both: two answers to "when
                -- is this due" is one too many.
                CONSTRAINT lp_assignments_one_due_rule CHECK (
                    due_date IS NULL OR due_days_from_assignment IS NULL
                ),
                CONSTRAINT lp_assignments_offset_positive CHECK (
                    due_days_from_assignment IS NULL OR due_days_from_assignment > 0
                ),
                CONSTRAINT lp_assignments_reminder_positive CHECK (
                    reminder_days_before >= 0
                )
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX lp_assignments_path_idx
                ON learning_path_assignments (tenant_id, path_id, status)
                WHERE deleted_at IS NULL
        SQL);

        // The sweep's index: the rules it has to consider each time it runs.
        $this->addSql(<<<'SQL'
            CREATE INDEX lp_assignments_due_idx
                ON learning_path_assignments (tenant_id, last_applied_at)
                WHERE status = 'active' AND deleted_at IS NULL
        SQL);

        // An enrolment remembers the rule that made it. Without it, "how many
        // people has this rule enrolled" can only be guessed at from dates,
        // and cancelling a rule cannot tell its own enrolments from anybody
        // else's.
        $this->addSql(<<<'SQL'
            ALTER TABLE learning_path_enrollments
                ADD COLUMN assignment_id UUID
                    REFERENCES learning_path_assignments (id) ON DELETE SET NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX lp_enrollments_assignment_idx
                ON learning_path_enrollments (assignment_id)
                WHERE assignment_id IS NOT NULL AND deleted_at IS NULL
        SQL);

        // A rule is a way of arriving on a path, and the column that records
        // how somebody arrived did not know about it. Left alone, every
        // enrolment a rule made would have to claim to be something else.
        $this->addSql(<<<'SQL'
            ALTER TABLE learning_path_enrollments
                DROP CONSTRAINT IF EXISTS learning_path_enrollments_source_known
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE learning_path_enrollments
                ADD CONSTRAINT learning_path_enrollments_source_known CHECK (
                    source IN ('self', 'direct', 'bulk', 'team', 'branch', 'department', 'assignment')
                )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE learning_path_enrollments
                DROP CONSTRAINT IF EXISTS learning_path_enrollments_source_known
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE learning_path_enrollments
                ADD CONSTRAINT learning_path_enrollments_source_known CHECK (
                    source IN ('self', 'direct', 'bulk', 'team', 'branch', 'department')
                )
        SQL);

        $this->addSql('DROP INDEX IF EXISTS lp_enrollments_assignment_idx');
        $this->addSql('ALTER TABLE learning_path_enrollments DROP COLUMN IF EXISTS assignment_id');
        $this->addSql('DROP TABLE IF EXISTS learning_path_assignments');
    }
}
