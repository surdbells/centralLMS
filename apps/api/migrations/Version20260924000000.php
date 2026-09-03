<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Subscriptions and invoices: the billing lifecycle on top of the plan link.
 *
 * `tenants.plan_id` says which plan an organisation is on; a subscription says
 * on what terms — the billing cycle, the price captured at the time (so a later
 * plan price change does not rewrite history), where the current period runs to,
 * and whether it is trialing, live, behind, or cancelled. One subscription per
 * organisation, mutated through its lifecycle.
 *
 * An invoice is a bill for one period: a sequential number, an amount, and a
 * state. Payment itself is a later phase — here an invoice is issued and can be
 * marked paid or voided by the operator; when a gateway lands, a payment does
 * the marking. The renewal job rolls a subscription's period forward and issues
 * the next invoice.
 */
final class Version20260924000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Subscriptions and invoices — the SaaS billing lifecycle';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'PostgreSQL only.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE subscriptions (
                id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id    UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                plan_id      UUID REFERENCES plans (id) ON DELETE SET NULL,

                billing_cycle TEXT NOT NULL DEFAULT 'monthly',
                status        TEXT NOT NULL DEFAULT 'trialing',

                -- The price per period, in minor units, captured when the
                -- subscription is set or changed — not read from the plan at
                -- bill time, so a plan re-price never rewrites what was agreed.
                amount   BIGINT NOT NULL DEFAULT 0,
                currency TEXT   NOT NULL DEFAULT 'NGN',

                current_period_start TIMESTAMPTZ,
                current_period_end   TIMESTAMPTZ,
                trial_ends_at        TIMESTAMPTZ,

                -- A cancellation asked for but not yet due: the subscription runs
                -- to the period end, then stops.
                cancel_at_period_end BOOLEAN NOT NULL DEFAULT false,
                canceled_at          TIMESTAMPTZ,

                started_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),

                CONSTRAINT subscriptions_cycle_known CHECK (billing_cycle IN ('monthly', 'yearly')),
                CONSTRAINT subscriptions_status_known CHECK (status IN ('trialing', 'active', 'past_due', 'canceled')),
                CONSTRAINT subscriptions_amount_sane CHECK (amount >= 0)
            )
        SQL);

        // One current subscription per organisation.
        $this->addSql('CREATE UNIQUE INDEX subscriptions_tenant_uniq ON subscriptions (tenant_id)');

        // A tidy, gap-free human number for invoices.
        $this->addSql('CREATE SEQUENCE invoice_number_seq');

        $this->addSql(<<<'SQL'
            CREATE TABLE invoices (
                id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id       UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                subscription_id UUID REFERENCES subscriptions (id) ON DELETE SET NULL,

                number      TEXT NOT NULL,
                status      TEXT NOT NULL DEFAULT 'open',
                description TEXT,

                amount_due  BIGINT NOT NULL DEFAULT 0,
                amount_paid BIGINT NOT NULL DEFAULT 0,
                currency    TEXT   NOT NULL DEFAULT 'NGN',

                period_start TIMESTAMPTZ,
                period_end   TIMESTAMPTZ,

                issued_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
                due_at      TIMESTAMPTZ,
                paid_at     TIMESTAMPTZ,

                created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),

                CONSTRAINT invoices_status_known CHECK (status IN ('draft', 'open', 'paid', 'void', 'uncollectible')),
                CONSTRAINT invoices_amounts_sane CHECK (amount_due >= 0 AND amount_paid >= 0)
            )
        SQL);

        $this->addSql('CREATE UNIQUE INDEX invoices_number_uniq ON invoices (number)');
        $this->addSql('CREATE INDEX invoices_tenant_idx ON invoices (tenant_id, issued_at DESC)');
        $this->addSql('CREATE INDEX invoices_open_idx ON invoices (status) WHERE status = \'open\'');

        // At most one invoice per subscription per period. The renewal job's
        // check-then-insert is not enough on its own: two overlapping runs
        // (a slow one still going when the next fires, or a manual run beside
        // the cron) could both find no invoice and both raise one. The database
        // is the backstop — the second insert is rejected and the losing run
        // rolls back and picks it up cleanly next time.
        $this->addSql(
            'CREATE UNIQUE INDEX invoices_subscription_period_uniq
             ON invoices (subscription_id, period_end)
             WHERE subscription_id IS NOT NULL AND period_end IS NOT NULL',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS invoices');
        $this->addSql('DROP SEQUENCE IF EXISTS invoice_number_seq');
        $this->addSql('DROP TABLE IF EXISTS subscriptions');
    }
}
