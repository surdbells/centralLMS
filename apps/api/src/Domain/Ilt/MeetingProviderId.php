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
    // The in-app option: the class runs on the LMS itself over Agora's RTC,
    // rather than on an external platform behind a join link.
    case Agora      = 'agora';

    public function label(): string
    {
        return match ($this) {
            self::Zoom       => 'Zoom',
            self::GoogleMeet => 'Google Meet',
            self::Teams      => 'Microsoft Teams',
            self::Agora      => 'In-app video',
        };
    }

    /**
     * Whether the class happens inside the LMS (Agora) rather than on an
     * external platform reached by a join URL. Drives whether the client opens
     * the in-app room or a new tab.
     */
    public function isInApp(): bool
    {
        return $this === self::Agora;
    }

    /**
     * Whether attendance can be pulled back from the platform after a session.
     *
     * The three external platforms can, by different means (Meet's needs a
     * Workspace edition that reports participants, so a lower tier falls back to
     * QR attendance). Agora doesn't report after the fact — attendance is
     * recorded live as learners join the in-app room instead.
     */
    public function supportsAttendanceReport(): bool
    {
        return $this !== self::Agora;
    }

    public function supportsCloudRecording(): bool
    {
        return match ($this) {
            self::Zoom, self::Teams => true,
            self::GoogleMeet, self::Agora => false,
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
