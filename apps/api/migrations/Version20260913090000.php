<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A place to talk, next to the course you are taking.
 *
 * The course player has a "Discuss" tab with nothing behind it. A learner
 * stuck on a lesson has nowhere to ask, and the person who could answer never
 * hears the question. One table: posts against a course, one level of replies,
 * which is as much threading as a course discussion needs and less than a
 * forum nobody moderates becomes.
 */
final class Version20260913090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A discussion thread attached to each course';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE course_discussion_posts (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                course_id UUID NOT NULL,
                user_id UUID NOT NULL,

                -- A reply names its parent; a top-level post does not. One
                -- level only: a reply to a reply is still a reply to the post,
                -- which keeps the thread readable.
                parent_id UUID,

                -- The lesson it was asked from, when it was. Lets the player
                -- show "3 questions on this lesson" without a second store.
                lesson_id UUID,

                body TEXT NOT NULL,

                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ,
                deleted_at TIMESTAMPTZ,

                CONSTRAINT course_discussion_course_fk
                    FOREIGN KEY (course_id, tenant_id)
                    REFERENCES courses (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT course_discussion_user_fk
                    FOREIGN KEY (user_id, tenant_id)
                    REFERENCES users (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT course_discussion_parent_fk
                    FOREIGN KEY (parent_id)
                    REFERENCES course_discussion_posts (id) ON DELETE CASCADE,

                CONSTRAINT course_discussion_body_present CHECK (BTRIM(body) <> ''),
                -- A reply cannot be its own parent, and — enforced in the
                -- repository — a reply's parent must be a top-level post.
                CONSTRAINT course_discussion_not_self_parent CHECK (parent_id <> id)
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX course_discussion_thread_idx
                ON course_discussion_posts (tenant_id, course_id, created_at DESC)
                WHERE deleted_at IS NULL AND parent_id IS NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX course_discussion_replies_idx
                ON course_discussion_posts (parent_id, created_at)
                WHERE deleted_at IS NULL AND parent_id IS NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS course_discussion_posts');
    }
}
