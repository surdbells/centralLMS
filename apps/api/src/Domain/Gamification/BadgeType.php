<?php

declare(strict_types=1);

namespace Central\Api\Domain\Gamification;

/**
 * What kind of thing a badge marks.
 *
 * Presentation rather than behaviour: all five are awarded the same way. They
 * group the badge cabinet so twenty badges do not read as one undifferentiated
 * wall.
 */
enum BadgeType: string
{
    case Achievement   = 'achievement';
    case Milestone     = 'milestone';
    case Completion    = 'completion';
    case Streak        = 'streak';
    case Participation = 'participation';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
