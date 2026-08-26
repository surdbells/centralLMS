<?php

declare(strict_types=1);

namespace Central\Api\Domain\Ilt;

/**
 * How a training or one of its sessions is delivered.
 */
enum TrainingDelivery: string
{
    case Physical = 'physical';
    case Virtual = 'virtual';

    /** In a room and online at once. */
    case Hybrid = 'hybrid';

    public static function fromLegacy(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::Virtual;
    }

    /**
     * Whether a meeting has to be created for it.
     *
     * The question the legacy code never asked: session 2 in the live data is
     * `virtual` and has no meeting, because nothing ever checked.
     */
    public function needsMeeting(): bool
    {
        return $this !== self::Physical;
    }

    /** Whether people can be in a room, and so whether a QR code is useful. */
    public function needsVenue(): bool
    {
        return $this !== self::Virtual;
    }

    /**
     * How someone may be marked present.
     *
     * @return list<AttendanceMode>
     */
    public function allowedModes(): array
    {
        return match ($this) {
            self::Physical => [AttendanceMode::Physical],
            self::Virtual  => [AttendanceMode::Virtual],
            self::Hybrid   => [AttendanceMode::Physical, AttendanceMode::Virtual],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Physical => 'In person',
            self::Virtual  => 'Online',
            self::Hybrid   => 'In person and online',
        };
    }
}
