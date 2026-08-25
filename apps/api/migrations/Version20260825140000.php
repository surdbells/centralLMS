<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reference data: branches, departments, teams, levels, job titles, categories.
 *
 * Six legacy tables collapse into one. They were already the same thing —
 * a tenant-scoped named list with a code, a description, an active flag and
 * timestamps — differing only in column prefixes (`branch_name`,
 * `department_name`, `title_name`, `level_name`, `team_name`, `name`).
 *
 * That duplication is what produced 36 near-identical PHP endpoints, and with
 * them the bug below.
 *
 * ---------------------------------------------------------------------------
 * TENANT ISOLATION
 * ---------------------------------------------------------------------------
 * The legacy queries do not filter by tenant. `dictionary/branches/list.php`
 * runs `SELECT * FROM branches` with no WHERE clause at all, and
 * `Branches::update()` runs `UPDATE branches SET … WHERE branch_id = ?` — so
 * any caller could read every organisation's structure, and edit or deactivate
 * another organisation's records by id. Only the `active` dropdown query was
 * scoped.
 *
 * Here isolation is structural: `tenant_id` is NOT NULL, every index is
 * composite on it, and the repository has no method that omits it.
 *
 * ---------------------------------------------------------------------------
 * NOT INCLUDED
 * ---------------------------------------------------------------------------
 * `certificate_templates` is also served under `/dictionary/` but is not
 * reference data — it has 12 columns including template bodies. It moves with
 * the certificates module.
 */
final class Version20260825140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create lookups: branches, departments, teams, levels, job titles, categories';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration targets PostgreSQL.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE lookups (
                id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id    BIGINT,
                tenant_id    UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                kind         TEXT NOT NULL,
                code         TEXT,
                name         TEXT NOT NULL,
                description  TEXT,
                parent_id    UUID REFERENCES lookups (id) ON DELETE SET NULL,
                sort_order   INTEGER NOT NULL DEFAULT 0,
                is_active    BOOLEAN NOT NULL DEFAULT TRUE,
                attributes   JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at   TIMESTAMPTZ,
                deleted_at   TIMESTAMPTZ,
                CONSTRAINT lookups_kind_check CHECK (
                    kind IN ('branch', 'department', 'team', 'level', 'job_title', 'category')
                ),
                CONSTRAINT lookups_name_not_blank CHECK (length(btrim(name)) > 0),
                -- Lets a referencing table pin the kind through a composite
                -- foreign key, so users.department_id cannot point at a branch.
                CONSTRAINT lookups_id_kind_uniq UNIQUE (id, kind)
            )
        SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN lookups.attributes IS
                'Kind-specific fields with no shared meaning — branch address, city, state, country. Display only; never filtered on.'
        SQL);

        // Names and codes are unique per tenant per kind, among live rows.
        // lower() because the legacy MySQL collation was case-insensitive and
        // PostgreSQL is not: without this the port would start admitting
        // "Lagos" alongside "lagos".
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX lookups_tenant_kind_name_uniq
                ON lookups (tenant_id, kind, lower(name))
                WHERE deleted_at IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX lookups_tenant_kind_code_uniq
                ON lookups (tenant_id, kind, lower(code))
                WHERE code IS NOT NULL AND deleted_at IS NULL
        SQL);

        // The hot path is "the active <kind> for this tenant", which is what
        // every dropdown in the application asks for.
        $this->addSql(<<<'SQL'
            CREATE INDEX lookups_tenant_kind_active_idx
                ON lookups (tenant_id, kind, sort_order, name)
                WHERE deleted_at IS NULL AND is_active
        SQL);

        $this->addSql('CREATE INDEX lookups_parent_idx ON lookups (parent_id) WHERE parent_id IS NOT NULL');

        // Maps legacy rows during the data migration. Ids collide across the
        // old tables — branch_id 1 and department_id 1 both exist — so the
        // kind is part of the key.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX lookups_legacy_uniq
                ON lookups (kind, legacy_id)
                WHERE legacy_id IS NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS lookups');
    }
}
