<?php

declare(strict_types=1);

namespace Central\Api\Domain\Messaging;

/**
 * What sort of conversation this is.
 *
 * Three, where the legacy declared four. `announcement` is not here because
 * announcements are `notification.send`, which the notification module already
 * does with delivery tracking and per-person preferences. A second
 * announcement mechanism is the thing this port keeps removing.
 */
enum ConversationKind: string
{
    /** Two people. There is only ever one between a given pair. */
    case Direct = 'direct';

    /** A named set of people, added by hand. */
    case Group = 'group';

    /** A named room anybody may be added to; the same shape as a group,
     *  distinguished so the interface can present it differently. */
    case Channel = 'channel';

    public function label(): string
    {
        return match ($this) {
            self::Direct  => 'Direct message',
            self::Group   => 'Group',
            self::Channel => 'Channel',
        };
    }

    /**
     * Whether it carries a name of its own.
     *
     * A direct conversation does not: it is named by whoever else is in it,
     * which is why storing a name for one goes stale the moment somebody is
     * renamed.
     */
    public function isNamed(): bool
    {
        return $this !== self::Direct;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }
}
