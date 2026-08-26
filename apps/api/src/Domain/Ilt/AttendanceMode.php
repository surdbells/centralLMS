<?php

declare(strict_types=1);

namespace Central\Api\Domain\Ilt;

/**
 * How a learner attended: in the room, or in the meeting.
 */
enum AttendanceMode: string
{
    case Physical = 'physical';
    case Virtual = 'virtual';

    /**
     * Whether the record came from the meeting provider rather than a person.
     *
     * Virtual attendance is read back from Zoom, Meet or Teams; physical
     * attendance is a scan or an instructor's mark. Knowing which matters when
     * the two disagree.
     */
    public function isMachineReported(): bool
    {
        return $this === self::Virtual;
    }

    public function label(): string
    {
        return match ($this) {
            self::Physical => 'In the room',
            self::Virtual  => 'Online',
        };
    }
}
