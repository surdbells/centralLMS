<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Signing in with somebody else's directory.
 *
 * Google and Microsoft Entra ID — which is what "Active Directory" means for
 * anything reached over the internet — are both OpenID Connect, so they are
 * one mechanism with two sets of endpoints rather than two implementations.
 *
 * Configured per tenant, because the credentials belong to the organisation:
 * an organisation's Google Workspace is not the platform's to speak for. That
 * also means one tenant turning on Microsoft says nothing about the next.
 *
 * `sso_states` is the short-lived half. An OAuth redirect leaves and comes back
 * with nothing but a code and a state parameter, and without a record of what
 * was sent out, a callback cannot be told from a forgery — so the state and
 * the nonce are written here before the redirect and struck off when it
 * returns.
 */
final class Version20260903090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Per-tenant OpenID Connect sign-in';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE sso_connections (
                id            UUID PRIMARY KEY,
                tenant_id     UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                provider      TEXT NOT NULL,
                enabled       BOOLEAN NOT NULL DEFAULT FALSE,
                client_id     TEXT NOT NULL,
                -- Encrypted with the same cipher the conferencing credentials
                -- use. It is never returned by any endpoint.
                client_secret TEXT NOT NULL,
                -- Entra only: which directory to authenticate against.
                -- `organizations` accepts any work account, a GUID pins it to
                -- one company. Google ignores this.
                directory     TEXT,
                -- Addresses allowed in. Empty means any the provider vouches
                -- for, which for a personal Google account is anybody at all.
                domains       TEXT NOT NULL DEFAULT '',
                -- Whether somebody the provider vouches for but who has no
                -- account here gets one. Off by default: an organisation that
                -- wants to choose who joins should not have that decided by
                -- who happens to hold a company mailbox.
                auto_provision BOOLEAN NOT NULL DEFAULT FALSE,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT sso_connections_provider_known
                    CHECK (provider IN ('google', 'microsoft'))
            )
        SQL);

        // One configuration per provider per tenant.
        $this->addSql(
            'CREATE UNIQUE INDEX sso_connections_tenant_provider
                 ON sso_connections (tenant_id, provider)',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE sso_states (
                -- The `state` parameter itself. Random, and the primary key so
                -- a replay collides rather than being accepted twice.
                state       TEXT PRIMARY KEY,
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                provider    TEXT NOT NULL,
                -- Echoed in the id_token. What ties the token that comes back
                -- to the redirect that went out.
                nonce       TEXT NOT NULL,
                -- Where to send them afterwards, checked against a whitelist
                -- before use — an open redirect on a sign-in endpoint is how
                -- a token ends up posted to somebody else's site.
                redirect_to TEXT,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                expires_at  TIMESTAMPTZ NOT NULL
            )
        SQL);

        // The sweep that clears abandoned redirects.
        $this->addSql('CREATE INDEX sso_states_expiry ON sso_states (expires_at)');

        // Where an account came from, so a directory-managed one is not
        // mistaken for one with a password. Null means an ordinary account.
        $this->addSql("ALTER TABLE users ADD COLUMN sso_provider TEXT");
        $this->addSql("ALTER TABLE users ADD COLUMN sso_subject TEXT");

        // The provider's own id for the person. Stable across a change of
        // email address, which their address is not — matching on email alone
        // means a renamed mailbox becomes a second account.
        $this->addSql(
            'CREATE UNIQUE INDEX users_sso_subject
                 ON users (sso_provider, sso_subject)
                 WHERE sso_subject IS NOT NULL AND deleted_at IS NULL',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS users_sso_subject');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS sso_subject');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS sso_provider');
        $this->addSql('DROP TABLE sso_states');
        $this->addSql('DROP TABLE sso_connections');
    }
}
