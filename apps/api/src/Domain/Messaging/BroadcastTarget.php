<?php

declare(strict_types=1);

namespace Central\Api\Domain\Messaging;

/**
 * Who an announcement is for, as a rule.
 *
 * The rule becomes a list of people when the broadcast is sent — see
 * {@see Broadcast} for why that moment and not later.
 */
enum BroadcastTarget: string
{
    case All        = 'all';
    case Role       = 'role';
    case Department = 'department';
    case Branch     = 'branch';
    case Team       = 'team';
    case Course     = 'course';
    /** A list of people chosen by hand. */
    case Custom     = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::All        => 'Everybody',
            self::Role       => 'By role',
            self::Department => 'By department',
            self::Branch     => 'By branch',
            self::Team       => 'By team',
            self::Course     => 'People on a course',
            self::Custom     => 'Chosen people',
        };
    }

    /** What the criteria must name, for the message when they are missing. */
    public function noun(): string
    {
        return match ($this) {
            self::All        => 'audience',
            self::Role       => 'role',
            self::Department => 'department',
            self::Branch     => 'branch',
            self::Team       => 'team',
            self::Course     => 'course',
            self::Custom     => 'people',
        };
    }

    /**
     * Whether this needs anything beyond the kind itself.
     *
     * "Everybody" does not. The rest resolve to nobody without it, and a
     * broadcast that reached nobody still looks sent.
     */
    public function needsCriteria(): bool
    {
        return $this !== self::All;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
