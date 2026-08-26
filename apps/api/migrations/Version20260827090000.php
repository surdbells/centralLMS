<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Instructor-led training: programmes, their sessions, enrolment and
 * attendance.
 *
 * This is the module the original brief called out as not working, and the
 * legacy data says exactly why. `live_training_sessions` row 2 is marked
 * `session_type = 'virtual'`, and every one of its meeting columns —
 * `video_platform`, `meeting_id`, `meeting_url`, `join_url`, `host_url`,
 * `external_meeting_id` — is NULL. No meeting was ever created for it, because
 * `LiveTraining::createVirtualMeeting()` returned
 * `https://meet.example.com/` plus random hex while a complete 896-line
 * provider layer sat beside it with zero call sites.
 *
 * The provider layer is already ported (`Infrastructure\Ilt\`, with Zoom,
 * Google Meet and Teams), as is per-tenant OAuth credential storage
 * (`video_integrations`). These tables are what it schedules against.
 *
 * Resolved from the legacy shapes:
 *
 *  - **`session_date` + `start_time` + `end_time` + `timezone` become two
 *    `timestamptz` columns.** A date, two times and a timezone name in four
 *    columns is how a session moves by an hour twice a year. The timezone is
 *    kept as a separate column because it is still needed to *display* the
 *    session in the tenant's own zone and to pass to the meeting provider —
 *    but it no longer defines the instant.
 *  - **`meeting_url` and `meeting_id` are dropped**, duplicating `join_url`
 *    and `external_meeting_id`. Four columns for two facts.
 *  - **`is_active` is dropped** from both tables, duplicating `status`.
 *  - **`venue_cost`, `instructor_cost` and `materials_cost` are dropped** —
 *    NULL in every row, and `training_costs` is a table of its own.
 *  - **`skills_count` is dropped**: a stored count of rows in
 *    `live_training_skills`, which is the same mistake as the assessment
 *    `total_questions` that disagreed with reality in 16 of 19 rows.
 *  - **`qr_code_expires_at` gets a check constraint.** In the live data the
 *    one generated token expires *before* it was generated — created
 *    `2026-01-12 23:19:33`, expiring `2026-01-12 09:15:00`. It was born
 *    expired, and nothing noticed.
 *
 * `reminder_channels` is worth a note: in live row 1 it holds
 * `"\"[\\\"email\\\", \\\"in_app\\\"]\""` — a JSON string containing a JSON
 * string containing a JSON array, encoded three times over. Row 2 holds a
 * clean array. The import will need to unwrap until it reaches an array
 * rather than decoding once.
 */
final class Version20260827090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Instructor-led training: programmes, sessions, enrolment and attendance.';
    }

    public function up(Schema $schema): void
    {
        $this->trainings();
        $this->sessions();
        $this->enrolments();
        $this->attendance();
        $this->assessmentLink();
    }

    private function trainings(): void
    {
        $this->addSql("
            CREATE TABLE live_trainings (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id   BIGINT,
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,

                title       TEXT NOT NULL,
                description TEXT,
                summary     TEXT,
                thumbnail_url TEXT,

                category_id   UUID,
                category_kind TEXT GENERATED ALWAYS AS ('category') STORED,

                delivery TEXT NOT NULL DEFAULT 'virtual',
                status   TEXT NOT NULL DEFAULT 'draft',

                max_capacity   INTEGER,
                allow_waitlist BOOLEAN NOT NULL DEFAULT FALSE,

                -- How much of the programme must be attended to pass it.
                min_attendance_percentage NUMERIC(5,2) NOT NULL DEFAULT 100,
                mandatory_sessions_only   BOOLEAN NOT NULL DEFAULT FALSE,

                issues_certificate BOOLEAN NOT NULL DEFAULT FALSE,

                -- Which reminders go out. A constrained array rather than the
                -- legacy free JSON, which held a triple-encoded string.
                reminder_offsets_minutes INTEGER[] NOT NULL DEFAULT '{1440,60}',
                reminder_channels        TEXT[] NOT NULL DEFAULT '{email}',

                -- Attendance windows for physical sessions.
                qr_grace_period_minutes       INTEGER NOT NULL DEFAULT 15,
                qr_valid_before_start_minutes INTEGER NOT NULL DEFAULT 30,

                -- Minimum minutes in a virtual session to count as present.
                min_virtual_duration_minutes INTEGER NOT NULL DEFAULT 30,

                created_by   UUID REFERENCES users (id) ON DELETE SET NULL,
                published_at TIMESTAMPTZ,
                created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at   TIMESTAMPTZ,
                deleted_at   TIMESTAMPTZ,

                CONSTRAINT live_trainings_title_not_blank CHECK (length(btrim(title)) > 0),
                CONSTRAINT live_trainings_delivery_check
                    CHECK (delivery IN ('physical', 'virtual', 'hybrid')),
                CONSTRAINT live_trainings_status_check
                    CHECK (status IN ('draft', 'published', 'ongoing', 'completed', 'cancelled')),
                CONSTRAINT live_trainings_attendance_check
                    CHECK (min_attendance_percentage BETWEEN 0 AND 100),
                CONSTRAINT live_trainings_capacity_check
                    CHECK (max_capacity IS NULL OR max_capacity > 0),
                CONSTRAINT live_trainings_windows_check CHECK (
                    qr_grace_period_minutes >= 0
                    AND qr_valid_before_start_minutes >= 0
                    AND min_virtual_duration_minutes >= 0
                ),
                CONSTRAINT live_trainings_category_fk
                    FOREIGN KEY (category_id, category_kind) REFERENCES lookups (id, kind),
                CONSTRAINT live_trainings_id_tenant_uniq UNIQUE (id, tenant_id)
            )
        ");

        $this->addSql("
            CREATE INDEX live_trainings_tenant_status_idx
                ON live_trainings (tenant_id, status) WHERE deleted_at IS NULL
        ");
        $this->addSql('
            CREATE UNIQUE INDEX live_trainings_legacy_uniq
                ON live_trainings (legacy_id) WHERE legacy_id IS NOT NULL
        ');
    }

    private function sessions(): void
    {
        $this->addSql("
            CREATE TABLE training_sessions (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id   BIGINT,
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                training_id UUID NOT NULL,

                title       TEXT NOT NULL,
                description TEXT,
                sequence    INTEGER NOT NULL DEFAULT 1,
                delivery    TEXT NOT NULL DEFAULT 'virtual',

                -- One instant, not a date plus a time plus a zone. The zone is
                -- kept below for display and for the meeting provider, but it
                -- no longer decides when the session actually is.
                starts_at TIMESTAMPTZ NOT NULL,
                ends_at   TIMESTAMPTZ NOT NULL,
                timezone  TEXT NOT NULL DEFAULT 'UTC',

                instructor_id UUID REFERENCES users (id) ON DELETE SET NULL,

                -- Where, for physical and hybrid.
                venue_name     TEXT,
                venue_address  TEXT,
                venue_room     TEXT,
                venue_capacity INTEGER,
                venue_map_url  TEXT,

                -- The meeting, for virtual and hybrid. Written by the provider
                -- layer when the session is scheduled — the whole point of this
                -- module, and NULL in every legacy row including the virtual one.
                platform            TEXT,
                integration_id      UUID REFERENCES video_integrations (id) ON DELETE SET NULL,
                external_meeting_id TEXT,
                join_url            TEXT,
                host_url            TEXT,
                meeting_password    TEXT,
                meeting_scheduled_at TIMESTAMPTZ,
                meeting_error       TEXT,

                is_mandatory           BOOLEAN NOT NULL DEFAULT TRUE,
                allow_late_join        BOOLEAN NOT NULL DEFAULT TRUE,
                late_threshold_minutes INTEGER NOT NULL DEFAULT 15,

                -- Physical attendance, scanned at the door.
                qr_token        TEXT,
                qr_generated_at TIMESTAMPTZ,
                qr_expires_at   TIMESTAMPTZ,

                status TEXT NOT NULL DEFAULT 'scheduled',

                attendance_locked    BOOLEAN NOT NULL DEFAULT FALSE,
                attendance_locked_at TIMESTAMPTZ,
                attendance_locked_by UUID REFERENCES users (id) ON DELETE SET NULL,

                actual_started_at TIMESTAMPTZ,
                actual_ended_at   TIMESTAMPTZ,

                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ,
                deleted_at TIMESTAMPTZ,

                CONSTRAINT training_sessions_title_not_blank CHECK (length(btrim(title)) > 0),
                CONSTRAINT training_sessions_delivery_check
                    CHECK (delivery IN ('physical', 'virtual', 'hybrid')),
                CONSTRAINT training_sessions_status_check
                    CHECK (status IN ('scheduled', 'ongoing', 'completed', 'cancelled')),
                CONSTRAINT training_sessions_platform_check
                    CHECK (platform IS NULL OR platform IN ('zoom', 'google_meet', 'microsoft_teams')),
                CONSTRAINT training_sessions_sequence_check CHECK (sequence > 0),
                CONSTRAINT training_sessions_late_check CHECK (late_threshold_minutes >= 0),

                -- A session that ends before it starts.
                CONSTRAINT training_sessions_order_check CHECK (ends_at > starts_at),

                -- The live data has a QR token that expires before it was
                -- generated: created 23:19:33, expiring 09:15:00 the same day.
                -- It was born expired and nothing noticed.
                CONSTRAINT training_sessions_qr_window_check
                    CHECK (qr_expires_at IS NULL OR qr_generated_at IS NULL OR qr_expires_at > qr_generated_at),

                -- A physical session has no meeting to join.
                CONSTRAINT training_sessions_physical_has_no_meeting
                    CHECK (delivery <> 'physical' OR external_meeting_id IS NULL),

                CONSTRAINT training_sessions_training_fk
                    FOREIGN KEY (training_id, tenant_id)
                    REFERENCES live_trainings (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT training_sessions_id_tenant_uniq UNIQUE (id, tenant_id),
                CONSTRAINT training_sessions_id_training_uniq UNIQUE (id, training_id)
            )
        ");

        $this->addSql('
            CREATE INDEX training_sessions_training_idx
                ON training_sessions (training_id, sequence) WHERE deleted_at IS NULL
        ');
        $this->addSql("
            CREATE INDEX training_sessions_upcoming_idx
                ON training_sessions (tenant_id, starts_at)
                WHERE deleted_at IS NULL AND status = 'scheduled'
        ");

        // A QR token identifies one session, so it must not collide.
        $this->addSql('
            CREATE UNIQUE INDEX training_sessions_qr_uniq
                ON training_sessions (qr_token) WHERE qr_token IS NOT NULL
        ');
        $this->addSql('
            CREATE UNIQUE INDEX training_sessions_legacy_uniq
                ON training_sessions (legacy_id) WHERE legacy_id IS NOT NULL
        ');
    }

    private function enrolments(): void
    {
        $this->addSql("
            CREATE TABLE training_enrollments (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id   BIGINT,
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                training_id UUID NOT NULL,
                user_id     UUID NOT NULL,

                status TEXT NOT NULL DEFAULT 'not_started',
                source TEXT NOT NULL DEFAULT 'direct',

                -- Where a full programme puts people who still want a place.
                waitlisted    BOOLEAN NOT NULL DEFAULT FALSE,
                waitlist_position INTEGER,

                assigned_by UUID REFERENCES users (id) ON DELETE SET NULL,

                -- Derived from attendance by the recorder, in the same
                -- transaction, exactly as course progress is.
                attendance_percentage NUMERIC(5,2) NOT NULL DEFAULT 0,
                sessions_attended     INTEGER NOT NULL DEFAULT 0,

                enrolled_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                completed_at TIMESTAMPTZ,
                created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at   TIMESTAMPTZ,
                deleted_at   TIMESTAMPTZ,

                CONSTRAINT training_enrollments_status_check
                    CHECK (status IN ('not_started', 'in_progress', 'completed', 'dropped')),
                CONSTRAINT training_enrollments_source_check
                    CHECK (source IN ('self', 'direct', 'bulk', 'team', 'branch', 'department')),
                CONSTRAINT training_enrollments_percentage_check
                    CHECK (attendance_percentage BETWEEN 0 AND 100),
                CONSTRAINT training_enrollments_attended_check CHECK (sessions_attended >= 0),
                CONSTRAINT training_enrollments_completion_consistent CHECK (
                    (status = 'completed' AND completed_at IS NOT NULL)
                    OR (status <> 'completed' AND completed_at IS NULL)
                ),
                CONSTRAINT training_enrollments_waitlist_consistent
                    CHECK (waitlisted OR waitlist_position IS NULL),

                CONSTRAINT training_enrollments_training_fk
                    FOREIGN KEY (training_id, tenant_id)
                    REFERENCES live_trainings (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT training_enrollments_user_fk
                    FOREIGN KEY (user_id, tenant_id)
                    REFERENCES users (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT training_enrollments_id_user_uniq UNIQUE (id, user_id)
            )
        ");

        $this->addSql('
            CREATE UNIQUE INDEX training_enrollments_uniq
                ON training_enrollments (training_id, user_id) WHERE deleted_at IS NULL
        ');
        $this->addSql('
            CREATE INDEX training_enrollments_user_idx
                ON training_enrollments (tenant_id, user_id) WHERE deleted_at IS NULL
        ');
        $this->addSql('
            CREATE UNIQUE INDEX training_enrollments_legacy_uniq
                ON training_enrollments (legacy_id) WHERE legacy_id IS NOT NULL
        ');
    }

    private function attendance(): void
    {
        $this->addSql("
            CREATE TABLE session_attendance (
                id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id  BIGINT,
                tenant_id  UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                session_id UUID NOT NULL,
                enrollment_id UUID NOT NULL,
                user_id    UUID NOT NULL,

                status TEXT NOT NULL DEFAULT 'absent',
                mode   TEXT,

                -- Physical: scanned at the door.
                qr_scanned_at    TIMESTAMPTZ,
                qr_scan_location TEXT,

                -- Virtual: reported by the meeting provider. The join and
                -- leave times come from them, not from the learner's browser,
                -- which is the point of reading attendance back from the API.
                joined_at        TIMESTAMPTZ,
                left_at          TIMESTAMPTZ,
                duration_minutes INTEGER,
                provider_data    JSONB,

                -- An instructor overriding the record, which must be visible
                -- as an override rather than indistinguishable from a scan.
                is_manual_override BOOLEAN NOT NULL DEFAULT FALSE,
                override_by     UUID REFERENCES users (id) ON DELETE SET NULL,
                override_at     TIMESTAMPTZ,
                override_reason TEXT,

                marked_at  TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ,

                CONSTRAINT session_attendance_status_check
                    CHECK (status IN ('present', 'late', 'absent', 'excused')),
                CONSTRAINT session_attendance_mode_check
                    CHECK (mode IS NULL OR mode IN ('physical', 'virtual')),
                CONSTRAINT session_attendance_duration_check
                    CHECK (duration_minutes IS NULL OR duration_minutes >= 0),
                CONSTRAINT session_attendance_times_check
                    CHECK (left_at IS NULL OR joined_at IS NULL OR left_at >= joined_at),

                -- An override must say when it happened and why. The legacy
                -- columns were all optional, so an override could appear from
                -- nobody for no reason.
                --
                -- `override_by` is deliberately not required: it is
                -- ON DELETE SET NULL, so requiring it would make the person
                -- who made the mark undeletable. The timestamp and the reason
                -- carry the audit; the name is a bonus that may leave.
                CONSTRAINT session_attendance_override_consistent CHECK (
                    NOT is_manual_override
                    OR (override_at IS NOT NULL AND override_reason IS NOT NULL)
                ),

                CONSTRAINT session_attendance_session_fk
                    FOREIGN KEY (session_id, tenant_id)
                    REFERENCES training_sessions (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT session_attendance_enrollment_fk
                    FOREIGN KEY (enrollment_id, user_id)
                    REFERENCES training_enrollments (id, user_id) ON DELETE CASCADE
            )
        ");

        // One record per learner per session. Marking twice replaces rather
        // than stacking, so a QR scan after a manual mark is not two truths.
        $this->addSql('
            CREATE UNIQUE INDEX session_attendance_uniq
                ON session_attendance (session_id, user_id)
        ');
        $this->addSql('CREATE INDEX session_attendance_enrollment_idx ON session_attendance (enrollment_id)');
        $this->addSql('
            CREATE UNIQUE INDEX session_attendance_legacy_uniq
                ON session_attendance (legacy_id) WHERE legacy_id IS NOT NULL
        ');
    }

    /**
     * Assessments may now hang off a training, as the design always intended.
     *
     * The assessment core deliberately left this column out — a foreign key
     * to a table that did not exist is how the legacy schema accumulated
     * relationships enforced only by convention. The table exists now.
     */
    private function assessmentLink(): void
    {
        $this->addSql('ALTER TABLE assessments ADD COLUMN training_id UUID');
        $this->addSql('
            ALTER TABLE assessments ADD CONSTRAINT assessments_training_fk
                FOREIGN KEY (training_id, tenant_id)
                REFERENCES live_trainings (id, tenant_id) ON DELETE CASCADE
        ');
        $this->addSql('
            CREATE INDEX assessments_training_idx
                ON assessments (training_id) WHERE deleted_at IS NULL AND training_id IS NOT NULL
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assessments DROP CONSTRAINT IF EXISTS assessments_training_fk');
        $this->addSql('DROP INDEX IF EXISTS assessments_training_idx');
        $this->addSql('ALTER TABLE assessments DROP COLUMN IF EXISTS training_id');

        $this->addSql('DROP TABLE IF EXISTS session_attendance');
        $this->addSql('DROP TABLE IF EXISTS training_enrollments');
        $this->addSql('DROP TABLE IF EXISTS training_sessions');
        $this->addSql('DROP TABLE IF EXISTS live_trainings');
    }
}
