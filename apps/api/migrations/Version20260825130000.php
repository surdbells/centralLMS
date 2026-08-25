<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Identity: tenants, users, roles and refresh tokens.
 *
 * The foundation every other module depends on — everything in the system is
 * filtered by tenant and authorised by user.
 *
 * Ported from the legacy `tenants`, `users`, `roles`, `permissions`,
 * `role_permissions` and `user_roles` tables, with these deliberate changes:
 *
 *  1. **Permissions are code, not data.** The legacy `permissions` table is
 *     not ported. `role_permissions.permission` holds a
 *     `Domain\Authz\Permission` value, validated by the application. A
 *     permission that does not exist in the enum cannot be granted, so a
 *     tenant cannot invent capability by inserting a row.
 *  2. **One role mechanism.** The legacy users table carried `role_id`, `role`
 *     *and* five booleans (`is_admin`, `is_instructor`, `is_learner`,
 *     `is_hr`, `is_content`) alongside the `user_roles` join table — four ways
 *     to say the same thing, all in live use. Only `user_roles` survives.
 *     See docs/migration/identity.md for the reconciliation rule.
 *  3. **Real constraints.** Email is `citext` and unique per tenant, so the
 *     case-insensitive MySQL collation does not silently become
 *     case-sensitive and admit duplicate accounts.
 *  4. **Refresh tokens are stored hashed**, so logout and revocation are
 *     possible. The legacy `users.token` column was a single opaque token per
 *     user with no expiry — and was never actually checked.
 *
 * `legacy_id` columns exist purely to make the data migration re-runnable and
 * auditable; they are dropped once the cutover is signed off.
 */
