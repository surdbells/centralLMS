<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tokens that let somebody set a password without being signed in.
 *
 * Two things needed this and neither existed.
 *
 * Resetting a forgotten password had no server-side machinery at all. The
 * legacy flow asked for an email, and `authentication/confirm` replied with
 * the one-time code *in the response body*; the browser held it and posted it
 * back to `authentication/validate` for the server to compare against what it
 * had just been handed. The final `authentication/reset` then took an email
 * address and a new password and nothing else — no code, no token, no evidence
 * the step before it had happened. Anybody could set anybody's password.
 *
 * Invitations were promised and never sent. `UserStatus::Invited` tells the
 * person "check your email for the invitation", an administrator creating a
 * user leaves them in exactly that state, and no invitation was ever composed.
 * An invited account could not be signed into and had no way to become one
 * that could.
 *
 * Both are the same thing — a single-use, expiring secret, emailed to an
 * address, that authorises setting one password — so they are one table.
 */
final class Version20260902090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Single-use tokens for password resets and invitations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE password_tokens (
                id           UUID PRIMARY KEY,
                tenant_id    UUID NOT NULL,
                user_id      UUID NOT NULL,
                purpose      TEXT NOT NULL,
                -- The token itself is never stored. What is kept is a SHA-256
                -- of it, so a leaked database does not hand somebody a working
                -- reset link for every account in it.
                token_hash   TEXT NOT NULL,
                created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                expires_at   TIMESTAMPTZ NOT NULL,
                used_at      TIMESTAMPTZ,
                -- Where it was asked for and from what. Not used to decide
                -- anything; it is what somebody looks at afterwards.
                requested_ip INET,
                requested_ua TEXT,

                CONSTRAINT password_tokens_purpose_known
                    CHECK (purpose IN ('reset', 'invitation')),

                CONSTRAINT password_tokens_user_fk
                    FOREIGN KEY (user_id, tenant_id)
                    REFERENCES users (id, tenant_id)
                    ON DELETE CASCADE
            )
        SQL);

        // The lookup every redemption does: find the row by its hash.
        $this->addSql('CREATE UNIQUE INDEX password_tokens_hash ON password_tokens (token_hash)');

        // Used to retire a person's outstanding tokens when a new one is
        // issued, and when their password changes by any other route.
        $this->addSql(<<<'SQL'
            CREATE INDEX password_tokens_live
                ON password_tokens (user_id, purpose)
                WHERE used_at IS NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE password_tokens');
    }
}
