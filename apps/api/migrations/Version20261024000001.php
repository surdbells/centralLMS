<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-tenant custom profile fields.
 *
 * The fixed profile covers what every organisation has in common; this is for
 * what they do not. One tenant records a "Division", another an "Entity" or a
 * "Cost Centre" — all of them a field with a label, a type and, for a dropdown,
 * a fixed set of options. Rather than a column per tenant's idea, the
 * definitions live in a table and the values in a JSONB column on the user,
 * keyed by the definition's stable `field_key`.
 *
 *  - **tenant_custom_fields** holds the definitions: what fields this tenant
 *    has, in what order, which are required. `field_key` is the machine name
 *    the value map is keyed by and never changes once set; `label` is what
 *    people see and can be renamed freely.
 *  - **users.custom_fields** holds one person's answers — `{ "division": "Retail" }`
 *    — exactly as `consents` already stores a small per-user JSON map. The
 *    values are validated against the definitions on write, in the application.
 *
 * A definition is deleted outright rather than soft-deleted: a field the tenant
 * removed should stop appearing at once. Any value left behind in a user's JSON
 * is simply never rendered again, and costs nothing where it sits.
 */
final class Version20261024000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Per-tenant custom profile fields: definitions table and user value column';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration targets PostgreSQL.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE tenant_custom_fields (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                field_key   TEXT NOT NULL,
                label       TEXT NOT NULL,
                type        TEXT NOT NULL DEFAULT 'text',
                options     JSONB NOT NULL DEFAULT '[]'::jsonb,
                required    BOOLEAN NOT NULL DEFAULT FALSE,
                sort_order  INTEGER NOT NULL DEFAULT 0,
                is_active   BOOLEAN NOT NULL DEFAULT TRUE,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMPTZ,
                CONSTRAINT tenant_custom_fields_type_check
                    CHECK (type IN ('text', 'number', 'date', 'dropdown', 'boolean'))
            )
        SQL);

        // The key is the stable identifier the value map is keyed by, so it is
        // unique within a tenant. Case-insensitive, so 'Division' and 'division'
        // cannot become two fields whose values never meet.
        $this->addSql(
            'CREATE UNIQUE INDEX tenant_custom_fields_key_uniq
                 ON tenant_custom_fields (tenant_id, lower(field_key))',
        );
        $this->addSql(
            'CREATE INDEX tenant_custom_fields_tenant_idx
                 ON tenant_custom_fields (tenant_id, sort_order)',
        );

        $this->addSql("ALTER TABLE users ADD COLUMN custom_fields JSONB NOT NULL DEFAULT '{}'::jsonb");

        $this->seedMidwestern();
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS custom_fields');
        $this->addSql('DROP TABLE IF EXISTS tenant_custom_fields');
    }

    /**
     * The three fields Midwestern Learning asked for, seeded only if that
     * tenant exists here.
     *
     * Guarded by a name match and a not-exists so it does no harm in an
     * environment that has no such tenant, and is safe to re-run: a second
     * pass inserts nothing because the keys are already present. Other tenants
     * are untouched — they add their own fields through the admin screen.
     */
    private function seedMidwestern(): void
    {
        foreach ([
            ['division', 'Division', 10],
            ['subsidiary', 'Organization / Subsidiary', 20],
            ['entity', 'Entity', 30],
        ] as [$key, $label, $order]) {
            $this->addSql(
                <<<'SQL'
                INSERT INTO tenant_custom_fields (tenant_id, field_key, label, type, required, sort_order)
                SELECT t.id, :key, :label, 'text', FALSE, :order
                FROM tenants t
                WHERE lower(t.name) LIKE 'midwest%' AND t.deleted_at IS NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM tenant_custom_fields f
                      WHERE f.tenant_id = t.id AND lower(f.field_key) = :key
                  )
                SQL,
                ['key' => $key, 'label' => $label, 'order' => $order],
            );
        }
    }
}
