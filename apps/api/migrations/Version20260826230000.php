<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The proctoring extension.
 *
 * An advanced module on the same assessment core, not a parallel product:
 * `assessments.proctoring_level` already exists and defaults to `none`. A row
 * in any table here exists only for an assessment whose level is above that,
 * and core code never joins to them — which is what keeps the module optional
 * rather than quietly mandatory.
 *
 * As with the rest of the port, the legacy tables were never used: all five
 * `proc_proctoring_*` tables are empty. They are the design, not the data.
 *
 * **Settings are one `jsonb` column, not thirty-five.** The legacy table had
 * `prevent_tab_switch`, `prevent_copy_paste`, `prevent_right_click`,
 * `require_fullscreen` and thirty more like them. They are configuration: read
 * as one blob when a sitting starts, never filtered on, never aggregated. The
 * legacy schema already conceded this — `proc_proctoring_presets.settings` is
 * JSON holding the same fields — so one shape now serves both, and
 * `ProctoringSettings` validates it where the meaning lives.
 *
 * **The counters are dropped.** `proc_proctoring_sessions` carried
 * `total_violations`, `violation_score`, `trust_score`, `tab_switch_count`,
 * `window_blur_count`, `fullscreen_exit_count`, `no_face_detected_count`,
 * `multiple_faces_detected_count` and `total_snapshots` — every one an
 * aggregate over rows this schema already holds, and every one a fresh chance
 * to disagree with them. The enrolments port removed exactly this pattern
 * after finding 16 of 159 rows where the cached flag contradicted the truth.
 * They are computed.
 *
 * **`emotion_detected` is not ported.** Inferring a learner's emotional state
 * from a webcam frame is biometric profiling of a person under examination.
 * It is not needed to detect cheating, none of the violation types depend on
 * it, and building it because a column existed would be the wrong default. If
 * it is genuinely wanted it should be asked for, with the consent and
 * retention questions answered first.
 *
 * `proc_attempts` carried four proctoring columns duplicating the session row.
 * They were left out of the core migration deliberately and stay out here.
 */
