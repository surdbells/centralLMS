<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Turns the course discussion from a plain Q&A into a proper forum.
 *
 * A thread can be pinned to the top and locked against new replies; a reply can
 * be marked the accepted answer (one per thread), which lets the question read
 * as resolved; a post can be edited, and says when it was. Reactions — an
 * upvote and a few feelings — are rows, not counters, so one person cannot
 * react forty times and "who found this useful" is answerable.
 */
final class Version20261111030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forum upgrade: pin/lock threads, accepted answers, edits, reactions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE course_discussion_posts
            ADD COLUMN is_answer BOOLEAN NOT NULL DEFAULT FALSE,
            ADD COLUMN is_pinned BOOLEAN NOT NULL DEFAULT FALSE,
            ADD COLUMN is_locked BOOLEAN NOT NULL DEFAULT FALSE,
            ADD COLUMN edited_at TIMESTAMPTZ');

        // One accepted answer per thread. Partial-unique on the parent so a
        // top-level post (parent_id NULL) is unaffected and only one reply per
        // question can carry the flag.
        $this->addSql(
            'CREATE UNIQUE INDEX course_discussion_one_answer
             ON course_discussion_posts (parent_id)
             WHERE is_answer AND parent_id IS NOT NULL AND deleted_at IS NULL',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE discussion_reactions (
                post_id    UUID NOT NULL REFERENCES course_discussion_posts (id) ON DELETE CASCADE,
                user_id    UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                tenant_id  UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                kind       TEXT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                PRIMARY KEY (post_id, user_id, kind),

                CONSTRAINT discussion_reactions_kind_known
                    CHECK (kind IN ('up', 'heart', 'celebrate', 'insightful'))
            )
        SQL);

        $this->addSql(
            'CREATE INDEX discussion_reactions_post_idx ON discussion_reactions (post_id, kind)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS discussion_reactions');
        $this->addSql('DROP INDEX IF EXISTS course_discussion_one_answer');
        $this->addSql('ALTER TABLE course_discussion_posts
            DROP COLUMN IF EXISTS is_answer,
            DROP COLUMN IF EXISTS is_pinned,
            DROP COLUMN IF EXISTS is_locked,
            DROP COLUMN IF EXISTS edited_at');
    }
}
