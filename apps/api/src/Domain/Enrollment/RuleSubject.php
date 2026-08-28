<?php

declare(strict_types=1);

namespace Central\Api\Domain\Enrollment;

/**
 * What a standing enrolment rule puts people onto.
 *
 * The rule itself is the same whatever the answer — the same targets, the same
 * statuses, the same sweep. Only the table the enrolment lands in differs, and
 * that is what {@see SubjectEnroller} is for.
 */
enum RuleSubject: string
{
    case Path     = 'path';
    case Training = 'training';

    public function label(): string
    {
        return match ($this) {
            self::Path     => 'Learning path',
            self::Training => 'Live training',
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
