<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notifications: the inbox, the delivery attempts, the preferences and the
 * schedule that makes reminders actually arrive.
 *
 * The legacy has all the parts and none of the wiring:
 *
 *   * `notifications` holds 10 rows, every one of them hand-seeded demo data
 *     for a single user, every `data` column NULL. Nothing in the application
 *     writes to it, because the two endpoints that read it —
 *     `utility/shared/notifications.php` and `mark_notifications.php` — both
 *     `new Notifications($db)` while the class file defines `class
 *     Notification`, singular. They fatal on every request. Both top bars in
 *     the Angular app call them. The bell has never worked.
 *
 *   * `live_training_reminders` holds 2 rows, both `pending`, both `sent_at`
 *     NULL, scheduled for January 2026 and never fired.
 *
 *   * `live_training_notification_queue` holds 1 row, `pending`.
 *
 *   * `messaging_notification_queue` holds 19 rows, all `pending`.
 *
 * Four tables here replace six there.
 *
 * **Two queues become one.** `live_training_notification_queue` and
 * `messaging_notification_queue` are the same table written twice with
 * different enum members, and a third would have appeared the first time
 * certificates needed an email. Delivery does not care what happened; it
 * cares who, which channel, and whether it went. What happened lives on the
 * notification.
 *
 * **`live_training_reminder_log` folds into deliveries.** It was a per
 * recipient send record with a channel and a status — which is what a
 * delivery row is. Keeping it separate is what forced
 * `live_training_reminders` to carry `recipients_count`, `successful_count`
 * and `failed_count`: three columns holding what `GROUP BY status` over the
 * log already knew, and drifting the moment a retry succeeded.
 *
 * **Reminders do not store when they are due.** This is the one design point
 * worth reading twice. `live_training_reminders.scheduled_at` was a copy of
 * the session's start time minus the offset, and nothing recomputed it — so
 * moving a session left its reminders pointing at the old hour, and learners
 * would have been told "starts in 1 hour" the day after it finished. Here a
 * schedule row stores the offset only and the due time is computed from the
 * subject's current start, so rescheduling a session moves its reminders by
 * construction rather than by remembering to.
 */
