<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Where a SCORM package left off.
 *
 * SCORM resume needs the package's own bookmark back on the next launch:
 * `cmi.core.lesson_location` / `cmi.location` and, above all,
 * `cmi.suspend_data`, the opaque blob most authoring tools keep the learner's
 * real position and answers in. Nothing stored either, so every launch started
 * from the first slide.
 *
 * One JSONB column on the lesson's progress row rather than a table of its
 * own: it is one learner's current state for one lesson, read and written with
 * exactly that row, and it already has the right keys, tenant scoping and
 * cascades. Shape: `{location, suspendData, lessonStatus, exit, totalTime}`,
 * all strings, any of them absent. NULL for every non-SCORM lesson. The API
 * caps suspendData at 64000 characters (the SCORM 2004 limit), so a row cannot
 * grow without bound.
 */
final class Version20261112010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'lesson_progress.scorm_state: the SCORM runtime state that resumes a package';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration targets PostgreSQL.',
        );

        // Nullable with no default: adding it is a catalogue-only change, with
        // no table rewrite on a large lesson_progress.
        $this->addSql('ALTER TABLE lesson_progress ADD COLUMN scorm_state JSONB');

        // An object or nothing: the merge in the progress upsert (`||`)
        // assumes an object, and a stray array or scalar would make it fail.
        $this->addSql(<<<'SQL'
            ALTER TABLE lesson_progress ADD CONSTRAINT lesson_progress_scorm_state_object
                CHECK (scorm_state IS NULL OR jsonb_typeof(scorm_state) = 'object')
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lesson_progress DROP CONSTRAINT IF EXISTS lesson_progress_scorm_state_object');
        $this->addSql('ALTER TABLE lesson_progress DROP COLUMN IF EXISTS scorm_state');
    }
}
