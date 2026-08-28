<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * More than one instructor on a session.
 *
 * A session named one person in a column, and the screen that assigns
 * instructors — with roles, a host flag and a table of them — had nothing
 * behind it. A two-day workshop with a lead and two assistants could be
 * described in the interface and nowhere else.
 *
 * The column goes rather than staying alongside the table. Two places
 * recording who teaches a session is two answers to the question, and the one
 * that gets updated is whichever the last person to touch the code remembered.
 * `instructorId` is now read from the primary row and written back to it.
 *
 * Two constraints carry the meaning:
 *
 * - One primary per session. "Co-instructor" and "assistant" are relative to
 *   somebody, and two leads is not a hierarchy anybody described.
 * - One host per session. The meeting provider issues one host URL, so two
 *   hosts is a state it cannot represent — the second would simply not work,
 *   silently, on the morning.
 */
final class Version20260911090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let a session have more than one instructor';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE session_instructors (
                session_id UUID NOT NULL,
                user_id UUID NOT NULL,
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                role TEXT NOT NULL DEFAULT 'primary',
                is_host BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                PRIMARY KEY (session_id, user_id),

                CONSTRAINT session_instructors_session_fk
                    FOREIGN KEY (session_id) REFERENCES training_sessions (id) ON DELETE CASCADE,
                CONSTRAINT session_instructors_user_fk
                    FOREIGN KEY (user_id, tenant_id) REFERENCES users (id, tenant_id) ON DELETE CASCADE,

                CONSTRAINT session_instructors_role_known CHECK (
                    role IN ('primary', 'co_instructor', 'assistant')
                )
            )
        SQL);

        // One lead. "Co-instructor" and "assistant" are relative to somebody.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX session_instructors_one_primary
                ON session_instructors (session_id) WHERE role = 'primary'
        SQL);

        // One host. The provider issues one host URL; a second host is a state
        // it cannot represent, and would fail silently on the morning.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX session_instructors_one_host
                ON session_instructors (session_id) WHERE is_host
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX session_instructors_user_idx
                ON session_instructors (tenant_id, user_id)
        SQL);

        // Carry across whoever each session already named, as its lead and
        // its host. Nothing is lost by dropping the column.
        $this->addSql(<<<'SQL'
            INSERT INTO session_instructors (session_id, user_id, tenant_id, role, is_host)
            SELECT s.id, s.instructor_id, s.tenant_id, 'primary', TRUE
            FROM training_sessions s
            JOIN users u ON u.id = s.instructor_id AND u.tenant_id = s.tenant_id
            WHERE s.instructor_id IS NOT NULL
            ON CONFLICT DO NOTHING
        SQL);

        $this->addSql('ALTER TABLE training_sessions DROP COLUMN instructor_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_sessions ADD COLUMN instructor_id UUID');

        $this->addSql(<<<'SQL'
            UPDATE training_sessions s
            SET instructor_id = i.user_id
            FROM session_instructors i
            WHERE i.session_id = s.id AND i.role = 'primary'
        SQL);

        $this->addSql('DROP TABLE IF EXISTS session_instructors');
    }
}
