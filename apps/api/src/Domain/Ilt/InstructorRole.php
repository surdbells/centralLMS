<?php

declare(strict_types=1);

namespace Central\Api\Domain\Ilt;

/**
 * What somebody does on a session they are teaching.
 *
 * Only `primary` is exclusive, and deliberately: "co-instructor" and
 * "assistant" are relative to somebody, so a session with two leads and no
 * assistants describes a hierarchy nobody meant.
 */
enum InstructorRole: string
{
    case Primary       = 'primary';
    case CoInstructor  = 'co_instructor';
    case Assistant     = 'assistant';

    public function label(): string
    {
        return match ($this) {
            self::Primary      => 'Lead instructor',
            self::CoInstructor => 'Co-instructor',
            self::Assistant    => 'Assistant',
        };
    }

    /** Whether a session may have only one of these. */
    public function isExclusive(): bool
    {
        return $this === self::Primary;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}
