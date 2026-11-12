<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Categories for the digital library.
 *
 * A tenant names its own shelves ("Policies", "Safety videos", ...) and files
 * the library's items under them. A category is a label, not a permission: who
 * may see an item is still its audience, so a category never widens or
 * narrows it.
 *
 * An item has at most one category; deleting a category leaves its items in
 * the library, uncategorised (ON DELETE SET NULL), rather than taking them with
 * it. Names are unique per tenant ignoring case and surrounding space, so
 * "Policies" and " policies " cannot both exist.
 */
final class Version20261113090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Digital library categories, and an optional category on each library item';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'PostgreSQL only.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE library_categories (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                name        TEXT NOT NULL,
                description TEXT,
                sort_order  INTEGER NOT NULL DEFAULT 0,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),

                CONSTRAINT lc_name_present CHECK (btrim(name) <> '')
            )
        SQL);

        // One "Policies" per tenant, however it is cased or padded.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX lc_tenant_name_uniq
                ON library_categories (tenant_id, lower(btrim(name)))
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE digital_library_items
                ADD COLUMN category_id UUID REFERENCES library_categories (id) ON DELETE SET NULL
        SQL);

        // Filtering the shelf by category, and counting what each one holds.
        $this->addSql('CREATE INDEX dli_category_idx ON digital_library_items (tenant_id, category_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS dli_category_idx');
        $this->addSql('ALTER TABLE digital_library_items DROP COLUMN IF EXISTS category_id');
        $this->addSql('DROP TABLE IF EXISTS library_categories');
    }
}
