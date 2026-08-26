<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Points, badges and levels.
 *
 * Unlike most of this port, gamification was in use: `user_points` holds 122
 * rows and `user_badges` 10. What was not maintained is the table that
 * summarised them.
 *
 * **`user_level_progress` is not ported.** It stored `total_points` and
 * `current_level_id` beside a 122-row ledger that already implied both — and
 * held a single row, so for every user but one the summary was simply absent.
 * A points total is `SUM(point_events.points)` and a level is the highest rung
 * whose threshold that total clears. Neither is written down.
 *
 * **`leaderboards` is not ported either.** Its three rows were saved queries —
 * a name, a type and a period — and the standings were computed on read
 * anyway. The type and period are parameters here.
 *
 * **`user_levels` becomes `gamification_levels`.** The legacy had two tables
 * called something-levels meaning entirely different things: `levels` is the
 * organisational hierarchy (Junior, Senior, Manager), already ported as
 * `lookups(kind = 'level')`, and `user_levels` is the points ladder. Sharing a
 * name is how somebody joins the wrong one.
 *
 * **`point_type` becomes a constrained column.** Across 122 rows it holds
 * eight spellings for five things — `course` and `course_completion`,
 * `assessment` and `assessment_pass` and `quiz_passed`. A free-text column
 * cannot be grouped, which is why the breakdown screen showed each of them as
 * a separate line.
 */
