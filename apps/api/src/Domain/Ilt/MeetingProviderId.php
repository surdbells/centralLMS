<?php

declare(strict_types=1);

namespace Central\Api\Domain\Ilt;

/**
 * The conferencing platforms an ILT session can be hosted on.
 *
 * The values match the legacy `video_platform` /
 * `tenant_video_integrations.platform` enum exactly. Keeping them identical
 * means the data migration is a straight copy with no translation table, and
 * a value read from an un-migrated row is still understood.
 */
enum MeetingProviderId: string
{
    case Zoom       = 'zoom';
    case GoogleMeet = 'google_meet';
    case Teams      = 'microsoft_teams';

    public function label(): string
    {
        return match ($this) {
            self::Zoom       => 'Zoom',
            self::GoogleMeet => 'Google Meet',
            self::Teams      => 'Microsoft Teams',
        };
    }

    /**
     * Whether attendance can be pulled back from the platform after a session.
     *
     * All three can, but by different means, and Meet's requires a Workspace
     * edition that reports participants — so a tenant on a lower tier falls
     * back to QR-code attendance.
     */
    public function supportsAttendanceReport(): bool
    {
        return true;
    }

    public function supportsCloudRecording(): bool
    {
        return match ($this) {
            self::Zoom, self::Teams => true,
            self::GoogleMeet        => false,
        };
    }

    public function supportsWaitingRoom(): bool
    {
        return $this === self::Zoom;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }
}
