<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The audit trail.
 *
 * The legacy had a `user_activity_log` table with 14 columns and a screen that
 * read it, and the only thing that ever wrote to it was the login handler — so
 * "who suspended this account?" was unanswerable, and the Activity tab on a
 * user's record showed a list of sign-ins labelled as their whole history.
 *
 * Two decisions worth stating, because both look like omissions:
 *
 *  - **The request body is not stored.** It carries passwords, and an audit
 *    trail that quietly becomes the largest collection of plaintext
 *    credentials in the system is worse than no audit trail. What was written
 *    is recoverable from the row itself; that a write happened, by whom, to
 *    what, is what this answers.
 *  - **`actor_email` is stored beside `actor_id`.** People get deleted, and
 *    the FK is `ON DELETE SET NULL` because an audit row must outlive its
 *    subject — without the address, every row for a departed colleague would
 *    read "somebody did this". The *name* is not stored: it is resolved by
 *    joining `users` at read time, so a row shows what somebody is called now
 *    rather than what they were called then.
 *
 * Rows are written by AuditMiddleware from the route name, not by each action
 * calling a logger. One place, and no way for a new endpoint to be forgotten.
 */
final class Version20260831090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create audit_events: who did what, to what, and from where.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE audit_events (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,

                -- Null once the person is deleted; the address beside it is not.
                actor_id    UUID REFERENCES users (id) ON DELETE SET NULL,
                -- The name is joined from `users` at read time; only the
                -- address is kept, because it survives the person.
                actor_email TEXT NOT NULL DEFAULT '',

                -- The route name: `users.create`, `roles.delete`. Derived from
                -- the route rather than passed in, so it cannot disagree with
                -- what was actually called.
                action      TEXT NOT NULL,
                method      TEXT NOT NULL,
                path        TEXT NOT NULL,

                -- The {id} the route matched, when it had one. Untyped on
                -- purpose: a route id may be a user, a course or a role, and a
                -- foreign key here would delete history when the subject goes.
                subject_id  UUID,

                status      SMALLINT NOT NULL,
                ip_address  INET,
                user_agent  TEXT,
                request_id  TEXT,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT audit_events_status_check CHECK (status BETWEEN 100 AND 599)
            )
        SQL);

        // Every query this table serves starts with a tenant and a date range,
        // so that is the leading pair. The other two are the filters the
        // screen offers beside it.
        $this->addSql('CREATE INDEX audit_events_tenant_time_idx ON audit_events (tenant_id, created_at DESC)');
        $this->addSql(
            'CREATE INDEX audit_events_actor_idx ON audit_events (tenant_id, actor_id, created_at DESC)
             WHERE actor_id IS NOT NULL',
        );
        $this->addSql('CREATE INDEX audit_events_action_idx ON audit_events (tenant_id, action)');
        $this->addSql(
            'CREATE INDEX audit_events_subject_idx ON audit_events (tenant_id, subject_id)
             WHERE subject_id IS NOT NULL',
        );

        $this->addSql(<<<'SQL'
            COMMENT ON TABLE audit_events IS
                'Mutating requests, refused ones included. Written by AuditMiddleware from the route name. '
                'Request bodies are deliberately not stored: they carry passwords.'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS audit_events');
    }
}
