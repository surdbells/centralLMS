<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tenant video conferencing connections.
 *
 * Ports the legacy `tenant_video_integrations` table, with three changes:
 *
 *  1. OAuth credentials are stored encrypted (see Support\Cipher). The legacy
 *     table held every customer's refresh token in plaintext.
 *  2. `platform` is a text column with a check constraint rather than a MySQL
 *     enum, so adding a platform is an ordinary migration instead of a table
 *     rewrite under lock.
 *  3. A partial unique index enforces at most one default platform per tenant.
 *     The legacy schema allowed several rows with `is_default = 1`, which made
 *     "the default" whichever row the query happened to return first.
 *
 * The foreign key to `tenants` is deliberately absent: that table has not been
 * migrated yet. It is added in the migration that creates it.
 */
final class Version20260825120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create video_integrations (tenant conferencing connections)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration targets PostgreSQL.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE video_integrations (
                id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id         UUID NOT NULL,
                platform          TEXT NOT NULL,
                enabled           BOOLEAN NOT NULL DEFAULT TRUE,
                is_default        BOOLEAN NOT NULL DEFAULT FALSE,
                client_id         TEXT,
                client_secret     TEXT,
                access_token      TEXT,
                refresh_token     TEXT,
                token_expires_at  TIMESTAMPTZ,
                settings          JSONB NOT NULL DEFAULT '{}'::jsonb,
                connected_by      UUID,
                connected_at      TIMESTAMPTZ,
                created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at        TIMESTAMPTZ,
                CONSTRAINT video_integrations_platform_check
                    CHECK (platform IN ('zoom', 'google_meet', 'microsoft_teams'))
            )
        SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN video_integrations.client_secret IS
                'Encrypted with Support\Cipher (AES-256-GCM). Never store plaintext.'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN video_integrations.access_token IS
                'Encrypted with Support\Cipher (AES-256-GCM). Never store plaintext.'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN video_integrations.refresh_token IS
                'Encrypted with Support\Cipher (AES-256-GCM). Never store plaintext.'
        SQL);

        // One connection per tenant per platform.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX video_integrations_tenant_platform_uniq
                ON video_integrations (tenant_id, platform)
        SQL);

        // At most one default per tenant — enforced, not assumed.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX video_integrations_one_default_per_tenant
                ON video_integrations (tenant_id)
                WHERE is_default
        SQL);

        // The hot path is "the enabled connections for this tenant".
        $this->addSql(<<<'SQL'
            CREATE INDEX video_integrations_tenant_enabled_idx
                ON video_integrations (tenant_id)
                WHERE enabled
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS video_integrations');
    }
}
