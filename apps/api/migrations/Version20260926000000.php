<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Assessment cycles become managed reference data.
 *
 * A cycle — the period an exercise runs for, "2026" or "2026 H1" — was only ever
 * free text typed onto each assessment, and the dropdowns were built from
 * SELECT DISTINCT over that column. So a typo made a new cycle, and no cycle
 * existed until someone had already filed an assessment under it.
 *
 * A cycle is a tenant-scoped named list — exactly what `lookups` already is — so
 * it joins as a seventh kind rather than earning a table of its own. Assessments
 * still carry the cycle by name (leaving every report query untouched); the
 * lookup is the managed list the pickers draw from, and the repository's update
 * carries a rename across the assessments that name it.
 *
 * The existing distinct cycles are seeded as active lookups so nothing a tenant
 * has already filed under drops out of the pickers.
 */
final class Version20260926000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cycle as a lookup kind and seed cycles from existing assessments';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration targets PostgreSQL.',
        );

        $this->addSql('ALTER TABLE lookups DROP CONSTRAINT lookups_kind_check');
        $this->addSql(<<<'SQL'
            ALTER TABLE lookups ADD CONSTRAINT lookups_kind_check CHECK (
                kind IN ('branch', 'department', 'team', 'level', 'job_title', 'category', 'cycle')
            )
        SQL);

        // Every distinct cycle a tenant has an assessment under becomes an active
        // cycle lookup. The source is grouped case-insensitively — the cycle
        // unique index is on lower(name), and cycles were free text, so a tenant
        // may hold "2026 H1" and "2026 h1"; folding them here (keeping one real
        // spelling per group) stops the two colliding on the index and aborting
        // the migration. NOT EXISTS keeps it idempotent across re-runs.
        $this->addSql(<<<'SQL'
            INSERT INTO lookups (tenant_id, kind, name, is_active, sort_order)
            SELECT d.tenant_id, 'cycle', d.cycle, TRUE, 0
            FROM (
                SELECT tenant_id, MIN(cycle) AS cycle
                FROM competency_assessments
                WHERE cycle IS NOT NULL
                  AND btrim(cycle) <> ''
                  AND deleted_at IS NULL
                GROUP BY tenant_id, lower(cycle)
            ) d
            WHERE NOT EXISTS (
                SELECT 1 FROM lookups l
                WHERE l.tenant_id = d.tenant_id
                  AND l.kind = 'cycle'
                  AND lower(l.name) = lower(d.cycle)
                  AND l.deleted_at IS NULL
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        // The cycles themselves go; the assessments keep their cycle name (the
        // column was never touched), so the pickers fall back to SELECT DISTINCT
        // as before.
        $this->addSql("DELETE FROM lookups WHERE kind = 'cycle'");
        $this->addSql('ALTER TABLE lookups DROP CONSTRAINT lookups_kind_check');
        $this->addSql(<<<'SQL'
            ALTER TABLE lookups ADD CONSTRAINT lookups_kind_check CHECK (
                kind IN ('branch', 'department', 'team', 'level', 'job_title', 'category')
            )
        SQL);
    }
}
