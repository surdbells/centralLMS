<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Competency assurance: what a job needs, what a person has, and the gap.
 *
 * A new module, modelled on how the organisation already runs it — a
 * competency dictionary per function, a job competency profile (JCP) that says
 * which competencies a role needs and to what level, and an assessment that
 * measures a person against the profile of their job. The gap between required
 * and demonstrated is the whole point of the exercise, so it is a stored,
 * generated fact rather than something each report recomputes its own way.
 *
 * The proficiency scale is the three the exercise uses — Knowledge, Skill,
 * Mastery — with nought for "not demonstrated". A gap of one level is Minor,
 * two Major, three Significant, which is the categorisation the outcome report
 * is built on.
 */
final class Version20260914090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Competency assurance: competencies, job profiles, assessments and gaps';
    }

    public function up(Schema $schema): void
    {
        // --- the dictionary ---------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE competencies (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,

                -- The function it belongs to — CASHES, Finance, Production
                -- Technology. A grouping the exercise runs by, not the org's
                -- department structure, so it is a name rather than a lookup.
                function_name TEXT NOT NULL,

                name TEXT NOT NULL,
                description TEXT,

                -- What each level looks like for this competency: the proof
                -- points an assessor reads against. Optional — an imported
                -- competency may have them, a hand-added one may not yet.
                knowledge_descriptor TEXT,
                skill_descriptor TEXT,
                mastery_descriptor TEXT,

                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ,
                deleted_at TIMESTAMPTZ,

                CONSTRAINT competencies_name_present CHECK (BTRIM(name) <> '')
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX competencies_unique
                ON competencies (tenant_id, function_name, LOWER(name))
                WHERE deleted_at IS NULL
        SQL);

        // A composite key other tables reference within the tenant.
        $this->addSql(<<<'SQL'
            ALTER TABLE competencies ADD CONSTRAINT competencies_id_tenant_uniq UNIQUE (id, tenant_id)
        SQL);

        // --- job competency profiles ------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE job_competency_profiles (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,

                -- The role this profile is for, and the function it sits in.
                -- Job title is a name because the exercise's grades (Executive
                -- Management, Professional Staff) are not the org's job-title
                -- lookup, and forcing them into it would lose the distinction.
                job_title TEXT NOT NULL,
                function_name TEXT NOT NULL,
                description TEXT,

                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ,
                deleted_at TIMESTAMPTZ,

                CONSTRAINT jcp_title_present CHECK (BTRIM(job_title) <> '')
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX jcp_unique
                ON job_competency_profiles (tenant_id, function_name, LOWER(job_title))
                WHERE deleted_at IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE job_competency_profiles ADD CONSTRAINT jcp_id_tenant_uniq UNIQUE (id, tenant_id)
        SQL);

        // What the profile requires: a competency, and the level a person in
        // the role must reach.
        $this->addSql(<<<'SQL'
            CREATE TABLE job_competency_requirements (
                profile_id UUID NOT NULL,
                competency_id UUID NOT NULL,
                tenant_id UUID NOT NULL,

                -- 1 Knowledge, 2 Skill, 3 Mastery. Nought is not a requirement
                -- — a competency the role does not need is simply not listed.
                required_level SMALLINT NOT NULL,

                -- The few the role cannot do without, for a report that leads
                -- with the gaps that matter most.
                is_critical BOOLEAN NOT NULL DEFAULT FALSE,

                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                PRIMARY KEY (profile_id, competency_id),

                CONSTRAINT jcr_profile_fk
                    FOREIGN KEY (profile_id, tenant_id)
                    REFERENCES job_competency_profiles (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT jcr_competency_fk
                    FOREIGN KEY (competency_id, tenant_id)
                    REFERENCES competencies (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT jcr_level_valid CHECK (required_level BETWEEN 1 AND 3)
            )
        SQL);

        $this->addSql('CREATE INDEX jcr_competency_idx ON job_competency_requirements (competency_id)');

        // --- assessments ------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE competency_assessments (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,

                user_id UUID NOT NULL,
                profile_id UUID NOT NULL,

                -- The exercise it belongs to — "2025 Competency Assurance".
                cycle TEXT NOT NULL DEFAULT 'default',

                -- Where it is: the learner self-assesses, an external SME
                -- reviews, and it is finalised. The status is not decoration —
                -- a gap is only trustworthy once an expert has confirmed the
                -- level, so the report counts finalised assessments.
                status TEXT NOT NULL DEFAULT 'draft',

                -- The subject-matter expert who reviewed it, and their note.
                assessor_id UUID,
                assessor_comment TEXT,

                self_submitted_at TIMESTAMPTZ,
                reviewed_at TIMESTAMPTZ,
                finalized_at TIMESTAMPTZ,

                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ,
                deleted_at TIMESTAMPTZ,

                CONSTRAINT ca_user_fk
                    FOREIGN KEY (user_id, tenant_id) REFERENCES users (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT ca_profile_fk
                    FOREIGN KEY (profile_id, tenant_id)
                    REFERENCES job_competency_profiles (id, tenant_id) ON DELETE RESTRICT,
                CONSTRAINT ca_assessor_fk FOREIGN KEY (assessor_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT ca_status_known CHECK (
                    status IN ('draft', 'self_assessed', 'reviewed', 'finalized')
                ),
                -- A finalised assessment must have been reviewed and dated:
                -- "finalised but nobody looked at it" is the state that makes a
                -- compliance figure a lie.
                CONSTRAINT ca_finalized_reviewed CHECK (
                    status <> 'finalized' OR (assessor_id IS NOT NULL AND finalized_at IS NOT NULL)
                )
            )
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE competency_assessments ADD CONSTRAINT ca_id_tenant_uniq UNIQUE (id, tenant_id)
        SQL);
        // One live assessment per person per profile per cycle: two averages
        // beside a name mean nothing.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX ca_once
                ON competency_assessments (tenant_id, user_id, profile_id, cycle)
                WHERE deleted_at IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX ca_cycle_idx ON competency_assessments (tenant_id, cycle, status)
                WHERE deleted_at IS NULL
        SQL);

        // Per competency: what was required, what the person said, what the
        // expert confirmed — and the gap, generated so it cannot drift from
        // the two levels it is computed from.
        $this->addSql(<<<'SQL'
            CREATE TABLE competency_assessment_items (
                assessment_id UUID NOT NULL,
                competency_id UUID NOT NULL,
                tenant_id UUID NOT NULL,

                required_level SMALLINT NOT NULL,
                self_level SMALLINT,
                assessed_level SMALLINT,

                -- The gap the report is built on: required minus what the
                -- expert confirmed, never below nought (exceeding the
                -- requirement is not a negative gap, it is met). Falls back to
                -- the self level until an expert has reviewed.
                gap SMALLINT GENERATED ALWAYS AS (
                    GREATEST(required_level - COALESCE(assessed_level, self_level, 0), 0)
                ) STORED,

                comment TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                PRIMARY KEY (assessment_id, competency_id),

                CONSTRAINT cai_assessment_fk
                    FOREIGN KEY (assessment_id, tenant_id)
                    REFERENCES competency_assessments (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT cai_competency_fk
                    FOREIGN KEY (competency_id, tenant_id)
                    REFERENCES competencies (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT cai_required_valid CHECK (required_level BETWEEN 1 AND 3),
                CONSTRAINT cai_self_valid CHECK (self_level IS NULL OR self_level BETWEEN 0 AND 3),
                CONSTRAINT cai_assessed_valid CHECK (assessed_level IS NULL OR assessed_level BETWEEN 0 AND 3)
            )
        SQL);

        $this->addSql('CREATE INDEX cai_competency_idx ON competency_assessment_items (competency_id)');
        $this->addSql('CREATE INDEX cai_gap_idx ON competency_assessment_items (tenant_id, gap) WHERE gap > 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS competency_assessment_items');
        $this->addSql('DROP TABLE IF EXISTS competency_assessments');
        $this->addSql('DROP TABLE IF EXISTS job_competency_requirements');
        $this->addSql('DROP TABLE IF EXISTS job_competency_profiles');
        $this->addSql('DROP TABLE IF EXISTS competencies');
    }
}
