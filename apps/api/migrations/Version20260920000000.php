<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A sitting-level review verdict on a proctoring session.
 *
 * The session already carries reviewed_by/reviewed_at/review_notes, but no
 * verdict — so a reviewer could leave a note yet not record whether they
 * cleared or flagged the sitting. This adds that verdict, mirroring the
 * review_status the violations table already has.
 */
final class Version20260920000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sitting-level review verdict on proctoring_sessions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE proctoring_sessions
                ADD COLUMN review_status text NOT NULL DEFAULT 'pending',
                ADD CONSTRAINT proctoring_sessions_review_status_check
                    CHECK (review_status IN ('pending', 'cleared', 'flagged'))
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE proctoring_sessions DROP COLUMN IF EXISTS review_status');
    }
}
