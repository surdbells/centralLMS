<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Saved reports and their schedules.
 *
 * The reports themselves need no tables — they are queries over what the other
 * modules already store, and the legacy's 190 report queries invent nothing.
 * What does need storing is a named set of criteria somebody wants back, and
 * a note to run it every Monday.
 *
 * **Three tables become two, and they disagreed.** `saved_reports` carried a
 * `schedule` JSON column; `scheduled_reports` was a separate table with the
 * same job spelled out in columns; and `scheduled_report_runs` — the log for
 * both — has a `saved_report_id` foreign key, so a row created by the
 * *scheduled_reports* path points at an id from the other table entirely. One
 * `saved_reports` row here holds the criteria and, optionally, the schedule.
 *
 * **`next_run_at` is not stored.** It was on `scheduled_reports` beside the
 * frequency it is derived from, and nothing recomputed it when the frequency
 * changed — the same failure as `live_training_reminders.scheduled_at`, with
 * the same consequence: a weekly report changed to monthly keeps arriving
 * weekly until someone notices. Due-ness is computed from the frequency and
 * the last completed run, in {@see \Central\Api\Domain\Report\ReportSchedule}.
 *
 * **`last_run_at` and `last_run_status` are not stored either.** They are the
 * newest row of `report_runs`, which is a join, not a copy.
 *
 * **Not ported:** `custom_reports`, which stores a JSON description of a
 * report somebody assembled in a builder — source table, columns, filters,
 * grouping. That is a query builder exposed to end users, and building one
 * that cannot be pointed at another tenant's rows is a piece of work in its
 * own right rather than a port. Both legacy rows are empty.
 */
