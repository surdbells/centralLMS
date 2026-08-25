<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seeds the four lateral built-in roles.
 *
 * The identity migration seeded five roles — the ladder this system was
 * originally designed around. The legacy database turned out to seed **eight**
 * per tenant, four of which sit beside the ladder rather than on it:
 * content creator, HR staff, HR manager and team manager.
 *
 * Without rows here, `SystemRole` defines them but `user_roles` cannot
 * reference them, so the legacy import silently dropped those assignments.
 */
final class Version20260825150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the lateral built-in roles: content creator, HR, HR manager, team manager';
    }

    public function up(Schema $schema): void
    {
        foreach ([
            ['content_creator', 'Content Creator'],
            ['hr', 'HR Staff'],
            ['hr_manager', 'HR Manager'],
            ['manager', 'Team Manager'],
        ] as [$key, $name]) {
            // ON CONFLICT so the migration is safe on a database where they
            // were added by hand while this was being sorted out.
            $this->addSql(
                'INSERT INTO roles (tenant_id, key, name, is_system)
                 VALUES (NULL, :key, :name, TRUE)
                 ON CONFLICT DO NOTHING',
                ['key' => $key, 'name' => $name],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "DELETE FROM roles
             WHERE tenant_id IS NULL
               AND key IN ('content_creator', 'hr', 'hr_manager', 'manager')",
        );
    }
}