final class Version20260828140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Point events, badges and the level ladder';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'PostgreSQL only.',
        );

        // -- the ledger ---------------------------------------------------
        //
        // Named for what it is. `user_points` reads like a balance, and a
        // balance is exactly what it is not: every row is one award, and the
        // total is their sum.
        $this->addSql(<<<'SQL'
            CREATE TABLE point_events (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                user_id     UUID NOT NULL,

                -- Signed. An award can be taken back — a course completion
                -- reversed, points granted in error — and a correcting entry
                -- keeps the history readable where a deletion would not.
                points      INTEGER NOT NULL,

                reason      TEXT NOT NULL,
                description TEXT,

                subject_type TEXT,
                subject_id   UUID,

                -- Who granted it, when a person did. Null for the automatic
                -- awards, which is most of them.
                awarded_by  UUID,

                earned_at   TIMESTAMPTZ NOT NULL DEFAULT now(),

                CONSTRAINT point_events_reason_known CHECK (reason IN (
                    'course_completed', 'lesson_completed', 'assessment_passed',
                    'certificate_earned', 'session_attended', 'streak', 'manual'
                )),
                CONSTRAINT point_events_not_zero CHECK (points <> 0),
                CONSTRAINT point_events_subject_complete
                    CHECK ((subject_type IS NULL) = (subject_id IS NULL)),
                -- A manual award is somebody's decision and needs a name
                -- against it; the automatic ones have no actor by definition.
                CONSTRAINT point_events_manual_has_an_actor
                    CHECK (reason <> 'manual' OR awarded_by IS NOT NULL),

                CONSTRAINT point_events_user_fk
                    FOREIGN KEY (user_id, tenant_id) REFERENCES users (id, tenant_id) ON DELETE CASCADE
            )
        SQL);

        // The only query that matters: one person's total, and their history.
        $this->addSql(<<<'SQL'
            CREATE INDEX point_events_user_idx ON point_events (tenant_id, user_id, earned_at DESC)
        SQL);

        // The leaderboard, which sums over a period.
        $this->addSql(<<<'SQL'
            CREATE INDEX point_events_period_idx ON point_events (tenant_id, earned_at)
        SQL);

        // Earning the same thing twice should not pay twice. Partial, because
        // `manual` and `streak` legitimately repeat and carry no subject.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX point_events_once_per_subject_uniq
                ON point_events (tenant_id, user_id, reason, subject_id)
                WHERE subject_id IS NOT NULL AND reason <> 'manual'
        SQL);

        // -- the ladder ---------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE gamification_levels (
                id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id      UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,

                number         SMALLINT NOT NULL,
                name           TEXT NOT NULL,
                required_points INTEGER NOT NULL,
                icon_url       TEXT,
                rewards        TEXT,

                created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at     TIMESTAMPTZ,

                CONSTRAINT gamification_levels_name_present CHECK (btrim(name) <> ''),
                CONSTRAINT gamification_levels_number_positive CHECK (number >= 1),
                CONSTRAINT gamification_levels_threshold_sane CHECK (required_points >= 0)
            )
        SQL);

        // One rung per number, and one per threshold: two levels needing the
        // same points means the ladder has no answer for somebody standing
        // there.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX gamification_levels_number_uniq
                ON gamification_levels (tenant_id, number)
                WHERE deleted_at IS NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX gamification_levels_threshold_uniq
                ON gamification_levels (tenant_id, required_points)
                WHERE deleted_at IS NULL
        SQL);

        // -- badges -------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE badges (
                id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id    UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,

                name         TEXT NOT NULL,
                description  TEXT,
                icon         TEXT,
                image_url    TEXT,
                type         TEXT NOT NULL DEFAULT 'achievement',
                points_value INTEGER NOT NULL DEFAULT 0,

                -- A badge tied to finishing one course. Null for the general
                -- ones — "first login", "ten courses".
                course_id    UUID,

                /**
                 * What earns it, when it is awarded automatically.
                 *
                 * A shape the awarder understands, e.g.
                 * `{"coursesCompleted": 10}`. An empty object means the badge
                 * is only ever given by hand.
                 */
                criteria     JSONB NOT NULL DEFAULT '{}'::jsonb,

                created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at   TIMESTAMPTZ,

                CONSTRAINT badges_name_present CHECK (btrim(name) <> ''),
                CONSTRAINT badges_type_known CHECK (type IN (
                    'achievement', 'milestone', 'completion', 'streak', 'participation'
                )),
                CONSTRAINT badges_points_not_negative CHECK (points_value >= 0),
                CONSTRAINT badges_criteria_is_object CHECK (jsonb_typeof(criteria) = 'object'),

                CONSTRAINT badges_course_fk
                    FOREIGN KEY (course_id, tenant_id) REFERENCES courses (id, tenant_id) ON DELETE CASCADE
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX badges_name_uniq
                ON badges (tenant_id, lower(btrim(name)))
                WHERE deleted_at IS NULL
        SQL);

        $this->addSql('CREATE UNIQUE INDEX badges_id_tenant_uniq ON badges (id, tenant_id)');

        // -- awards -------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE badge_awards (
                id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id  UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                user_id    UUID NOT NULL,
                badge_id   UUID NOT NULL,

                awarded_by UUID,
                subject_type TEXT,
                subject_id   UUID,

                awarded_at TIMESTAMPTZ NOT NULL DEFAULT now(),

                CONSTRAINT badge_awards_subject_complete
                    CHECK ((subject_type IS NULL) = (subject_id IS NULL)),

                CONSTRAINT badge_awards_user_fk
                    FOREIGN KEY (user_id, tenant_id) REFERENCES users (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT badge_awards_badge_fk
                    FOREIGN KEY (badge_id, tenant_id) REFERENCES badges (id, tenant_id) ON DELETE CASCADE
            )
        SQL);

        // A badge is held once. The legacy had no such index, and
        // `awardBadge()` checked first and inserted after — which is a race,
        // and the reason somebody can hold the same badge twice.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX badge_awards_once_uniq ON badge_awards (user_id, badge_id)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX badge_awards_user_idx ON badge_awards (tenant_id, user_id, awarded_at DESC)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS badge_awards');
        $this->addSql('DROP TABLE IF EXISTS badges');
        $this->addSql('DROP TABLE IF EXISTS gamification_levels');
        $this->addSql('DROP TABLE IF EXISTS point_events');
    }
}
