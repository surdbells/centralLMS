<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Uploaded files, and their versions.
 *
 * One table where the legacy had four, three of which were empty or derived.
 *
 * **`media_thumbnails` is not ported.** Zero rows: nothing ever generated one.
 * Thumbnails are an output of processing, and there is no processing.
 *
 * **`media_processing_queue` is not ported.** Also zero rows — transcoding,
 * thumbnailing and SCORM validation were all designed and never run. The same
 * story as the four notification queues. `media.is_processed` goes with it:
 * a flag whose only writer never existed.
 *
 * **`media_version_history` is not ported** because it is not a table. It is a
 * view joining `media` to `users`, which is to say a query, and it lives here
 * as one.
 *
 * **`is_latest_version` is not stored.** It duplicated what
 * `root_media_id` and `version_number` already say, and nothing kept it in
 * step across the two writes that change it — uploading version three has to
 * clear the flag on version two, and a crash between them leaves a file with
 * two latest versions or none. A partial unique index makes the second
 * impossible and the query answers the rest.
 */
final class Version20260829090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Uploaded media and its version chain';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'PostgreSQL only.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE media (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                uploaded_by UUID,

                kind        TEXT NOT NULL,
                title       TEXT NOT NULL,
                description TEXT,

                -- What the person called it, and what it is stored as. Both:
                -- the first is shown, the second is a generated key, and
                -- conflating them is how a file called `../../etc/passwd`
                -- becomes a path.
                original_filename TEXT NOT NULL,
                storage_key       TEXT NOT NULL,

                file_size   BIGINT NOT NULL DEFAULT 0,
                mime_type   TEXT NOT NULL,
                extension   TEXT NOT NULL,

                -- Everything a player needs, and all of it optional because
                -- what applies depends on the kind: a PDF has pages, a video
                -- has a duration.
                duration_seconds NUMERIC(10, 2),
                width       INTEGER,
                height      INTEGER,
                page_count  INTEGER,

                -- SCORM only.
                scorm_version  TEXT,
                launch_url     TEXT,
                extracted_path TEXT,

                metadata    JSONB NOT NULL DEFAULT '{}'::jsonb,

                -- For spotting a re-upload of something already held.
                sha256      TEXT,

                -- --- versions --------------------------------------------
                --
                -- The first upload is its own root, so every file has a
                -- chain and the query needs no special case for version one.
                root_media_id  UUID NOT NULL,
                version_number INTEGER NOT NULL DEFAULT 1,
                version_label  TEXT,
                version_notes  TEXT,

                course_id   UUID,

                created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at  TIMESTAMPTZ,

                CONSTRAINT media_title_present CHECK (btrim(title) <> ''),
                CONSTRAINT media_kind_known CHECK (kind IN ('video', 'audio', 'pdf', 'scorm', 'image', 'document')),
                CONSTRAINT media_size_sane CHECK (file_size >= 0),
                CONSTRAINT media_version_positive CHECK (version_number >= 1),
                CONSTRAINT media_metadata_is_object CHECK (jsonb_typeof(metadata) = 'object'),
                -- A SCORM package that cannot be launched is a zip file.
                CONSTRAINT media_scorm_has_an_entry_point
                    CHECK (kind <> 'scorm' OR launch_url IS NOT NULL),

                CONSTRAINT media_course_fk
                    FOREIGN KEY (course_id, tenant_id) REFERENCES courses (id, tenant_id) ON DELETE SET NULL
            )
        SQL);

        $this->addSql('CREATE UNIQUE INDEX media_id_tenant_uniq ON media (id, tenant_id)');

        // One row per version of a file. Uploading the same version twice is
        // a double-submitted form, not a new version.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX media_version_uniq
                ON media (root_media_id, version_number)
                WHERE deleted_at IS NULL
        SQL);

        // The library view: latest versions only, newest first.
        $this->addSql(<<<'SQL'
            CREATE INDEX media_library_idx
                ON media (tenant_id, kind, created_at DESC)
                WHERE deleted_at IS NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX media_course_idx ON media (course_id) WHERE deleted_at IS NULL
        SQL);

        // Spotting a re-upload. Not unique: two courses may legitimately hold
        // the same file, and refusing that would be surprising.
        $this->addSql(<<<'SQL'
            CREATE INDEX media_sha256_idx ON media (tenant_id, sha256) WHERE sha256 IS NOT NULL
        SQL);

        // The uploader is kept even when their account goes, because "who put
        // this here" is the question a media library is asked.
        $this->addSql(<<<'SQL'
            ALTER TABLE media
                ADD CONSTRAINT media_uploaded_by_fk
                FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS media');
    }
}
