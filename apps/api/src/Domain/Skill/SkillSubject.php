<?php

declare(strict_types=1);

namespace Central\Api\Domain\Skill;

/**
 * A thing that can teach a skill.
 *
 * The kinds are the same three that people enrol on, and deliberately: a skill
 * is held because somebody finished something that teaches it, so the list of
 * things that teach and the list of things that can be finished have to be the
 * same list.
 */
enum SkillSubject: string
{
    case Course   = 'course';
    case Training = 'training';
    case Path     = 'path';

    public function label(): string
    {
        return match ($this) {
            self::Course   => 'Course',
            self::Training => 'Live training',
            self::Path     => 'Learning path',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