final class Version20260828090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Saved report criteria, their schedules, and a log of runs';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'PostgreSQL only.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE saved_reports (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                user_id     UUID NOT NULL,

                report_key  TEXT NOT NULL,
                name        TEXT NOT NULL,
                description TEXT,

                -- The criteria, exactly as ReportCriteria::toArray() writes
                -- them. Never the tenant: that comes from whoever runs it, so
                -- a saved report cannot carry someone else's organisation.
                criteria    JSONB NOT NULL DEFAULT '{}'::jsonb,

                -- Visible to colleagues who hold the report's permission.
                -- Off by default: a saved report is a question somebody asked,
                -- and its name often says why.
                is_shared   BOOLEAN NOT NULL DEFAULT FALSE,

                -- --- the schedule, all null when it is not scheduled -------
                frequency    TEXT,
                day_of_week  SMALLINT,
                day_of_month SMALLINT,
                time_of_day  TIME,
                recipients   TEXT[] NOT NULL DEFAULT '{}',
                format       TEXT NOT NULL DEFAULT 'csv',
                schedule_paused BOOLEAN NOT NULL DEFAULT FALSE,

                created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at  TIMESTAMPTZ,

                CONSTRAINT saved_reports_name_present CHECK (btrim(name) <> ''),
                CONSTRAINT saved_reports_criteria_is_object
                    CHECK (jsonb_typeof(criteria) = 'object'),
                CONSTRAINT saved_reports_frequency_known
                    CHECK (frequency IS NULL OR frequency IN ('daily', 'weekly', 'monthly')),
                CONSTRAINT saved_reports_format_known
                    CHECK (format IN ('csv')),

                -- A schedule is a frequency and a time, or nothing at all.
                -- Half of one is a report that never runs, and nothing to
                -- show the person who set it up why.
                CONSTRAINT saved_reports_schedule_complete
                    CHECK ((frequency IS NULL) = (time_of_day IS NULL)),

                -- Weekly needs a day of the week and monthly a day of the
                -- month; neither needs the other's.
                CONSTRAINT saved_reports_weekly_has_a_day
                    CHECK (frequency <> 'weekly' OR day_of_week BETWEEN 1 AND 7),
                CONSTRAINT saved_reports_monthly_has_a_day
                    CHECK (frequency <> 'monthly' OR day_of_month BETWEEN 1 AND 28),

                -- A schedule with nobody to send to produces a file that goes
                -- nowhere, every week, for ever.
                CONSTRAINT saved_reports_schedule_has_recipients
                    CHECK (frequency IS NULL OR cardinality(recipients) > 0),

                CONSTRAINT saved_reports_user_fk
                    FOREIGN KEY (user_id, tenant_id) REFERENCES users (id, tenant_id) ON DELETE CASCADE
            )
        SQL);

        // 28 rather than 31: a monthly report set for the 31st would skip
        // February, April, June, September and November entirely, and the
        // person who set it would find out in March.
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN saved_reports.day_of_month IS
                'Capped at 28 so a monthly schedule cannot skip short months.'
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX saved_reports_mine_idx
                ON saved_reports (tenant_id, user_id)
                WHERE deleted_at IS NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX saved_reports_shared_idx
                ON saved_reports (tenant_id)
                WHERE is_shared AND deleted_at IS NULL
        SQL);

        // The scheduler's only query.
        $this->addSql(<<<'SQL'
            CREATE INDEX saved_reports_scheduled_idx
                ON saved_reports (tenant_id)
                WHERE frequency IS NOT NULL AND NOT schedule_paused AND deleted_at IS NULL
        SQL);

        // Two people may each keep a report called "Monthly compliance"; one
        // person may not keep two.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX saved_reports_name_uniq
                ON saved_reports (tenant_id, user_id, lower(btrim(name)))
                WHERE deleted_at IS NULL
        SQL);

        $this->addSql('CREATE UNIQUE INDEX saved_reports_id_tenant_uniq ON saved_reports (id, tenant_id)');

        // --- the log -----------------------------------------------------
        //
        // What `last_run_at` and `last_run_status` were copies of.
        $this->addSql(<<<'SQL'
            CREATE TABLE report_runs (
                id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id       UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                saved_report_id UUID NOT NULL,

                -- Whether a person asked or the schedule did. A manual run
                -- should not satisfy a weekly schedule, and without this the
                -- two are indistinguishable afterwards.
                trigger         TEXT NOT NULL DEFAULT 'schedule',

                status          TEXT NOT NULL DEFAULT 'running',
                started_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                completed_at    TIMESTAMPTZ,

                row_count       INTEGER,
                error           TEXT,

                -- Who the file actually went to, which is not always who the
                -- schedule named — an address can fail.
                delivered_to    TEXT[] NOT NULL DEFAULT '{}',

                CONSTRAINT report_runs_trigger_known
                    CHECK (trigger IN ('schedule', 'manual')),
                CONSTRAINT report_runs_status_known
                    CHECK (status IN ('running', 'completed', 'failed')),
                CONSTRAINT report_runs_finished_consistent
                    CHECK ((status = 'running') = (completed_at IS NULL)),
                CONSTRAINT report_runs_failed_has_a_reason
                    CHECK (status <> 'failed' OR error IS NOT NULL),
                CONSTRAINT report_runs_completed_has_a_count
                    CHECK (status <> 'completed' OR row_count IS NOT NULL),

                CONSTRAINT report_runs_saved_report_fk
                    FOREIGN KEY (saved_report_id, tenant_id)
                    REFERENCES saved_reports (id, tenant_id) ON DELETE CASCADE
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX report_runs_latest_idx
                ON report_runs (saved_report_id, started_at DESC)
        SQL);

        // "Has this schedule already run today" — the question that replaces
        // a stored next_run_at.
        $this->addSql(<<<'SQL'
            CREATE INDEX report_runs_scheduled_idx
                ON report_runs (saved_report_id, completed_at DESC)
                WHERE status = 'completed' AND trigger = 'schedule'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS report_runs');
        $this->addSql('DROP TABLE IF EXISTS saved_reports');
    }
}
