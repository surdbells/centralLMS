<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * How far a SCORM package has got through being unpacked.
 *
 * A SCORM upload is a zip that the worker unzips off the request, which takes a
 * moment. `extracted_path` already says when that has finished, but not whether
 * it is in progress or has failed — so the upload screen could only guess. This
 * records the worker's progress (`processing`/`failed`), which the UI polls to
 * show "unpacking…", then the manifest summary or the failure. `ready` is still
 * read from `extracted_path` being set, so a successful unpack needs no second
 * write to agree with itself.
 */
final class Version20261023000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track SCORM extraction progress (media.scorm_status)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media ADD COLUMN scorm_status TEXT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media DROP COLUMN scorm_status');
    }
}
