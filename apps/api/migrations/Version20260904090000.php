<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The organisation's postal address, and its own email templates.
 *
 * **The address** completes what the settings screen already asks for. Name,
 * contact email, contact phone and logo are all on `tenants` already —
 * deliberately, because they are the organisation's own record rather than
 * key/value settings, and keeping a second copy in a settings table is how the
 * two come to disagree. The address was the one field with nowhere to live.
 *
 * **The templates** are how an organisation says what its own mail sounds
 * like. Wording lives in {@see \Central\Api\Domain\Notification\NotificationComposer},
 * which is one set of English sentences for every tenant on the platform — fine
 * as a default, wrong as the only option for a company that wants its own tone,
 * its own signature, or its own language. A row here overrides the default for
 * one tenant and one notification type; no row means the default stands, so an
 * organisation that has never opened the screen is unaffected.
 */
final class Version20260904090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "A tenant's postal address and its own email templates";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenants ADD COLUMN IF NOT EXISTS address TEXT');

        $this->addSql(<<<'SQL'
            CREATE TABLE email_templates (
                id         UUID PRIMARY KEY,
                tenant_id  UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                -- The notification type this speaks for, as
                -- NotificationType::value. Not a foreign key: the catalogue is
                -- an enum in code, and a row naming a type that no longer
                -- exists is ignored rather than blocking a deployment.
                type       TEXT NOT NULL,
                -- Null means "use the wording the composer would have used".
                -- That is not the same as an empty string, which means an
                -- organisation has deliberately left it blank.
                subject    TEXT,
                body       TEXT,
                -- Off keeps the row and its wording while sending the default,
                -- so an organisation can put a draft aside without losing it.
                enabled    BOOLEAN NOT NULL DEFAULT TRUE,
                updated_by UUID REFERENCES users (id) ON DELETE SET NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        SQL);

        // One template per type per tenant.
        $this->addSql(
            'CREATE UNIQUE INDEX email_templates_tenant_type ON email_templates (tenant_id, type)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE email_templates');
        $this->addSql('ALTER TABLE tenants DROP COLUMN IF EXISTS address');
    }
}
