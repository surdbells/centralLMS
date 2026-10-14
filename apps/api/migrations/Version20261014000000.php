<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The line manager becomes the final, authoritative stage of an assessment.
 *
 * The assessment runs Self -> SME -> Line Manager, each stage unlocking after
 * the one before. The line manager's confirming level is now what the gap is
 * measured against, falling back to the SME's level and then the self-claim.
 * Postgres cannot alter a generated column's expression in place, so the `gap`
 * column (and its partial index) are dropped and recreated with the new
 * COALESCE. A `line_manager_id` column records who performed the final stage,
 * kept distinct from the SME in `assessor_id`, so a finalised assessment names
 * both reviewers.
 */
final class Version20261014000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Line manager is the final assessment stage; gap measured against their level';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS cai_gap_idx');
        $this->addSql('ALTER TABLE competency_assessment_items DROP COLUMN gap');
        $this->addSql(<<<'SQL'
            ALTER TABLE competency_assessment_items
                ADD COLUMN gap SMALLINT GENERATED ALWAYS AS (
                    GREATEST(
                        required_level - COALESCE(line_executive_level, assessed_level, self_level, 0),
                        0
                    )
                ) STORED
        SQL);
        $this->addSql('CREATE INDEX cai_gap_idx ON competency_assessment_items (tenant_id, gap) WHERE gap > 0');

        $this->addSql('ALTER TABLE competency_assessments ADD COLUMN line_manager_id UUID');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competency_assessments DROP COLUMN IF EXISTS line_manager_id');

        $this->addSql('DROP INDEX IF EXISTS cai_gap_idx');
        $this->addSql('ALTER TABLE competency_assessment_items DROP COLUMN gap');
        $this->addSql(<<<'SQL'
            ALTER TABLE competency_assessment_items
                ADD COLUMN gap SMALLINT GENERATED ALWAYS AS (
                    GREATEST(required_level - COALESCE(assessed_level, self_level, 0), 0)
                ) STORED
        SQL);
        $this->addSql('CREATE INDEX cai_gap_idx ON competency_assessment_items (tenant_id, gap) WHERE gap > 0');
    }
}
