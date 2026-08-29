<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Who has to take an assessment.
 *
 * The authoring form has an "Assignments" tab — assign this to a person, a
 * department, a team, a branch, or everyone, with a due date — and nothing
 * behind it on the new API. One row per assignment: a target, or none when it
 * is assigned to everyone. Reaching a course through its enrolment is one path
 * to an assessment; this is the other, the deliberate "you must sit this".
 */
final class Version20260916090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Assessment assignments — who is required to take one';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE assessment_assignments (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                assessment_id UUID NOT NULL,

                -- Who it lands on. 'all' is everyone in the tenant and carries
                -- no target; the others each name one.
                assignment_type TEXT NOT NULL,
                target_id UUID,

                due_date TIMESTAMPTZ,

                created_by UUID,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT assessment_assignments_assessment_fk
                    FOREIGN KEY (assessment_id, tenant_id)
                    REFERENCES assessments (id, tenant_id) ON DELETE CASCADE,

                CONSTRAINT assessment_assignments_type
                    CHECK (assignment_type IN ('user', 'department', 'team', 'branch', 'all')),

                -- 'all' has no target; everything else must name one.
                CONSTRAINT assessment_assignments_target
                    CHECK (
                        (assignment_type = 'all' AND target_id IS NULL)
                        OR (assignment_type <> 'all' AND target_id IS NOT NULL)
                    )
            )
        SQL);

        // The same assessment cannot be assigned to the same target twice.
        // Two partial indexes because a NULL target (everyone) is not caught
        // by a plain unique over the nullable column.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX assessment_assignments_target_uniq
                ON assessment_assignments (assessment_id, assignment_type, target_id)
                WHERE target_id IS NOT NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX assessment_assignments_all_uniq
                ON assessment_assignments (assessment_id)
                WHERE assignment_type = 'all'
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX assessment_assignments_lookup_idx
                ON assessment_assignments (tenant_id, assessment_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS assessment_assignments');
    }
}
