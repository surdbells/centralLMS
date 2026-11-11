<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Moderation for the course forum: reporting and hiding.
 *
 * Anyone on a course can report a post they think breaks the rules, with a
 * reason; a moderator can then hide it. Hidden posts stay in the table — a
 * removed post that vanishes entirely looks like a bug to the person who wrote
 * it and leaves a reply chain with a hole in it — but their body is withheld
 * from everyone but a moderator, who can put it back.
 */
final class Version20261111040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forum moderation: report posts and hide them';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE course_discussion_posts
            ADD COLUMN is_hidden BOOLEAN NOT NULL DEFAULT FALSE');

        $this->addSql(<<<'SQL'
            CREATE TABLE discussion_reports (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                post_id     UUID NOT NULL REFERENCES course_discussion_posts (id) ON DELETE CASCADE,
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                reporter_id UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                reason      TEXT NOT NULL DEFAULT '',
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                resolved_at TIMESTAMPTZ,

                -- One open report per person per post: reporting twice is one voice.
                CONSTRAINT discussion_reports_one_open
                    UNIQUE (post_id, reporter_id)
            )
        SQL);

        // The moderator view reads open reports by post.
        $this->addSql(
            'CREATE INDEX discussion_reports_open_idx
             ON discussion_reports (post_id) WHERE resolved_at IS NULL',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS discussion_reports');
        $this->addSql('ALTER TABLE course_discussion_posts DROP COLUMN IF EXISTS is_hidden');
    }
}
