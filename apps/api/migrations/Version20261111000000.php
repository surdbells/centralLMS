<?php

declare(strict_types=1);

namespace Central\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A session is a stretch of a person actually using the app.
 *
 * The platform had no session of its own. `refresh_tokens` records one row per
 * sign-in (good enough for "where am I signed in" and login frequency), but a
 * sign-in is not a session: somebody signs in once on Monday and uses the app
 * every day that week on one token. Learning analytics ask how long people
 * spend and on what device, and neither question has an answer without a thing
 * that starts when they arrive and ends when they go quiet.
 *
 * The client pings `/me/session/ping` while the app is open. A ping within the
 * idle window extends the current session; a ping after it starts a new one.
 * Duration is `last_seen_at − started_at`; the device is parsed from the
 * user-agent once, at the session's start.
 */
final class Version20261111000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User sessions — presence/duration and device, for engagement analytics';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE user_sessions (
                id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id    UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                user_id      UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                started_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                last_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                -- Parsed from the user-agent once, at the start: desktop /
                -- mobile / tablet. The raw string is kept for anything the
                -- parser does not yet split out.
                device_type  TEXT NOT NULL DEFAULT 'desktop',
                user_agent   TEXT,
                ip_address   INET,

                CONSTRAINT user_sessions_device_known
                    CHECK (device_type IN ('desktop', 'mobile', 'tablet'))
            )
        SQL);

        // The most recent session for a user, to extend or close — the ping's
        // hot path.
        $this->addSql(
            'CREATE INDEX user_sessions_user_recent_idx
             ON user_sessions (user_id, last_seen_at DESC)',
        );

        // Engagement reports read every session by tenant and time, bucketed
        // by day/week/month and split by device.
        $this->addSql(
            'CREATE INDEX user_sessions_tenant_time_idx
             ON user_sessions (tenant_id, started_at)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS user_sessions');
    }
}
