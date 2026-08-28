<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Broadcasts: one announcement to an audience, rather than a conversation.
 *
 * A conversation is people talking to each other. A broadcast is one person
 * telling everybody something — "the site is closed on Monday", "this policy
 * has changed and you must confirm you have read it" — and the difference
 * matters enough to be its own thing. A conversation with four hundred
 * participants, no replies and a read-receipt requirement is a broadcast
 * wearing the wrong shape, and every query against it pays for the disguise.
 *
 * **Who receives one is resolved when it is sent, not when it is read.** The
 * audience is stored as criteria — "everybody in Operations" — and turned into
 * rows at the moment of sending. Resolving it lazily would mean somebody who
 * joined the department last week sees an announcement from before they
 * arrived, and somebody who left still owes an acknowledgement.
 *
 * `requires_acknowledgment` is the whole reason this exists rather than a
 * notification: for a policy change, "did they see it" is a question somebody
 * will be asked in an audit, and it needs an answer per person.
 */
final class Version20260907090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Broadcast announcements, and who has read and acknowledged them';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE broadcasts (
                id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id    UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                title        TEXT NOT NULL,
                content      TEXT NOT NULL,
                -- Who it is for, as a rule rather than a list. The list is in
                -- broadcast_recipients, written when it is sent.
                target_type  TEXT NOT NULL DEFAULT 'all',
                target_criteria JSONB NOT NULL DEFAULT '{}'::jsonb,
                priority     TEXT NOT NULL DEFAULT 'normal',
                -- Whether each person must confirm they have read it. The
                -- reason a broadcast is not a notification.
                requires_acknowledgment BOOLEAN NOT NULL DEFAULT FALSE,
                scheduled_at TIMESTAMPTZ,
                expires_at   TIMESTAMPTZ,
                status       TEXT NOT NULL DEFAULT 'draft',
                sent_at      TIMESTAMPTZ,
                created_by   UUID REFERENCES users (id) ON DELETE SET NULL,
                created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at   TIMESTAMPTZ,
                deleted_at   TIMESTAMPTZ,

                CONSTRAINT broadcasts_title_present CHECK (BTRIM(title) <> ''),
                CONSTRAINT broadcasts_content_present CHECK (BTRIM(content) <> ''),
                CONSTRAINT broadcasts_status_known
                    CHECK (status IN ('draft', 'scheduled', 'sent', 'cancelled')),
                CONSTRAINT broadcasts_priority_known
                    CHECK (priority IN ('normal', 'important', 'urgent')),
                CONSTRAINT broadcasts_target_known
                    CHECK (target_type IN ('all', 'role', 'department', 'branch', 'team', 'course', 'custom')),
                -- A sent broadcast has a time it was sent, and an unsent one
                -- does not. The two disagreeing is how a "sent" announcement
                -- comes to have no recipients.
                CONSTRAINT broadcasts_sent_has_time
                    CHECK ((status = 'sent') = (sent_at IS NOT NULL)),
                -- Scheduling for a moment already past is not scheduling.
                CONSTRAINT broadcasts_scheduled_has_time
                    CHECK (status <> 'scheduled' OR scheduled_at IS NOT NULL)
            )
        SQL);

        $this->addSql(
            "CREATE INDEX broadcasts_tenant_idx
             ON broadcasts (tenant_id, status, created_at DESC)
             WHERE deleted_at IS NULL",
        );

        // What is due to go out. Partial, because the sweep that sends them
        // should not read every broadcast ever written to find three.
        $this->addSql(
            "CREATE INDEX broadcasts_due_idx ON broadcasts (scheduled_at)
             WHERE status = 'scheduled' AND deleted_at IS NULL",
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE broadcast_recipients (
                broadcast_id    UUID NOT NULL REFERENCES broadcasts (id) ON DELETE CASCADE,
                user_id         UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                tenant_id       UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                read_at         TIMESTAMPTZ,
                acknowledged_at TIMESTAMPTZ,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                PRIMARY KEY (broadcast_id, user_id),

                -- Acknowledging without having read it is not a state that
                -- means anything, and it is the state an audit would query.
                CONSTRAINT broadcast_recipients_ack_implies_read
                    CHECK (acknowledged_at IS NULL OR read_at IS NOT NULL)
            )
        SQL);

        // What is waiting for one person, newest first — the query the
        // messaging screen runs on every load.
        $this->addSql(
            'CREATE INDEX broadcast_recipients_mine_idx
             ON broadcast_recipients (tenant_id, user_id, created_at DESC)',
        );

        // And the counts an author watches: read and acknowledged, per
        // broadcast.
        $this->addSql(
            'CREATE INDEX broadcast_recipients_progress_idx
             ON broadcast_recipients (broadcast_id, read_at, acknowledged_at)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS broadcast_recipients');
        $this->addSql('DROP TABLE IF EXISTS broadcasts');
    }
}
