<?php

declare(strict_types=1);

namespace Central\Api\Domain\Messaging;

/**
 * How loudly an announcement asks to be read.
 *
 * Three, not five: a scale people cannot tell apart becomes one everybody
 * sets to the top.
 */
enum BroadcastPriority: string
{
    case Normal    = 'normal';
    case Important = 'important';
    case Urgent    = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Normal    => 'Normal',
            self::Important => 'Important',
            self::Urgent    => 'Urgent',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }
}
