<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One table for standing enrolment rules, whatever they enrol people onto.
 *
 * `learning_path_assignments` was the right idea against one subject. Live
 * training needs the same rules — "everybody in Operations attends the fire
 * drill, including whoever joins next month" — and so, plainly, will courses.
 * Three tables with the same fifteen columns is three sweeps to keep in step,
 * three sets of constraints to remember, and three places for the answer to
 * "what does pausing a rule do" to drift apart.
 *
 * What differs between subjects is only which table the enrolment lands in.
 * That belongs in code, not in the schema.
 */
final class Version20260910090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Generalise path assignment rules into enrolment rules for any subject';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE enrollment_rules (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,

                -- What people are put onto. The id is not a foreign key,
                -- because it points at a different table per kind; the
                -- repository refuses a subject that does not exist, and the
                -- sweep skips a rule whose subject has gone.
                subject_kind TEXT NOT NULL,
                subject_id UUID NOT NULL,

                target_type TEXT NOT NULL DEFAULT 'all',
                target_criteria JSONB NOT NULL DEFAULT '{}'::jsonb,

                due_date DATE,
                due_days_from_enrollment INTEGER,

                priority TEXT NOT NULL DEFAULT 'normal',
                is_mandatory BOOLEAN NOT NULL DEFAULT FALSE,

                send_notification BOOLEAN NOT NULL DEFAULT TRUE,
                send_reminder BOOLEAN NOT NULL DEFAULT FALSE,
                reminder_days_before INTEGER NOT NULL DEFAULT 3,

                notes TEXT,
                status TEXT NOT NULL DEFAULT 'active',

                created_by UUID REFERENCES users (id) ON DELETE SET NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                last_applied_at TIMESTAMPTZ,
                updated_at TIMESTAMPTZ,
                deleted_at TIMESTAMPTZ,

                CONSTRAINT enrollment_rules_subject_known CHECK (
                    subject_kind IN ('path', 'training')
                ),
                CONSTRAINT enrollment_rules_target_known CHECK (
                    target_type IN ('all', 'role', 'department', 'branch', 'team', 'course', 'path', 'custom')
                ),
                CONSTRAINT enrollment_rules_priority_known CHECK (
                    priority IN ('low', 'normal', 'high', 'critical')
                ),
                CONSTRAINT enrollment_rules_status_known CHECK (
                    status IN ('active', 'paused', 'completed', 'cancelled')
                ),

                -- Everything but "everybody" has to say who. Without this a
                -- department rule with no department silently covers the
                -- whole organisation on its first sweep.
                CONSTRAINT enrollment_rules_target_named CHECK (
                    target_type = 'all' OR target_criteria <> '{}'::jsonb
                ),

                -- A fixed date or an offset, never both: two answers to "when
                -- is this due" is one too many.
                CONSTRAINT enrollment_rules_one_due_rule CHECK (
                    due_date IS NULL OR due_days_from_enrollment IS NULL
                ),
                CONSTRAINT enrollment_rules_offset_positive CHECK (
                    due_days_from_enrollment IS NULL OR due_days_from_enrollment > 0
                ),
                CONSTRAINT enrollment_rules_reminder_positive CHECK (
                    reminder_days_before >= 0
                )
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX enrollment_rules_subject_idx
                ON enrollment_rules (tenant_id, subject_kind, subject_id, status)
                WHERE deleted_at IS NULL
        SQL);

        // The sweep's index: the rules it has to consider each time it runs.
        $this->addSql(<<<'SQL'
            CREATE INDEX enrollment_rules_due_idx
                ON enrollment_rules (tenant_id, last_applied_at)
                WHERE status = 'active' AND deleted_at IS NULL
        SQL);

        // Carry the path rules across, ids and all, so the enrolments that
        // name them keep naming them.
        $this->addSql(<<<'SQL'
            INSERT INTO enrollment_rules
                (id, tenant_id, subject_kind, subject_id, target_type, target_criteria,
                 due_date, due_days_from_enrollment, priority, is_mandatory,
                 send_notification, send_reminder, reminder_days_before, notes, status,
                 created_by, created_at, last_applied_at, updated_at, deleted_at)
            SELECT id, tenant_id, 'path', path_id, target_type, target_criteria,
                   due_date, due_days_from_assignment, priority, is_mandatory,
                   send_notification, send_reminder, reminder_days_before, notes, status,
                   assigned_by, created_at, last_applied_at, updated_at, deleted_at
            FROM learning_path_assignments
        SQL);

        // --- the enrolments those rules made ---------------------------------

        $this->addSql(<<<'SQL'
            ALTER TABLE learning_path_enrollments
                DROP CONSTRAINT IF EXISTS learning_path_enrollments_assignment_id_fkey
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE learning_path_enrollments RENAME COLUMN assignment_id TO rule_id
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE learning_path_enrollments
                ADD CONSTRAINT learning_path_enrollments_rule_fk
                FOREIGN KEY (rule_id) REFERENCES enrollment_rules (id) ON DELETE SET NULL
        SQL);

        $this->addSql('DROP INDEX IF EXISTS lp_enrollments_assignment_idx');

        $this->addSql(<<<'SQL'
            CREATE INDEX lp_enrollments_rule_idx
                ON learning_path_enrollments (rule_id)
                WHERE rule_id IS NOT NULL AND deleted_at IS NULL
        SQL);

        // A training enrolment can come from a rule too, and needs to say so
        // for the same reason: without it, "how many people has this rule
        // enrolled" can only be guessed at from dates.
        $this->addSql(<<<'SQL'
            ALTER TABLE training_enrollments
                ADD COLUMN rule_id UUID REFERENCES enrollment_rules (id) ON DELETE SET NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX training_enrollments_rule_idx
                ON training_enrollments (rule_id)
                WHERE rule_id IS NOT NULL AND deleted_at IS NULL
        SQL);

        // A training enrolment also needs a deadline of its own, so a rule
        // saying "attend by the end of March" has somewhere to put it.
        $this->addSql('ALTER TABLE training_enrollments ADD COLUMN due_date DATE');
        $this->addSql(
            'ALTER TABLE training_enrollments ADD COLUMN is_mandatory BOOLEAN NOT NULL DEFAULT FALSE',
        );

        // --- how somebody arrived --------------------------------------------
        //
        // "assignment" was the path table's word for it. One word for both,
        // now that it is one feature — and the constraint has to go first,
        // because the rows it rejects are the ones being corrected.
        foreach (['learning_path_enrollments', 'training_enrollments'] as $table) {
            $constraint = $table === 'training_enrollments'
                ? 'training_enrollments_source_check'
                : 'learning_path_enrollments_source_known';

            $this->addSql(sprintf(
                'ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s',
                $table,
                $constraint,
            ));
        }

        $this->addSql("UPDATE learning_path_enrollments SET source = 'rule' WHERE source = 'assignment'");

        foreach (['learning_path_enrollments', 'training_enrollments'] as $table) {
            $constraint = $table === 'training_enrollments'
                ? 'training_enrollments_source_check'
                : 'learning_path_enrollments_source_known';

            $this->addSql(sprintf(
                "ALTER TABLE %s ADD CONSTRAINT %s CHECK (
                     source IN ('self', 'direct', 'bulk', 'team', 'branch', 'department', 'rule')
                 )",
                $table,
                $constraint,
            ));
        }

        $this->addSql('DROP TABLE IF EXISTS learning_path_assignments');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE learning_path_assignments (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                path_id UUID NOT NULL,
                target_type TEXT NOT NULL DEFAULT 'all',
                target_criteria JSONB NOT NULL DEFAULT '{}'::jsonb,
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
                    REFERENCES learning_paths (id, tenant_id) ON DELETE CASCADE
            )
        SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO learning_path_assignments
                (id, tenant_id, path_id, target_type, target_criteria, due_date,
                 due_days_from_assignment, priority, is_mandatory, send_notification,
                 send_reminder, reminder_days_before, notes, status, assigned_by,
                 created_at, last_applied_at, updated_at, deleted_at)
            SELECT id, tenant_id, subject_id, target_type, target_criteria, due_date,
                   due_days_from_enrollment, priority, is_mandatory, send_notification,
                   send_reminder, reminder_days_before, notes, status, created_by,
                   created_at, last_applied_at, updated_at, deleted_at
            FROM enrollment_rules WHERE subject_kind = 'path'
        SQL);

        $this->addSql('DROP INDEX IF EXISTS training_enrollments_rule_idx');
        $this->addSql('ALTER TABLE training_enrollments DROP COLUMN IF EXISTS rule_id');
        $this->addSql('ALTER TABLE training_enrollments DROP COLUMN IF EXISTS due_date');
        $this->addSql('ALTER TABLE training_enrollments DROP COLUMN IF EXISTS is_mandatory');

        $this->addSql('DROP INDEX IF EXISTS lp_enrollments_rule_idx');
        $this->addSql(<<<'SQL'
            ALTER TABLE learning_path_enrollments
                DROP CONSTRAINT IF EXISTS learning_path_enrollments_rule_fk
        SQL);
        $this->addSql('ALTER TABLE learning_path_enrollments RENAME COLUMN rule_id TO assignment_id');
        $this->addSql(<<<'SQL'
            ALTER TABLE learning_path_enrollments
                ADD CONSTRAINT learning_path_enrollments_assignment_id_fkey
                FOREIGN KEY (assignment_id) REFERENCES learning_path_assignments (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX lp_enrollments_assignment_idx
                ON learning_path_enrollments (assignment_id)
                WHERE assignment_id IS NOT NULL AND deleted_at IS NULL
        SQL);

        $this->addSql("UPDATE learning_path_enrollments SET source = 'assignment' WHERE source = 'rule'");
        $this->addSql("UPDATE training_enrollments SET source = 'direct' WHERE source = 'rule'");

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

        $this->addSql('ALTER TABLE training_enrollments DROP CONSTRAINT IF EXISTS training_enrollments_source_check');
        $this->addSql(<<<'SQL'
            ALTER TABLE training_enrollments
                ADD CONSTRAINT training_enrollments_source_check CHECK (
                    source IN ('self', 'direct', 'bulk', 'team', 'branch', 'department')
                )
        SQL);

        $this->addSql('DROP TABLE IF EXISTS enrollment_rules');
    }
}