final class Version20260826230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The proctoring extension: entitlement, settings, presets, sessions, violations and snapshots.';
    }

    public function up(Schema $schema): void
    {
        $this->entitlements();
        $this->presets();
        $this->settings();
        $this->sessions();
        $this->violations();
        $this->snapshots();
    }

    /**
     * Which paid modules a tenant may use.
     *
     * A column of its own rather than a key inside `settings`, because it is
     * the difference between a preference and a licence: the API refuses to
     * raise `proctoring_level` above `none` without it.
     */
    private function entitlements(): void
    {
        $this->addSql("ALTER TABLE tenants ADD COLUMN entitlements JSONB NOT NULL DEFAULT '{}'::jsonb");
        $this->addSql("
            ALTER TABLE tenants ADD CONSTRAINT tenants_entitlements_object
                CHECK (jsonb_typeof(entitlements) = 'object')
        ");
    }

    /**
     * Named bundles of settings — "Standard exam", "Open book".
     *
     * `tenant_id IS NULL` marks a preset the platform ships. The legacy table
     * used `tenant_id = 0` for that, which is a foreign key that cannot
     * resolve; NULL says the same thing and the database can enforce it.
     */
    private function presets(): void
    {
        $this->addSql("
            CREATE TABLE proctoring_presets (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id   BIGINT,
                tenant_id   UUID REFERENCES tenants (id) ON DELETE CASCADE,
                name        TEXT NOT NULL,
                description TEXT,
                level       TEXT NOT NULL,
                settings    JSONB NOT NULL DEFAULT '{}'::jsonb,
                is_default  BOOLEAN NOT NULL DEFAULT FALSE,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMPTZ,

                CONSTRAINT proctoring_presets_name_not_blank CHECK (length(btrim(name)) > 0),
                CONSTRAINT proctoring_presets_level_check
                    CHECK (level IN ('none', 'basic', 'standard', 'advanced')),
                CONSTRAINT proctoring_presets_settings_object
                    CHECK (jsonb_typeof(settings) = 'object')
            )
        ");

        // One default per tenant, and one platform default.
        $this->addSql('
            CREATE UNIQUE INDEX proctoring_presets_one_default
                ON proctoring_presets (COALESCE(tenant_id, \'00000000-0000-0000-0000-000000000000\'::uuid))
                WHERE is_default
        ');
        $this->addSql('
            CREATE UNIQUE INDEX proctoring_presets_legacy_uniq
                ON proctoring_presets (legacy_id) WHERE legacy_id IS NOT NULL
        ');
    }

    /**
     * One row per proctored assessment.
     */
    private function settings(): void
    {
        $this->addSql("
            CREATE TABLE proctoring_settings (
                id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id     UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                assessment_id UUID NOT NULL,

                -- Mirrors assessments.proctoring_level. Kept here too so this
                -- row is self-describing to the extension, which never joins
                -- back to the core.
                level    TEXT NOT NULL DEFAULT 'none',
                settings JSONB NOT NULL DEFAULT '{}'::jsonb,

                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ,

                CONSTRAINT proctoring_settings_level_check
                    CHECK (level IN ('none', 'basic', 'standard', 'advanced')),
                CONSTRAINT proctoring_settings_object
                    CHECK (jsonb_typeof(settings) = 'object'),
                CONSTRAINT proctoring_settings_assessment_fk
                    FOREIGN KEY (assessment_id, tenant_id)
                    REFERENCES assessments (id, tenant_id) ON DELETE CASCADE
            )
        ");

        $this->addSql('
            CREATE UNIQUE INDEX proctoring_settings_assessment_uniq
                ON proctoring_settings (assessment_id)
        ');
    }

    /**
     * One row per proctored sitting.
     */
    private function sessions(): void
    {
        $this->addSql("
            CREATE TABLE proctoring_sessions (
                id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id  BIGINT,
                tenant_id  UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                attempt_id UUID NOT NULL,
                user_id    UUID NOT NULL,

                status TEXT NOT NULL DEFAULT 'pending_check',

                -- The pre-flight check: browser, permissions, devices.
                system_check_passed BOOLEAN,
                system_check_at     TIMESTAMPTZ,
                system_check_data   JSONB,

                -- Identity verification, where the level requires it.
                id_verified        BOOLEAN,
                id_verification_at TIMESTAMPTZ,
                id_photo_url       TEXT,
                face_photo_url     TEXT,
                face_match_score   NUMERIC(5,2),

                room_scan_completed  BOOLEAN,
                room_scan_at         TIMESTAMPTZ,
                room_scan_video_url  TEXT,

                screen_recording_url TEXT,
                webcam_recording_url TEXT,

                started_at TIMESTAMPTZ,
                ended_at   TIMESTAMPTZ,
                total_active_seconds INTEGER NOT NULL DEFAULT 0,

                -- Set by a reviewer, not by the system: the trust score is
                -- computed, but the decision about it is a person's.
                reviewed_by UUID REFERENCES users (id) ON DELETE SET NULL,
                reviewed_at TIMESTAMPTZ,
                review_notes TEXT,

                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ,

                CONSTRAINT proctoring_sessions_status_check CHECK (status IN (
                    'pending_check', 'system_check', 'active', 'paused', 'completed', 'terminated'
                )),
                CONSTRAINT proctoring_sessions_score_check
                    CHECK (face_match_score IS NULL OR face_match_score BETWEEN 0 AND 100),
                CONSTRAINT proctoring_sessions_time_check CHECK (total_active_seconds >= 0),
                CONSTRAINT proctoring_sessions_check_consistent
                    CHECK (system_check_passed IS NULL OR system_check_at IS NOT NULL),

                CONSTRAINT proctoring_sessions_attempt_fk
                    FOREIGN KEY (attempt_id, tenant_id)
                    REFERENCES attempts (id, tenant_id) ON DELETE CASCADE,
                CONSTRAINT proctoring_sessions_user_fk
                    FOREIGN KEY (user_id, tenant_id)
                    REFERENCES users (id, tenant_id) ON DELETE CASCADE,

                CONSTRAINT proctoring_sessions_id_tenant_uniq UNIQUE (id, tenant_id)
            )
        ");

        // One session per attempt: a second would split the evidence in two.
        $this->addSql('CREATE UNIQUE INDEX proctoring_sessions_attempt_uniq ON proctoring_sessions (attempt_id)');
        $this->addSql('
            CREATE UNIQUE INDEX proctoring_sessions_legacy_uniq
                ON proctoring_sessions (legacy_id) WHERE legacy_id IS NOT NULL
        ');
        $this->addSql('CREATE INDEX proctoring_sessions_user_idx ON proctoring_sessions (tenant_id, user_id)');
    }

    private function violations(): void
    {
        $this->addSql("
            CREATE TABLE proctoring_violations (
                id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id  BIGINT,
                tenant_id  UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                session_id UUID NOT NULL,

                type     TEXT NOT NULL,
                severity TEXT NOT NULL DEFAULT 'medium',

                -- What this event costs the trust score. Stored because the
                -- weighting may be retuned later, and a session reviewed under
                -- the old weights should keep the numbers it was judged on.
                score NUMERIC(5,2) NOT NULL DEFAULT 1,

                description TEXT,
                question_id UUID,
                snapshot_url TEXT,
                video_clip_url TEXT,
                audio_clip_url TEXT,
                event_data JSONB,

                action_taken TEXT NOT NULL DEFAULT 'none',
                user_acknowledged BOOLEAN NOT NULL DEFAULT FALSE,
                acknowledged_at TIMESTAMPTZ,

                review_status TEXT NOT NULL DEFAULT 'pending',
                review_notes  TEXT,
                reviewed_by   UUID REFERENCES users (id) ON DELETE SET NULL,
                reviewed_at   TIMESTAMPTZ,

                occurred_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT proctoring_violations_severity_check
                    CHECK (severity IN ('low', 'medium', 'high', 'critical')),
                CONSTRAINT proctoring_violations_score_check CHECK (score >= 0),
                CONSTRAINT proctoring_violations_action_check CHECK (action_taken IN (
                    'none', 'warning_shown', 'paused', 'auto_submitted', 'terminated'
                )),
                CONSTRAINT proctoring_violations_review_check CHECK (review_status IN (
                    'pending', 'valid', 'false_positive', 'inconclusive'
                )),
                CONSTRAINT proctoring_violations_reviewed_consistent
                    CHECK (review_status = 'pending' OR reviewed_at IS NOT NULL),
                CONSTRAINT proctoring_violations_event_object
                    CHECK (event_data IS NULL OR jsonb_typeof(event_data) = 'object'),

                CONSTRAINT proctoring_violations_session_fk
                    FOREIGN KEY (session_id, tenant_id)
                    REFERENCES proctoring_sessions (id, tenant_id) ON DELETE CASCADE
            )
        ");

        $this->addSql('
            CREATE INDEX proctoring_violations_session_idx
                ON proctoring_violations (session_id, occurred_at)
        ');
        $this->addSql("
            CREATE INDEX proctoring_violations_review_idx
                ON proctoring_violations (tenant_id, review_status) WHERE review_status = 'pending'
        ");
        $this->addSql('
            CREATE UNIQUE INDEX proctoring_violations_legacy_uniq
                ON proctoring_violations (legacy_id) WHERE legacy_id IS NOT NULL
        ');
    }

    private function snapshots(): void
    {
        $this->addSql("
            CREATE TABLE proctoring_snapshots (
                id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                legacy_id  BIGINT,
                tenant_id  UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                session_id UUID NOT NULL,

                kind          TEXT NOT NULL DEFAULT 'periodic',
                image_url     TEXT NOT NULL,
                thumbnail_url TEXT,
                file_size_bytes INTEGER,

                question_id          UUID,
                time_elapsed_seconds INTEGER NOT NULL DEFAULT 0,

                -- What the client's detector reported. Nullable throughout:
                -- absence means \"not analysed\", which is not the same as
                -- \"nobody there\".
                face_detected   BOOLEAN,
                face_count      INTEGER,
                face_confidence NUMERIC(5,2),
                face_position   JSONB,
                gaze_direction  JSONB,
                objects_detected JSONB,

                flagged     BOOLEAN NOT NULL DEFAULT FALSE,
                flag_reason TEXT,

                captured_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT proctoring_snapshots_kind_check CHECK (kind IN (
                    'periodic', 'violation', 'question_start', 'question_submit', 'manual'
                )),
                CONSTRAINT proctoring_snapshots_image_not_blank CHECK (length(btrim(image_url)) > 0),
                CONSTRAINT proctoring_snapshots_confidence_check
                    CHECK (face_confidence IS NULL OR face_confidence BETWEEN 0 AND 100),
                CONSTRAINT proctoring_snapshots_face_count_check
                    CHECK (face_count IS NULL OR face_count >= 0),
                CONSTRAINT proctoring_snapshots_time_check CHECK (time_elapsed_seconds >= 0),
                CONSTRAINT proctoring_snapshots_size_check
                    CHECK (file_size_bytes IS NULL OR file_size_bytes >= 0),

                CONSTRAINT proctoring_snapshots_session_fk
                    FOREIGN KEY (session_id, tenant_id)
                    REFERENCES proctoring_sessions (id, tenant_id) ON DELETE CASCADE
            )
        ");

        $this->addSql('
            CREATE INDEX proctoring_snapshots_session_idx
                ON proctoring_snapshots (session_id, captured_at)
        ');
        $this->addSql('
            CREATE INDEX proctoring_snapshots_flagged_idx
                ON proctoring_snapshots (session_id) WHERE flagged
        ');
        $this->addSql('
            CREATE UNIQUE INDEX proctoring_snapshots_legacy_uniq
                ON proctoring_snapshots (legacy_id) WHERE legacy_id IS NOT NULL
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS proctoring_snapshots');
        $this->addSql('DROP TABLE IF EXISTS proctoring_violations');
        $this->addSql('DROP TABLE IF EXISTS proctoring_sessions');
        $this->addSql('DROP TABLE IF EXISTS proctoring_settings');
        $this->addSql('DROP TABLE IF EXISTS proctoring_presets');
        $this->addSql('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_entitlements_object');
        $this->addSql('ALTER TABLE tenants DROP COLUMN IF EXISTS entitlements');
    }
}
