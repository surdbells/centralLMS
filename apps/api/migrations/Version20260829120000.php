<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What a learner wrote down while working through a lesson.
 *
 * The legacy had two implementations of this and they did not agree.
 *
 *  - `class/Note.php`, behind eleven endpoints under `user/notes/`, inserts
 *    `title` and `lesson_timestamp`. **Neither column exists.** Every create,
 *    update and search in that half fails against the shipped schema, which is
 *    why the notes screen was never usable.
 *  - `user/course-player.php`'s `saveNote()` writes `content` and
 *    `video_timestamp`, which do exist. All five notes in the database came
 *    from there. It can create and read; it cannot edit, delete, pin or search.
 *
 * The Angular `Note` interface matches the broken half, down to `title` and
 * `lesson_timestamp` — so the intent is not in question, only the delivery.
 * This table carries both fields under one name each, and the six routes over
 * it do everything the eleven files meant to.
 *
 * **`enrollment_id` is not carried.** The legacy kept it and queried by it, so
 * a note lived and died with an enrolment: unenrol somebody and re-enrol them,
 * and their notes are orphaned from the course they are reading. A note belongs
 * to a person and a lesson. "The notes for this enrolment" is the same set of
 * rows as "this person's notes on this course", and it survives re-enrolment.
 */
final class Version20260829120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Learner notes against lessons';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'PostgreSQL only.',
        );

        // So a note's course can be pinned to its lesson's course below. The
        // same key `course_modules` and `enrollments` already carry.
        $this->addSql('ALTER TABLE lessons ADD CONSTRAINT lessons_id_course_uniq UNIQUE (id, course_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE notes (
                id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id  UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                user_id    UUID NOT NULL,

                lesson_id  UUID NOT NULL,
                -- Denormalised for "my notes on this course", and pinned to the
                -- lesson's own course by the composite key below so the two
                -- cannot disagree. Same pattern as `lessons`.
                course_id  UUID NOT NULL,

                title      TEXT,
                content    TEXT NOT NULL,

                -- How far into the lesson. The legacy called this
                -- `video_timestamp` in one implementation and
                -- `lesson_timestamp` in the other; it is a position in a
                -- recording, and only a recording has one.
                position_seconds INTEGER,

                is_pinned  BOOLEAN NOT NULL DEFAULT FALSE,

                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at TIMESTAMPTZ,

                CONSTRAINT notes_content_present CHECK (btrim(content) <> ''),
                CONSTRAINT notes_position_sane CHECK (position_seconds IS NULL OR position_seconds >= 0),

                -- Three composite keys, and between them a cross-tenant note
                -- is unrepresentable: the writer belongs to the tenant, the
                -- course belongs to the tenant, and the lesson belongs to the
                -- course.
                CONSTRAINT notes_user_fk
                    FOREIGN KEY (user_id, tenant_id) REFERENCES users (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT notes_course_fk
                    FOREIGN KEY (course_id, tenant_id) REFERENCES courses (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT notes_lesson_fk
                    FOREIGN KEY (lesson_id, course_id) REFERENCES lessons (id, course_id) ON DELETE CASCADE
            )
        SQL);

        // The two questions a notes screen asks: everything I have written,
        // and everything I have written here. Pinned first in both.
        $this->addSql(<<<'SQL'
            CREATE INDEX notes_mine_idx
                ON notes (tenant_id, user_id, is_pinned DESC, created_at DESC)
                WHERE deleted_at IS NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX notes_lesson_idx
                ON notes (user_id, lesson_id)
                WHERE deleted_at IS NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX notes_course_idx
                ON notes (user_id, course_id)
                WHERE deleted_at IS NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS notes');
        $this->addSql('ALTER TABLE lessons DROP CONSTRAINT IF EXISTS lessons_id_course_uniq');
    }
}
