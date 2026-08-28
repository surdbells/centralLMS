<?php

declare(strict_types=1);

namespace Central\Api\Domain\Messaging;

/**
 * Where a broadcast is in its life.
 */
enum BroadcastStatus: string
{
    case Draft     = 'draft';
    case Scheduled = 'scheduled';
    case Sent      = 'sent';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Sent      => 'Sent',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Whether the words can still be changed.
     *
     * A sent announcement cannot be unsaid: people have read it, and editing
     * it afterwards would leave them holding a version nobody else has.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Scheduled;
    }

    /** Whether it can still be sent. */
    public function canSend(): bool
    {
        return $this === self::Draft || $this === self::Scheduled;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
