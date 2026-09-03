<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Payments: an attempt to collect an invoice through a gateway.
 *
 * A payment is one pass through the checkout — begun when the customer chooses
 * to pay, settled when the gateway confirms it (by return, and authoritatively
 * by webhook). It carries our own reference (what we send the gateway and match
 * the callback back to), the gateway's reference, and the raw confirmation for
 * the trail. Marking the invoice paid is the billing service's; this records
 * how the money came in.
 */
final class Version20260925000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Payments — collecting an invoice through a gateway';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'PostgreSQL only.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE payments (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                invoice_id  UUID REFERENCES invoices (id) ON DELETE SET NULL,

                provider    TEXT NOT NULL DEFAULT 'paystack',

                -- Our reference: unique, sent to the gateway and matched back on
                -- the callback and the webhook. The gateway's own reference is
                -- kept alongside once it answers.
                reference          TEXT NOT NULL,
                gateway_reference  TEXT,

                status      TEXT NOT NULL DEFAULT 'pending',
                amount      BIGINT NOT NULL,
                currency    TEXT   NOT NULL DEFAULT 'NGN',

                -- Where the customer goes to pay, and the raw confirmation.
                authorization_url TEXT,
                raw               JSONB,

                paid_at     TIMESTAMPTZ,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),

                CONSTRAINT payments_status_known CHECK (status IN ('pending', 'success', 'failed', 'abandoned')),
                CONSTRAINT payments_amount_sane CHECK (amount >= 0)
            )
        SQL);

        $this->addSql('CREATE UNIQUE INDEX payments_reference_uniq ON payments (reference)');
        $this->addSql('CREATE INDEX payments_invoice_idx ON payments (invoice_id)');
        $this->addSql('CREATE INDEX payments_tenant_idx ON payments (tenant_id, created_at DESC)');

        // At most one checkout in progress per invoice, so a customer cannot be
        // sent to two hosted checkouts for the same invoice and charged twice.
        // Only 'pending' is constrained — a failed attempt does not block a retry.
        $this->addSql(
            'CREATE UNIQUE INDEX payments_one_pending_per_invoice
             ON payments (invoice_id)
             WHERE status = \'pending\' AND invoice_id IS NOT NULL',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS payments');
    }
}
