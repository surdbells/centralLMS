<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The assessment settings the rewrite had not carried across yet.
 *
 * The authoring form offers more than the model stored: a welcome and a
 * completion message, negative marking, partial credit, how many questions to
 * a page, whether to number them and show a progress bar, and a grading scale.
 * These are configuration the old system kept and the new one dropped on the
 * floor — so an editor could set them and a save would quietly forget them.
 * Each becomes a column, defaulted to the behaviour the code already assumed.
 */
final class Version20260915090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Carry the remaining assessment settings into the model';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE assessments
                ADD COLUMN welcome_message TEXT,
                ADD COLUMN completion_message TEXT,
                -- Negative marking: off by default, and when on it takes a
                -- share of the question's points away for a wrong answer.
                ADD COLUMN negative_marking BOOLEAN NOT NULL DEFAULT FALSE,
                ADD COLUMN negative_marking_percentage NUMERIC(5,2) NOT NULL DEFAULT 0,
                -- Partial credit on multi-answer questions, on by default as the
                -- grader already assumed.
                ADD COLUMN partial_credit BOOLEAN NOT NULL DEFAULT TRUE,
                -- One question to a page unless told otherwise.
                ADD COLUMN questions_per_page INTEGER NOT NULL DEFAULT 1,
                ADD COLUMN show_question_numbers BOOLEAN NOT NULL DEFAULT TRUE,
                ADD COLUMN show_progress_bar BOOLEAN NOT NULL DEFAULT TRUE,
                -- The grading scale a score is banded through. A soft reference:
                -- grading scales are their own resource, not yet built, so this
                -- holds the choice without a foreign key to enforce it yet.
                ADD COLUMN grading_scale_id UUID
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE assessments
                ADD CONSTRAINT assessments_negative_marking_pct
                    CHECK (negative_marking_percentage >= 0 AND negative_marking_percentage <= 100),
                ADD CONSTRAINT assessments_questions_per_page
                    CHECK (questions_per_page >= 1)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE assessments
                DROP CONSTRAINT IF EXISTS assessments_negative_marking_pct,
                DROP CONSTRAINT IF EXISTS assessments_questions_per_page,
                DROP COLUMN IF EXISTS welcome_message,
                DROP COLUMN IF EXISTS completion_message,
                DROP COLUMN IF EXISTS negative_marking,
                DROP COLUMN IF EXISTS negative_marking_percentage,
                DROP COLUMN IF EXISTS partial_credit,
                DROP COLUMN IF EXISTS questions_per_page,
                DROP COLUMN IF EXISTS show_question_numbers,
                DROP COLUMN IF EXISTS show_progress_bar,
                DROP COLUMN IF EXISTS grading_scale_id
        SQL);
    }
}
