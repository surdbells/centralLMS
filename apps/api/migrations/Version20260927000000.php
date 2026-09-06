<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Allow 'agora' as a session meeting platform.
 *
 * In-app video (Agora) became the default meeting provider so a live class can
 * run on Central LMS itself rather than on an external Zoom/Meet/Teams link.
 * The provider layer writes the scheduled platform onto the session —
 * `saveMeeting()` sets `platform = 'agora'` — but the original
 * `training_sessions_platform_check` only admitted the three external
 * platforms, so booking an in-app session failed at the database with a check
 * violation. This widens the constraint to include 'agora'.
 */
final class Version20260927000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Allow 'agora' as a training_sessions meeting platform";
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration targets PostgreSQL.',
        );

        $this->addSql('ALTER TABLE training_sessions DROP CONSTRAINT training_sessions_platform_check');
        $this->addSql(<<<'SQL'
            ALTER TABLE training_sessions ADD CONSTRAINT training_sessions_platform_check CHECK (
                platform IS NULL OR platform IN ('zoom', 'google_meet', 'microsoft_teams', 'agora')
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Any in-app session would violate the narrower constraint, so clear the
        // platform on those rows first — the join URL/id remain, but the row no
        // longer claims a platform the constraint forbids.
        $this->addSql("UPDATE training_sessions SET platform = NULL WHERE platform = 'agora'");
        $this->addSql('ALTER TABLE training_sessions DROP CONSTRAINT training_sessions_platform_check');
        $this->addSql(<<<'SQL'
            ALTER TABLE training_sessions ADD CONSTRAINT training_sessions_platform_check CHECK (
                platform IS NULL OR platform IN ('zoom', 'google_meet', 'microsoft_teams')
            )
        SQL);
    }
}
