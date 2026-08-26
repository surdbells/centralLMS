<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Messages between people in an organisation.
 *
 * Three tables where the legacy had eleven plus a view, and one content column
 * where it had three.
 *
 * ## `content`, `content_html` and `content_plain`
 *
 * Three columns holding one string. In all nine messages they are identical,
 * character for character. Nothing ever rendered one differently from another,
 * and nothing decided which to read. One column.
 *
 * ## Eight tables with nothing in them
 *
 *  - **`message_attachments`** — a real feature, and the media module is where
 *    it belongs: an attachment is an uploaded file, and there is now one place
 *    that stores those. Adding it means a join table from messages to `media`,
 *    not a second file store.
 *  - **`message_reactions`** — a real feature, and a small one. Not built here
 *    because nothing in the frontend renders a reaction.
 *  - **`message_read_receipts`** — per-recipient per-message rows. What people
 *    want from a conversation is "what have I not read", which is one timestamp
 *    per participant, not one row per person per message.
 *  - **`message_mentions`** — needs a composer that can parse them first.
 *  - **`message_drafts`**, **`saved_messages`**, **`message_reports`** — never
 *    written, and each needs a screen that does not exist.
 *  - **`broadcast_messages`** — this is `notification.send`, which the
 *    notification module already does, with delivery tracking and per-person
 *    preferences. A second announcement mechanism is the thing this port keeps
 *    removing.
 *  - **`vw_user_conversations`** — a view, which is to say a query.
 *
 * ## Counters and flags that are not carried
 *
 * `conversations.message_count`, `last_message_id` and `last_message_at` are
 * counted and looked up. So is `conversation_participants.unread_count`, which
 * is "messages since I last read" and needs one timestamp rather than a number
 * every send has to fan out an UPDATE to maintain.
 *
 * The six `can_*` flags on a participant are **zero on every row, including
 * every owner's** — the permission model was declared and never used. The role
 * decides. The four `notify_*` flags duplicate the notification module's
 * per-person preferences.
 *
 * On a message: `thread_root_id`, `thread_reply_count`, `thread_last_reply_at`,
 * `forwarded_from_id`, `is_pinned`, `pinned_at`, `pinned_by`, `priority`,
 * `scheduled_at` and `is_scheduled` are null or zero in all nine rows. Threads,
 * forwarding, pinning, priorities and scheduling were designed and never used.
 * `reply_to_id` **is** used, so it stays.
 *
 * On a conversation: `avatar_url` is never set, `is_private` is 1 everywhere,
 * `is_readonly` and the three `allow_*` flags are at their defaults everywhere,
 * and `linked_entity_type`/`linked_entity_id` are null everywhere.
 */
final class Version20260830130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Conversations and messages';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'PostgreSQL only.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE conversations (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id   BIGINT,
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,

                kind        TEXT NOT NULL DEFAULT 'direct',
                name        TEXT,
                description TEXT,

                -- Two people, in a fixed order, for the unique index below.
                -- Null for anything that is not a direct conversation.
                direct_key  TEXT,

                created_by  UUID,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                archived_at TIMESTAMPTZ,

                CONSTRAINT conversations_kind_known
                    CHECK (kind IN ('direct', 'group', 'channel')),

                -- A group needs a name and a direct conversation has none:
                -- it is named by whoever else is in it.
                CONSTRAINT conversations_named_when_it_should_be CHECK (
                    (kind = 'direct' AND name IS NULL AND direct_key IS NOT NULL)
                    OR (kind <> 'direct' AND btrim(COALESCE(name, '')) <> '' AND direct_key IS NULL)
                ),

                CONSTRAINT conversations_creator_fk
                    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            )
        SQL);

        $this->addSql('CREATE UNIQUE INDEX conversations_id_tenant_uniq ON conversations (id, tenant_id)');

        // One direct conversation per pair. The legacy had four of them and
        // nothing stopping a fifth between a pair that already had one — which
        // splits a conversation in half and hides the older one.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX conversations_one_per_pair
                ON conversations (tenant_id, direct_key)
                WHERE direct_key IS NOT NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE conversation_participants (
                conversation_id UUID NOT NULL,
                user_id         UUID NOT NULL,
                tenant_id       UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,

                role            TEXT NOT NULL DEFAULT 'member',

                -- What "unread" is measured from. One timestamp rather than a
                -- counter every send has to fan out an UPDATE to keep true.
                last_read_at    TIMESTAMPTZ,

                -- Per-person view state, which is genuinely per person.
                is_muted        BOOLEAN NOT NULL DEFAULT FALSE,
                is_pinned       BOOLEAN NOT NULL DEFAULT FALSE,

                joined_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
                left_at         TIMESTAMPTZ,

                PRIMARY KEY (conversation_id, user_id),

                CONSTRAINT conversation_participants_role_known
                    CHECK (role IN ('member', 'admin', 'owner')),

                CONSTRAINT conversation_participants_conversation_fk
                    FOREIGN KEY (conversation_id, tenant_id)
                    REFERENCES conversations (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT conversation_participants_user_fk
                    FOREIGN KEY (user_id, tenant_id) REFERENCES users (id, tenant_id) ON DELETE CASCADE
            )
        SQL);

        // "My conversations", which is the only way this table is read.
        $this->addSql(<<<'SQL'
            CREATE INDEX conversation_participants_mine_idx
                ON conversation_participants (tenant_id, user_id)
                WHERE left_at IS NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE messages (
                id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id       BIGINT,
                tenant_id       UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                conversation_id UUID NOT NULL,

                -- Null for a system message: "Ada added Grace" has no author.
                sender_id       UUID,

                -- One column. The legacy had three, holding the same string in
                -- all nine of its messages.
                content         TEXT NOT NULL,

                kind            TEXT NOT NULL DEFAULT 'text',

                -- Replies are used; threads, forwarding and pinning are not.
                reply_to_id     UUID,

                edited_at       TIMESTAMPTZ,
                deleted_at      TIMESTAMPTZ,
                deleted_by      UUID REFERENCES users (id) ON DELETE SET NULL,

                created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),

                CONSTRAINT messages_kind_known CHECK (kind IN ('text', 'system')),
                CONSTRAINT messages_content_present CHECK (btrim(content) <> ''),

                -- A system message has no sender; a text message does.
                CONSTRAINT messages_sender_matches_kind
                    CHECK ((kind = 'system') = (sender_id IS NULL)),

                -- Deleting a message keeps the row and blanks it on read, so
                -- the replies to it still make sense. Who did it is recorded,
                -- because a moderator removing somebody else's message is a
                -- different act from somebody removing their own.
                CONSTRAINT messages_deleted_consistent
                    CHECK (deleted_at IS NOT NULL OR deleted_by IS NULL),

                CONSTRAINT messages_conversation_fk
                    FOREIGN KEY (conversation_id, tenant_id)
                    REFERENCES conversations (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT messages_sender_fk
                    FOREIGN KEY (sender_id, tenant_id) REFERENCES users (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT messages_reply_fk
                    FOREIGN KEY (reply_to_id) REFERENCES messages (id) ON DELETE SET NULL
            )
        SQL);

        // Reading a conversation, newest last. Every query here is this one.
        $this->addSql(<<<'SQL'
            CREATE INDEX messages_conversation_idx
                ON messages (conversation_id, created_at DESC, id DESC)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX messages_search_idx
                ON messages (tenant_id, created_at DESC)
                WHERE deleted_at IS NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS messages');
        $this->addSql('DROP TABLE IF EXISTS conversation_participants');
        $this->addSql('DROP TABLE IF EXISTS conversations');
    }
}
