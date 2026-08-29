<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Files attached to a course as downloadable resources.
 *
 * A course's lessons carry the content a learner works through; a resource is
 * the material that sits alongside it — a slide deck, a checklist, a standard,
 * a spreadsheet — of any file type, added by whoever builds the course and
 * offered from the player's Resources tab. It is deliberately its own store
 * rather than a kind of lesson: a resource is not a step in the path and has no
 * progress of its own.
 */
final class Version20260917010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Downloadable file resources attached to a course';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE course_resources (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                course_id UUID NOT NULL,

                -- What the learner sees, and the file behind it.
                title TEXT NOT NULL,
                original_filename TEXT NOT NULL,
                storage_key TEXT NOT NULL,
                mime_type TEXT NOT NULL DEFAULT 'application/octet-stream',
                size_bytes BIGINT NOT NULL DEFAULT 0,

                -- The order they are shown in, and who added it when.
                position INT NOT NULL DEFAULT 0,
                created_by UUID,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at TIMESTAMPTZ,

                CONSTRAINT cr_title_present CHECK (BTRIM(title) <> ''),
                CONSTRAINT cr_course_fk
                    FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE CASCADE,
                CONSTRAINT cr_creator_fk
                    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX cr_course_idx ON course_resources (course_id)
                WHERE deleted_at IS NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS course_resources');
    }
}