final class Version20260825130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create identity tables: tenants, users, roles, user_roles, refresh_tokens';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration targets PostgreSQL.',
        );

        // --- tenants ---------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE tenants (
                id                       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id                BIGINT UNIQUE,
                name                     TEXT NOT NULL,
                code                     TEXT NOT NULL,
                domain                   TEXT,
                status                   TEXT NOT NULL DEFAULT 'trial',
                contact_email            CITEXT,
                contact_phone            TEXT,
                users_limit              INTEGER,
                storage_limit_mb         BIGINT,
                subscription_expires_at  DATE,
                logo_url                 TEXT,
                industry                 TEXT,
                company_size             TEXT,
                notes                    TEXT,
                settings                 JSONB NOT NULL DEFAULT '{}'::jsonb,
                provisioned_at           TIMESTAMPTZ,
                created_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at               TIMESTAMPTZ,
                deleted_at               TIMESTAMPTZ,
                CONSTRAINT tenants_status_check
                    CHECK (status IN ('trial', 'active', 'suspended', 'cancelled'))
            )
        SQL);

        // Codes and domains must be unique among *live* tenants only — a
        // soft-deleted tenant must not block reuse of its code.
        $this->addSql('CREATE UNIQUE INDEX tenants_code_uniq ON tenants (lower(code)) WHERE deleted_at IS NULL');
        $this->addSql('CREATE UNIQUE INDEX tenants_domain_uniq ON tenants (lower(domain)) WHERE domain IS NOT NULL AND deleted_at IS NULL');

        // --- users -----------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id                   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id            BIGINT UNIQUE,
                tenant_id            UUID NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                email                CITEXT NOT NULL,
                password_hash        TEXT,
                first_name           TEXT NOT NULL DEFAULT '',
                last_name            TEXT NOT NULL DEFAULT '',
                phone                TEXT,
                avatar_url           TEXT,
                status               TEXT NOT NULL DEFAULT 'invited',
                two_factor_enabled   BOOLEAN NOT NULL DEFAULT FALSE,
                locale               TEXT NOT NULL DEFAULT 'en',
                manager_id           UUID REFERENCES users (id) ON DELETE SET NULL,
                last_login_at        TIMESTAMPTZ,
                login_count          INTEGER NOT NULL DEFAULT 0,
                hire_date            DATE,
                probation_end_date   DATE,
                consents             JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_by           UUID REFERENCES users (id) ON DELETE SET NULL,
                created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at           TIMESTAMPTZ,
                deleted_at           TIMESTAMPTZ,
                CONSTRAINT users_status_check
                    CHECK (status IN ('invited', 'active', 'suspended', 'locked'))
            )
        SQL);

        // citext makes this case-insensitive, matching the old utf8mb4_unicode_ci
        // behaviour. Without it the port would start admitting Ada@x.com
        // alongside ada@x.com.
        $this->addSql('CREATE UNIQUE INDEX users_tenant_email_uniq ON users (tenant_id, email) WHERE deleted_at IS NULL');
        $this->addSql('CREATE INDEX users_tenant_status_idx ON users (tenant_id, status) WHERE deleted_at IS NULL');
        $this->addSql('CREATE INDEX users_manager_idx ON users (manager_id) WHERE manager_id IS NOT NULL');

        // A tenant's first admin, added after users exists to avoid a circular FK.
        $this->addSql('ALTER TABLE tenants ADD COLUMN admin_user_id UUID REFERENCES users (id) ON DELETE SET NULL');

        // --- roles -----------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE roles (
                id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id    INTEGER UNIQUE,
                tenant_id    UUID REFERENCES tenants (id) ON DELETE CASCADE,
                key          TEXT NOT NULL,
                name         TEXT NOT NULL,
                description  TEXT,
                is_system    BOOLEAN NOT NULL DEFAULT FALSE,
                created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at   TIMESTAMPTZ,
                CONSTRAINT roles_system_has_no_tenant
                    CHECK ((is_system AND tenant_id IS NULL) OR (NOT is_system AND tenant_id IS NOT NULL))
            )
        SQL);

        // Built-in roles are global (tenant_id NULL); tenant roles are unique
        // within their tenant. Two partial indexes because NULL never equals
        // NULL in a plain unique constraint.
        $this->addSql('CREATE UNIQUE INDEX roles_system_key_uniq ON roles (key) WHERE tenant_id IS NULL');
        $this->addSql('CREATE UNIQUE INDEX roles_tenant_key_uniq ON roles (tenant_id, key) WHERE tenant_id IS NOT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE role_permissions (
                role_id     UUID NOT NULL REFERENCES roles (id) ON DELETE CASCADE,
                permission  TEXT NOT NULL,
                PRIMARY KEY (role_id, permission)
            )
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN role_permissions.permission IS
                'A Domain\Authz\Permission value. Validated in application code — permissions are defined by the enum, not by this table.'
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE user_roles (
                user_id      UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                role_id      UUID NOT NULL REFERENCES roles (id) ON DELETE CASCADE,
                assigned_by  UUID REFERENCES users (id) ON DELETE SET NULL,
                assigned_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (user_id, role_id)
            )
        SQL);
        $this->addSql('CREATE INDEX user_roles_role_idx ON user_roles (role_id)');

        // --- refresh tokens ---------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE refresh_tokens (
                id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                user_id      UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                token_hash   TEXT NOT NULL UNIQUE,
                issued_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                expires_at   TIMESTAMPTZ NOT NULL,
                revoked_at   TIMESTAMPTZ,
                replaced_by  UUID REFERENCES refresh_tokens (id) ON DELETE SET NULL,
                user_agent   TEXT,
                ip_address   INET
            )
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN refresh_tokens.token_hash IS
                'SHA-256 of the token. The token itself is never stored, so a database leak cannot be replayed.'
        SQL);

        // The hot path is "find this token, is it still live?".
        $this->addSql('CREATE INDEX refresh_tokens_user_active_idx ON refresh_tokens (user_id) WHERE revoked_at IS NULL');

        // --- seed the built-in roles -----------------------------------------
        // Global (tenant_id NULL) so they exist in every tenant, present and
        // future, without a per-tenant seed step.
        foreach ([
            ['learner', 'Learner'],
            ['instructor', 'Instructor'],
            ['org_admin', 'Organisation Admin'],
            ['tenant_admin', 'Tenant Admin'],
            ['super_admin', 'Super Admin'],
        ] as [$key, $name]) {
            $this->addSql(
                'INSERT INTO roles (tenant_id, key, name, is_system) VALUES (NULL, :key, :name, TRUE)',
                ['key' => $key, 'name' => $name],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_admin_user_id_fkey');
        $this->addSql('DROP TABLE IF EXISTS refresh_tokens');
        $this->addSql('DROP TABLE IF EXISTS user_roles');
        $this->addSql('DROP TABLE IF EXISTS role_permissions');
        $this->addSql('DROP TABLE IF EXISTS roles');
        $this->addSql('DROP TABLE IF EXISTS users');
        $this->addSql('DROP TABLE IF EXISTS tenants');
    }
}
