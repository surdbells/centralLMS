<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The rest of messaging: reactions, drafts, pins, saved messages, blocking.
 *
 * The schema had conversations, participants and messages and stopped there,
 * while the screens offered all of the above. Each of these is a small table
 * serving one part of one screen, and each is here because the alternative —
 * a JSON column on `messages` — cannot answer the questions people ask of
 * them: "who reacted", "what have I saved", "is this person blocked".
 *
 * Read receipts are the exception and are deliberately absent. A participant
 * already carries `last_read_at`, and "who has read this message" is that
 * timestamp compared against the message's — one column already maintained
 * rather than a row per person per message, which on a busy channel is the
 * largest table in the system by an order of magnitude and says nothing the
 * timestamp does not.
 *
 * Message search is likewise not a table. `messages_search_idx` exists and
 * PostgreSQL's full-text search reads `content` directly; a search index of
 * our own would be a second copy of every message to keep in step.
 */
final class Version20260906090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reactions, drafts, pinned and saved messages, and blocking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE message_reactions (
                id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id  UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                message_id UUID NOT NULL REFERENCES messages (id) ON DELETE CASCADE,
                user_id    UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                -- The emoji itself rather than a name. A shortcode needs a
                -- lookup table to render, and the table would be a list of
                -- emoji.
                emoji      TEXT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT message_reactions_emoji_present CHECK (BTRIM(emoji) <> '')
            )
        SQL);

        // One person, one message, one emoji. Reacting twice with the same
        // one is a mis-click, not two reactions.
        $this->addSql(
            'CREATE UNIQUE INDEX message_reactions_uniq
             ON message_reactions (message_id, user_id, emoji)',
        );

        $this->addSql(
            'CREATE INDEX message_reactions_message_idx ON message_reactions (message_id)',
        );

        // What somebody has typed and not yet sent. One per conversation:
        // a draft is the state of a text box, and a text box holds one thing.
        $this->addSql(<<<'SQL'
            CREATE TABLE message_drafts (
                conversation_id UUID NOT NULL REFERENCES conversations (id) ON DELETE CASCADE,
                user_id         UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                tenant_id       UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                content         TEXT NOT NULL,
                reply_to_id     UUID REFERENCES messages (id) ON DELETE SET NULL,
                updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                PRIMARY KEY (conversation_id, user_id)
            )
        SQL);

        // Pinning is the conversation's, not the pinner's: a pinned message is
        // pinned for everybody in it, which is the point of pinning.
        $this->addSql(
            'ALTER TABLE messages ADD COLUMN IF NOT EXISTS pinned_at TIMESTAMPTZ',
        );
        $this->addSql(
            'ALTER TABLE messages ADD COLUMN IF NOT EXISTS pinned_by UUID
             REFERENCES users (id) ON DELETE SET NULL',
        );
        $this->addSql(
            'CREATE INDEX messages_pinned_idx ON messages (conversation_id, pinned_at DESC)
             WHERE pinned_at IS NOT NULL',
        );

        // Saving is the opposite: mine alone, and it carries my note.
        $this->addSql(<<<'SQL'
            CREATE TABLE saved_messages (
                id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id  UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                message_id UUID NOT NULL REFERENCES messages (id) ON DELETE CASCADE,
                user_id    UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                note       TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        SQL);

        $this->addSql(
            'CREATE UNIQUE INDEX saved_messages_uniq ON saved_messages (user_id, message_id)',
        );

        $this->addSql(
            'CREATE INDEX saved_messages_mine_idx
             ON saved_messages (tenant_id, user_id, created_at DESC)',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE blocked_users (
                id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id  UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                -- Who did the blocking, and who they blocked.
                user_id    UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                blocked_id UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                reason     TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                -- Blocking yourself is not a thing anybody means to do.
                CONSTRAINT blocked_users_not_self CHECK (user_id <> blocked_id)
            )
        SQL);

        $this->addSql(
            'CREATE UNIQUE INDEX blocked_users_uniq ON blocked_users (user_id, blocked_id)',
        );

        // Whether a person has muted a whole conversation is already on the
        // participant row. What was missing is what they want in general.
        $this->addSql(<<<'SQL'
            CREATE TABLE messaging_preferences (
                user_id             UUID PRIMARY KEY REFERENCES users (id) ON DELETE CASCADE,
                tenant_id           UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                notify_on_message   BOOLEAN NOT NULL DEFAULT TRUE,
                notify_on_mention   BOOLEAN NOT NULL DEFAULT TRUE,
                email_digest        BOOLEAN NOT NULL DEFAULT FALSE,
                -- Nobody may start a conversation with this person unless they
                -- already share one. The people who need this need it badly.
                direct_messages_from_anyone BOOLEAN NOT NULL DEFAULT TRUE,
                show_read_receipts  BOOLEAN NOT NULL DEFAULT TRUE,
                updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS messaging_preferences');
        $this->addSql('DROP TABLE IF EXISTS blocked_users');
        $this->addSql('DROP TABLE IF EXISTS saved_messages');
        $this->addSql('DROP INDEX IF EXISTS messages_pinned_idx');
        $this->addSql('ALTER TABLE messages DROP COLUMN IF EXISTS pinned_by');
        $this->addSql('ALTER TABLE messages DROP COLUMN IF EXISTS pinned_at');
        $this->addSql('DROP TABLE IF EXISTS message_drafts');
        $this->addSql('DROP TABLE IF EXISTS message_reactions');
    }
}