final class Version20260827180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notification inbox, delivery attempts, preferences and reminder schedules';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'PostgreSQL only.',
        );

        // -- the inbox ----------------------------------------------------
        //
        // One row per person per thing that happened. `type` drives both the
        // wording and which channels apply, so it is constrained rather than
        // free text: the legacy's `varchar(50)` already held 10 distinct
        // spellings across 10 rows.
        $this->addSql(<<<'SQL'
            CREATE TABLE notifications (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                user_id     UUID NOT NULL,

                type        TEXT NOT NULL,
                title       TEXT NOT NULL,
                body        TEXT NOT NULL DEFAULT '',

                -- What it is about. Nullable because some notifications are
                -- about nothing in particular ("welcome").
                subject_type TEXT,
                subject_id   UUID,

                -- Everything the wording needs, so rendering never has to go
                -- back to the originating module. A notification about a
                -- course deleted last week must still read correctly.
                data        JSONB NOT NULL DEFAULT '{}'::jsonb,

                priority    TEXT NOT NULL DEFAULT 'normal',

                -- read_at rather than is_read: it carries when, and the
                -- boolean is derivable from it. The legacy stored both a
                -- boolean and nothing else, so "unread since when" was
                -- unanswerable.
                read_at     TIMESTAMPTZ,

                -- Soft delete rather than the legacy's `is_active`, which
                -- read as though a notification could be deactivated and
                -- reactivated. It could not; it was a delete.
                deleted_at  TIMESTAMPTZ,

                -- After this it stops being shown. A session reminder for a
                -- session that has finished is noise.
                expires_at  TIMESTAMPTZ,

                created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),

                CONSTRAINT notifications_title_present CHECK (btrim(title) <> ''),
                CONSTRAINT notifications_priority_known
                    CHECK (priority IN ('low', 'normal', 'high', 'urgent')),
                CONSTRAINT notifications_data_is_object
                    CHECK (jsonb_typeof(data) = 'object'),
                -- A subject is a pair or neither. Half of one is a dangling
                -- reference nothing can follow.
                CONSTRAINT notifications_subject_complete
                    CHECK ((subject_type IS NULL) = (subject_id IS NULL)),
                CONSTRAINT notifications_expiry_after_creation
                    CHECK (expires_at IS NULL OR expires_at > created_at),

                -- The recipient must belong to the tenant that raised it.
                CONSTRAINT notifications_user_fk
                    FOREIGN KEY (user_id, tenant_id) REFERENCES users (id, tenant_id) ON DELETE CASCADE
            )
        SQL);

        // The bell: newest first, undeleted, for one person. Every list and
        // the unread count go through this.
        $this->addSql(<<<'SQL'
            CREATE INDEX notifications_inbox_idx
                ON notifications (tenant_id, user_id, created_at DESC)
                WHERE deleted_at IS NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX notifications_unread_idx
                ON notifications (tenant_id, user_id)
                WHERE read_at IS NULL AND deleted_at IS NULL
        SQL);

        $this->addSql('CREATE INDEX notifications_subject_idx ON notifications (subject_type, subject_id)');

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX notifications_id_tenant_uniq ON notifications (id, tenant_id)
        SQL);

        // -- delivery -----------------------------------------------------
        //
        // One row per notification per channel. The in-app channel gets a row
        // too, and it is marked sent at once: without it "which channels did
        // this go out on" has an exception in it, and exceptions in that
        // answer are how a missing email goes unnoticed.
        $this->addSql(<<<'SQL'
            CREATE TABLE notification_deliveries (
                id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id       UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                notification_id UUID NOT NULL,

                channel         TEXT NOT NULL,

                -- The address as it was at send time. A person who changes
                -- their email should not rewrite where last month's message
                -- went — that is the record of what happened.
                address         TEXT,

                status          TEXT NOT NULL DEFAULT 'pending',
                attempts        SMALLINT NOT NULL DEFAULT 0,
                max_attempts    SMALLINT NOT NULL DEFAULT 3,

                scheduled_for   TIMESTAMPTZ NOT NULL DEFAULT now(),

                -- Claim marker. Two workers running at once is the normal
                -- case, not the exceptional one, and without this they both
                -- send the same email.
                claimed_at      TIMESTAMPTZ,
                claimed_by      TEXT,

                sent_at         TIMESTAMPTZ,
                failed_at       TIMESTAMPTZ,
                error           TEXT,

                -- What the provider called it, for tracing a complaint back
                -- to a message the provider can still see.
                provider_message_id TEXT,

                created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),

                CONSTRAINT deliveries_channel_known
                    CHECK (channel IN ('in_app', 'email', 'sms', 'push')),
                CONSTRAINT deliveries_status_known
                    CHECK (status IN ('pending', 'sending', 'sent', 'failed', 'skipped')),
                CONSTRAINT deliveries_attempts_sane
                    CHECK (attempts >= 0 AND max_attempts >= 1 AND attempts <= max_attempts),

                -- Sent means there is a time it was sent, and nothing else
                -- does. The legacy allowed status='sent' with processed_at
                -- NULL, which is what made "when did this go out" a guess.
                CONSTRAINT deliveries_sent_consistent
                    CHECK ((status = 'sent') = (sent_at IS NOT NULL)),
                CONSTRAINT deliveries_failed_consistent
                    CHECK (status <> 'failed' OR (failed_at IS NOT NULL AND error IS NOT NULL)),
                CONSTRAINT deliveries_claim_consistent
                    CHECK ((claimed_at IS NULL) = (claimed_by IS NULL)),

                -- Every channel but in_app needs somewhere to send to.
                CONSTRAINT deliveries_address_present
                    CHECK (channel = 'in_app' OR status = 'skipped' OR btrim(coalesce(address, '')) <> ''),

                CONSTRAINT deliveries_notification_fk
                    FOREIGN KEY (notification_id, tenant_id)
                    REFERENCES notifications (id, tenant_id) ON DELETE CASCADE
            )
        SQL);

        // One attempt record per channel — a retry updates the row rather
        // than adding another, so `attempts` and the row agree.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX deliveries_one_per_channel_uniq
                ON notification_deliveries (notification_id, channel)
        SQL);

        // The worker's only query: what is due and unclaimed.
        $this->addSql(<<<'SQL'
            CREATE INDEX deliveries_due_idx
                ON notification_deliveries (scheduled_for)
                WHERE status = 'pending' AND claimed_at IS NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX deliveries_stuck_idx
                ON notification_deliveries (claimed_at)
                WHERE status = 'sending'
        SQL);

        // -- preferences --------------------------------------------------
        //
        // Opt-outs, not opt-ins: a row means "this person does not want this
        // type on this channel". Absence is the default, so a new
        // notification type reaches everyone without a backfill, and the
        // table stays small because most people never change anything.
        $this->addSql(<<<'SQL'
            CREATE TABLE notification_preferences (
                id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id  UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                user_id    UUID NOT NULL,

                -- NULL means every type: one row to turn a whole channel off.
                type       TEXT,
                channel    TEXT NOT NULL,
                enabled    BOOLEAN NOT NULL DEFAULT TRUE,

                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),

                CONSTRAINT preferences_channel_known
                    CHECK (channel IN ('in_app', 'email', 'sms', 'push')),

                CONSTRAINT preferences_user_fk
                    FOREIGN KEY (user_id, tenant_id) REFERENCES users (id, tenant_id) ON DELETE CASCADE
            )
        SQL);

        // Two partial indexes rather than one: NULL is not equal to itself,
        // so a plain unique on (user_id, type, channel) would let a user
        // accumulate any number of channel-wide rows.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX preferences_per_type_uniq
                ON notification_preferences (tenant_id, user_id, type, channel)
                WHERE type IS NOT NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX preferences_channel_wide_uniq
                ON notification_preferences (tenant_id, user_id, channel)
                WHERE type IS NULL
        SQL);

        // Quiet hours belong to the person, not to a type/channel pair, so
        // they sit on the user rather than repeating down the preference
        // rows. The legacy put them on `user_messaging_settings`, where they
        // applied to chat only and nothing else could see them.
        $this->addSql(<<<'SQL'
            ALTER TABLE users
                ADD COLUMN quiet_hours_start TIME,
                ADD COLUMN quiet_hours_end   TIME,
                ADD CONSTRAINT users_quiet_hours_complete
                    CHECK ((quiet_hours_start IS NULL) = (quiet_hours_end IS NULL))
        SQL);

        // -- schedules ----------------------------------------------------
        //
        // A planned future notification. `offset_minutes` is how long before
        // the subject's own moment it goes out; the due time is computed from
        // the subject when the dispatcher looks, never copied here. See the
        // class docblock.
        $this->addSql(<<<'SQL'
            CREATE TABLE notification_schedules (
                id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id      UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,

                type           TEXT NOT NULL,
                subject_type   TEXT NOT NULL,
                subject_id     UUID NOT NULL,

                offset_minutes INTEGER NOT NULL,
                channels       TEXT[] NOT NULL DEFAULT '{in_app,email}',

                status         TEXT NOT NULL DEFAULT 'pending',

                -- When it was turned into notifications, and how many.
                -- Not a running counter: written once, at expansion.
                expanded_at    TIMESTAMPTZ,
                recipients     INTEGER,
                error          TEXT,

                created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),

                CONSTRAINT schedules_status_known
                    CHECK (status IN ('pending', 'expanded', 'skipped', 'failed', 'cancelled')),
                CONSTRAINT schedules_offset_sane
                    CHECK (offset_minutes >= 0 AND offset_minutes <= 43200),
                CONSTRAINT schedules_channels_present
                    CHECK (cardinality(channels) > 0),
                CONSTRAINT schedules_channels_known
                    CHECK (channels <@ ARRAY['in_app', 'email', 'sms', 'push']::text[]),
                CONSTRAINT schedules_expanded_consistent
                    CHECK ((status = 'expanded') = (expanded_at IS NOT NULL AND recipients IS NOT NULL)),
                CONSTRAINT schedules_failed_consistent
                    CHECK (status <> 'failed' OR error IS NOT NULL)
            )
        SQL);

        // Re-running the scheduler must not double-book. This is what makes
        // "make sure this session's reminders exist" safe to call on every
        // save of the session.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX schedules_one_per_offset_uniq
                ON notification_schedules (subject_type, subject_id, type, offset_minutes)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX schedules_pending_idx
                ON notification_schedules (subject_type, subject_id)
                WHERE status = 'pending'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS notification_schedules');
        $this->addSql('DROP TABLE IF EXISTS notification_preferences');
        $this->addSql('DROP TABLE IF EXISTS notification_deliveries');
        $this->addSql('DROP TABLE IF EXISTS notifications');
        $this->addSql('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_quiet_hours_complete');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS quiet_hours_start');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS quiet_hours_end');
    }
}
