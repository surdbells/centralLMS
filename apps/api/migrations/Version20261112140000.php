<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Custom reports people save from the report builder.
 *
 * Separate from `saved_reports`, which holds criteria for a catalogue report
 * named by `report_key` and is read back through that enum: a custom report
 * has no catalogue key, it has a data source and a column/grouping/filter
 * config validated against it on every run.
 *
 * The config is the question, never the answer and never a tenant — running
 * one applies the caller's own tenant and row scope.
 */
final class Version20261112140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'custom_reports: report-builder definitions saved by a user';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration targets PostgreSQL.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE custom_reports (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                user_id     UUID NOT NULL,
                name        TEXT NOT NULL,
                description TEXT,
                source      TEXT NOT NULL,
                config      JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at  TIMESTAMPTZ,

                CONSTRAINT custom_reports_name_present CHECK (btrim(name) <> ''),
                CONSTRAINT custom_reports_config_is_object CHECK (jsonb_typeof(config) = 'object'),
                CONSTRAINT custom_reports_user_fk
                    FOREIGN KEY (user_id, tenant_id) REFERENCES users (id, tenant_id) ON DELETE CASCADE
            )
        SQL);

        // One name per person, so "Q3 overdue by branch" means one thing in
        // their list. Also the index their list is read through.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX custom_reports_owner_name_uniq
                ON custom_reports (tenant_id, user_id, lower(btrim(name)))
                WHERE deleted_at IS NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS custom_reports');
    }
}
