<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What a tenant has configured, where it differs from the default.
 *
 * **Only overrides are stored.** The legacy wrote every key of every category
 * on first save — 70-odd rows per tenant, most of them holding the default it
 * had just been handed. Two tenants ended up with sixteen rows between them,
 * all branding, all still the defaults. A row here means somebody changed
 * something.
 *
 * **The key is closed.** `updateSetting($category, $key, $value)` accepted any
 * pair and wrote it, so the table was one typo away from holding
 * `primary_colour` beside `primary_color` with nothing to say which was read.
 * The catalogue is a PHP enum; a key not in it is a 422.
 *
 * **`value_type` is not a column.** It was stored beside each row and never
 * consulted on read — `getAllSettings()` returns strings whatever it says. The
 * type belongs to the key, not to the row, and it lives in the catalogue with
 * everything else that is true of a key.
 *
 * Four of the legacy's eight categories are not carried, because nothing reads
 * them. See {@see \Central\Api\Domain\Settings\SettingCatalogue} for which and
 * for what each would need first.
 */
final class Version20260829150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Per-tenant setting overrides';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'PostgreSQL only.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE tenant_settings (
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,

                -- Both from the catalogue. Stored flat rather than as one JSON
                -- document per tenant so two administrators editing different
                -- categories cannot overwrite each other.
                category    TEXT NOT NULL,
                setting_key TEXT NOT NULL,

                -- Always text. The catalogue says what it means, and parsing it
                -- there rather than trusting a column is what stops a boolean
                -- stored as "false" reading as true.
                value       TEXT NOT NULL,

                updated_by  UUID REFERENCES users (id) ON DELETE SET NULL,
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),

                PRIMARY KEY (tenant_id, setting_key)
            )
        SQL);

        // Reading one category is the common case — a settings screen shows one
        // tab at a time.
        $this->addSql('CREATE INDEX tenant_settings_category_idx ON tenant_settings (tenant_id, category)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS tenant_settings');
    }
}
