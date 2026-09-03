<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The SaaS product catalogue: plans, and the plan a tenant is on.
 *
 * A plan is platform-level, not tenant-scoped — it is the product the operator
 * sells, the same catalogue every organisation chooses from — so it carries no
 * tenant_id. It names its price (in minor units, to keep money off floats) and
 * the entitlements it grants; a tenant's own `users_limit` / `storage_limit_mb`
 * still override, so an organisation can be given a bespoke allowance without
 * inventing a plan for it.
 *
 * `tenants.plan_id` records which plan an organisation is on. The subscription
 * lifecycle (periods, invoices, payments) is a later migration; this is the
 * catalogue and the link, which the operator console and the pricing page both
 * read.
 */
final class Version20260922000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SaaS plans catalogue and the tenant plan link';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'PostgreSQL only.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE plans (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                code        TEXT NOT NULL,
                name        TEXT NOT NULL,
                description TEXT,

                -- Money in minor units (kobo / cents), with its currency, so no
                -- price is ever a float. 0 is a free plan, not "unpriced".
                price_monthly BIGINT NOT NULL DEFAULT 0,
                price_yearly  BIGINT NOT NULL DEFAULT 0,
                currency      TEXT   NOT NULL DEFAULT 'NGN',

                -- Entitlements the plan grants. A NULL limit is unlimited.
                users_limit      INTEGER,
                storage_limit_mb BIGINT,
                features         JSONB NOT NULL DEFAULT '{}'::jsonb,

                trial_days  INTEGER NOT NULL DEFAULT 14,
                -- Whether an organisation may be put on it, and whether it shows
                -- on the public pricing page. A retired plan stays for the
                -- tenants already on it but is offered to no one new.
                is_active   BOOLEAN NOT NULL DEFAULT true,
                is_public   BOOLEAN NOT NULL DEFAULT true,
                sort_order  INTEGER NOT NULL DEFAULT 0,

                created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at  TIMESTAMPTZ,

                CONSTRAINT plans_code_present CHECK (btrim(code) <> ''),
                CONSTRAINT plans_name_present CHECK (btrim(name) <> ''),
                CONSTRAINT plans_features_is_object CHECK (jsonb_typeof(features) = 'object'),
                CONSTRAINT plans_prices_sane CHECK (price_monthly >= 0 AND price_yearly >= 0)
            )
        SQL);

        $this->addSql('CREATE UNIQUE INDEX plans_code_uniq ON plans (lower(code)) WHERE deleted_at IS NULL');

        // The plan an organisation is on. SET NULL rather than cascade: removing
        // a plan must never delete the organisations that were on it.
        $this->addSql(<<<'SQL'
            ALTER TABLE tenants
                ADD COLUMN plan_id UUID REFERENCES plans (id) ON DELETE SET NULL
        SQL);
        $this->addSql('CREATE INDEX tenants_plan_idx ON tenants (plan_id) WHERE deleted_at IS NULL');

        // A starting catalogue, so the console and pricing page open on
        // something. Prices in kobo (₦), the currency the onboarding wizard
        // already quotes. The operator edits these; they are only a start.
        $this->addSql(<<<'SQL'
            INSERT INTO plans (code, name, description, price_monthly, price_yearly, currency,
                               users_limit, storage_limit_mb, features, trial_days, sort_order)
            VALUES
              ('starter', 'Starter', 'For a small team getting going.',
               2500000, 25000000, 'NGN', 25, 5120,
               '{"proctoring": false, "competency": true, "library": true, "liveTraining": false}'::jsonb, 14, 1),
              ('growth', 'Growth', 'For a growing organisation training at scale.',
               7500000, 75000000, 'NGN', 150, 51200,
               '{"proctoring": true, "competency": true, "library": true, "liveTraining": true}'::jsonb, 14, 2),
              ('enterprise', 'Enterprise', 'Unlimited people and storage, every module.',
               0, 0, 'NGN', NULL, NULL,
               '{"proctoring": true, "competency": true, "library": true, "liveTraining": true, "sso": true}'::jsonb, 30, 3)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenants DROP COLUMN IF EXISTS plan_id');
        $this->addSql('DROP TABLE IF EXISTS plans');
    }
}
