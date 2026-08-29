<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The narrative an individual competency report carries beyond the numbers.
 *
 * The assessment already holds required, self and expert levels per competency
 * and the gap between them — enough for the outcome report, which is about the
 * workforce. An individual's report is also a document a person reads and signs
 * off: it carries the employee's own account of the evidence, the line
 * executive's confirming level and decision, and the two paragraphs that make a
 * gap actionable — what the gaps are, and how they are to be closed.
 *
 * All nullable: an assessment part-way through the exercise simply has not
 * gathered them yet, and the outcome report neither reads nor needs them.
 */
final class Version20260917000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The narrative fields of an individual competency report';
    }

    public function up(Schema $schema): void
    {
        // The employee's own overall account, written when they self-assess.
        $this->addSql('ALTER TABLE competency_assessments ADD COLUMN self_comment TEXT');
        // The line manager's note, and the line executive's placement decision.
        $this->addSql('ALTER TABLE competency_assessments ADD COLUMN line_manager_comment TEXT');
        $this->addSql('ALTER TABLE competency_assessments ADD COLUMN line_executive_decision TEXT');
        // The two paragraphs that turn a set of gaps into a development plan.
        $this->addSql('ALTER TABLE competency_assessments ADD COLUMN gaps_identified TEXT');
        $this->addSql('ALTER TABLE competency_assessments ADD COLUMN gaps_closure_interventions TEXT');

        // The line executive's confirming level, alongside the SME's. Where it
        // is set it is what the gap is measured against; where it is not, the
        // SME's assessed level still stands, so the generated gap column is
        // left as it is.
        $this->addSql('ALTER TABLE competency_assessment_items ADD COLUMN line_executive_level SMALLINT');
        $this->addSql(<<<'SQL'
            ALTER TABLE competency_assessment_items
                ADD CONSTRAINT cai_line_exec_valid
                CHECK (line_executive_level IS NULL OR line_executive_level BETWEEN 0 AND 3)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competency_assessment_items DROP CONSTRAINT IF EXISTS cai_line_exec_valid');
        $this->addSql('ALTER TABLE competency_assessment_items DROP COLUMN IF EXISTS line_executive_level');
        $this->addSql('ALTER TABLE competency_assessments DROP COLUMN IF EXISTS gaps_closure_interventions');
        $this->addSql('ALTER TABLE competency_assessments DROP COLUMN IF EXISTS gaps_identified');
        $this->addSql('ALTER TABLE competency_assessments DROP COLUMN IF EXISTS line_executive_decision');
        $this->addSql('ALTER TABLE competency_assessments DROP COLUMN IF EXISTS line_manager_comment');
        $this->addSql('ALTER TABLE competency_assessments DROP COLUMN IF EXISTS self_comment');
    }
}
