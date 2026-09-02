<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Strip the `/v1` prefix from stored avatar URLs.
 *
 * The avatar upload wrote `avatarUrl` as `/v1/users/{id}/avatar?v=...`, but the
 * client resolves that against an API base that already carries `/v1`, so every
 * uploaded avatar came out as `/v1/v1/users/...` and rendered broken. The upload
 * now writes a base-relative path; this repairs the rows written before that.
 *
 * Idempotent: it only touches rows whose value still begins with `/v1/`.
 */
final class Version20260918010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make stored avatar URLs base-relative (drop the /v1 prefix)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE users
               SET avatar_url = regexp_replace(avatar_url, '^/v1/', '/')
             WHERE avatar_url LIKE '/v1/%'
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Put the prefix back on the avatar paths this served.
        $this->addSql(<<<'SQL'
            UPDATE users
               SET avatar_url = '/v1' || avatar_url
             WHERE avatar_url LIKE '/users/%/avatar%'
        SQL);
    }
}
