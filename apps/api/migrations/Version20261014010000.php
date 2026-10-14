<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Which job competency profiles an employee is assigned to.
 *
 * Until now the assessment row was the only link between a person and the
 * profile of their role — an admin bulk-assigned people each cycle. This makes
 * the assignment a durable fact of its own, so that creating a new cycle can
 * auto-start an assessment for everyone who holds a profile. A person may hold
 * more than one profile (a role that spans two functions, a secondment), so it
 * is a join table, and a new cycle seeds one assessment per (employee, profile).
 */
final class Version20261014010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Assign employees to job competency profiles (user_job_profiles)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE user_job_profiles (
                tenant_id  UUID NOT NULL,
                user_id    UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                profile_id UUID NOT NULL REFERENCES job_competency_profiles (id) ON DELETE CASCADE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                PRIMARY KEY (tenant_id, user_id, profile_id)
            )
        SQL);

        // Finding who holds a profile (for auto-start, and for a profile's roster).
        $this->addSql('CREATE INDEX ujp_profile_idx ON user_job_profiles (tenant_id, profile_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS user_job_profiles');
    }
}
