<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Three profile fields every organisation asks for and the identity slice left
 * out: gender, an employee number, and a second reporting line.
 *
 *  - **gender** is plain text. The application constrains it to a known set
 *    ('male', 'female', 'other', 'undisclosed'), but the column does not: a
 *    tenant that needs another value should not need a migration to store it,
 *    and a CHECK here would turn that into one.
 *  - **employee_id** is the organisation's own staff number, free text because
 *    their formats are theirs — leading zeros, letters, slashes. Not made
 *    unique: a legacy import with two blanks or a genuine duplicate would fail
 *    the whole batch at the database rather than as a row-level warning, which
 *    is the import's job to raise, not this column's.
 *  - **line_executive_id** is a second manager link, the "line executive" above
 *    the line manager that the competency model already assesses against. Same
 *    shape as manager_id: a self-referential FK that nulls when the executive's
 *    own account is removed.
 *
 * Everything else the 14-field profile needs already exists: job title, job
 * grade (level), department and work location (branch) are lookup ids; division,
 * organisation/subsidiary and entity are tenant-defined custom fields.
 */
final class Version20261024000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add gender, employee_id and line_executive_id to users';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration targets PostgreSQL.',
        );

        $this->addSql('ALTER TABLE users ADD COLUMN gender TEXT');
        $this->addSql('ALTER TABLE users ADD COLUMN employee_id TEXT');
        $this->addSql(
            'ALTER TABLE users ADD COLUMN line_executive_id UUID
                 REFERENCES users (id) ON DELETE SET NULL',
        );

        // The reporting queries walk this link the same way they walk
        // manager_id, so it gets the same partial index.
        $this->addSql(
            'CREATE INDEX users_line_executive_idx ON users (line_executive_id)
                 WHERE line_executive_id IS NOT NULL',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS users_line_executive_idx');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS line_executive_id');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS employee_id');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS gender');
    }
}
