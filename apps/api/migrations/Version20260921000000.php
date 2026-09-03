<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The digital library: files an administrator shares with an audience.
 *
 * Distinct from `media`, which backs course content. A library item is a file
 * (video, audio, PDF, or an Office document converted to PDF) shown to whoever
 * it targets — a department, a role, everyone — and viewable inline but never
 * downloaded, so the bytes are only ever served through a short-lived ticket
 * with an inline disposition, and never on a download route.
 *
 * The audience is stored as a rule (`target_type` + `target_criteria`), not a
 * resolved list of people, because who is in a department changes after the
 * file is shared and the library should follow that, not a snapshot. It is
 * resolved against the reader at read time.
 *
 * An Office document is not something a browser renders inline, so it is
 * converted to PDF off the request (see the conversion worker); the PDF's key
 * and the state of that conversion live here alongside the original.
 */
final class Version20260921000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The digital library and its audience-targeted, no-download items';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'PostgreSQL only.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE digital_library_items (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                uploaded_by UUID,

                kind        TEXT NOT NULL,
                title       TEXT NOT NULL,
                description TEXT,

                -- What the person called it, and the generated key it is stored
                -- as. Never the same value: the first is shown, the second is
                -- derived, and conflating them is how a filename becomes a path.
                original_filename TEXT NOT NULL,
                storage_key       TEXT NOT NULL,

                file_size   BIGINT NOT NULL DEFAULT 0,
                mime_type   TEXT NOT NULL,
                extension   TEXT NOT NULL,

                -- Optional, by kind: a PDF has pages, a recording a duration.
                page_count       INTEGER,
                duration_seconds NUMERIC(10, 2),

                -- An Office document is converted to PDF for inline viewing.
                -- `not_required` for files already viewable (video, audio, pdf,
                -- image); `pending` → `processing` → `done`/`failed` otherwise.
                converted_pdf_key TEXT,
                conversion_status TEXT NOT NULL DEFAULT 'not_required',

                -- The audience as a rule, resolved against the reader at read
                -- time rather than snapshotted, so it follows the org as it
                -- changes. `all` needs no criteria; the rest name ids in the
                -- JSONB (departmentIds, roleIds, …).
                target_type     TEXT  NOT NULL DEFAULT 'all',
                target_criteria JSONB NOT NULL DEFAULT '{}'::jsonb,

                sha256      TEXT,
                metadata    JSONB NOT NULL DEFAULT '{}'::jsonb,

                created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at  TIMESTAMPTZ,

                CONSTRAINT dli_title_present CHECK (btrim(title) <> ''),
                CONSTRAINT dli_kind_known CHECK (kind IN ('video', 'audio', 'pdf', 'document', 'image')),
                CONSTRAINT dli_size_sane CHECK (file_size >= 0),
                CONSTRAINT dli_conversion_status_known
                    CHECK (conversion_status IN ('not_required', 'pending', 'processing', 'done', 'failed')),
                CONSTRAINT dli_target_type_known
                    CHECK (target_type IN ('all', 'role', 'department', 'branch', 'team')),
                CONSTRAINT dli_target_criteria_is_object CHECK (jsonb_typeof(target_criteria) = 'object'),
                CONSTRAINT dli_metadata_is_object CHECK (jsonb_typeof(metadata) = 'object'),
                -- A document that says it converted must carry the PDF it made.
                CONSTRAINT dli_converted_has_key
                    CHECK (conversion_status <> 'done' OR converted_pdf_key IS NOT NULL)
            )
        SQL);

        // Lets a tenant-scoped composite FK point at an item, the way the media
        // table is referenced, and keeps every lookup tenant-bound.
        $this->addSql('CREATE UNIQUE INDEX dli_id_tenant_uniq ON digital_library_items (id, tenant_id)');

        // The library listing: newest first, live rows only.
        $this->addSql(<<<'SQL'
            CREATE INDEX dli_library_idx
                ON digital_library_items (tenant_id, created_at DESC)
                WHERE deleted_at IS NULL
        SQL);

        // Spotting a re-upload of the same bytes. Not unique: the same file may
        // be shared with two audiences.
        $this->addSql(<<<'SQL'
            CREATE INDEX dli_sha256_idx
                ON digital_library_items (tenant_id, sha256)
                WHERE sha256 IS NOT NULL
        SQL);

        // The documents still to convert — a backstop for the worker, which is
        // normally handed the id directly.
        $this->addSql(<<<'SQL'
            CREATE INDEX dli_conversion_idx
                ON digital_library_items (tenant_id, conversion_status)
                WHERE conversion_status IN ('pending', 'processing')
        SQL);

        // The uploader is kept even when their account goes: "who shared this"
        // is a question the library is asked.
        $this->addSql(<<<'SQL'
            ALTER TABLE digital_library_items
                ADD CONSTRAINT dli_uploaded_by_fk
                FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS digital_library_items');
    }
}
