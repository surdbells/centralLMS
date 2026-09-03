<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Onboarding codes: what gates public self-service sign-up.
 *
 * Creating an organisation from the public site is not open — a customer has to
 * present a code the operator issued, the same way the legacy gated it. A code
 * carries how many times it may be used, an optional validity window, and an
 * optional list of the plans it unlocks (null = any plan). It is platform-level,
 * not tenant-scoped: it exists before the organisation it creates does.
 *
 * The operator console manages these; the public sign-up validates one and, on
 * a successful provision, spends one use.
 */
final class Version20260923000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Onboarding codes that gate public self-service sign-up';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'PostgreSQL only.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE onboarding_codes (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                code        TEXT NOT NULL,
                description TEXT,

                -- How many organisations it may create. NULL is unlimited; a
                -- spent code (used_count >= max_uses) validates no more.
                max_uses    INTEGER,
                used_count  INTEGER NOT NULL DEFAULT 0,

                -- The plans it unlocks, as an array of plan ids. NULL means any
                -- plan on offer; a set restricts sign-up to those.
                allowed_plan_ids JSONB,

                -- An optional window it is good for.
                valid_from  TIMESTAMPTZ,
                valid_until TIMESTAMPTZ,

                is_active   BOOLEAN NOT NULL DEFAULT true,

                -- The operator who issued it, kept for the trail. SET NULL so
                -- removing that account never deletes the codes they made.
                created_by  UUID REFERENCES users (id) ON DELETE SET NULL,

                created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at  TIMESTAMPTZ,

                CONSTRAINT onboarding_codes_code_present CHECK (btrim(code) <> ''),
                CONSTRAINT onboarding_codes_used_count_sane CHECK (used_count >= 0),
                CONSTRAINT onboarding_codes_max_uses_sane CHECK (max_uses IS NULL OR max_uses >= 0),
                CONSTRAINT onboarding_codes_allowed_is_array
                    CHECK (allowed_plan_ids IS NULL OR jsonb_typeof(allowed_plan_ids) = 'array')
            )
        SQL);

        // Case-insensitive: WELCOME2026 and welcome2026 are the same code, and
        // two live codes cannot collide.
        $this->addSql('CREATE UNIQUE INDEX onboarding_codes_code_uniq ON onboarding_codes (upper(code)) WHERE deleted_at IS NULL');

        // One example code so the console opens on something — but INACTIVE, so
        // a fresh deployment does not ship a publicly-known, working code that
        // would let anyone stand up organisations before the operator has set
        // anything up. The operator activates it, or issues their own; the gate
        // is closed until they do.
        $this->addSql(<<<'SQL'
            INSERT INTO onboarding_codes (code, description, max_uses, allowed_plan_ids, is_active)
            VALUES ('WELCOME2026', 'Example code — activate it or issue your own to open sign-up.', 100, NULL, false)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS onboarding_codes');
    }
}
