<?php

declare(strict_types=1);

namespace Central\Api\Domain\Competency;

/**
 * How well somebody knows a competency, on the exercise's three-point scale.
 *
 * Nought is a real value — "not demonstrated" — distinct from Knowledge, which
 * is the lowest a person can actually hold. A requirement is never nought: a
 * competency a role does not need is left off the profile, not required at
 * level zero.
 */
enum ProficiencyLevel: int
{
    case None      = 0;
    case Knowledge = 1;
    case Skill     = 2;
    case Mastery   = 3;

    public function label(): string
    {
        return match ($this) {
            self::None      => 'Not demonstrated',
            self::Knowledge => 'Knowledge',
            self::Skill     => 'Skill',
            self::Mastery   => 'Mastery',
        };
    }

    /** The single letter the spreadsheets use — K, S, M. */
    public function letter(): string
    {
        return match ($this) {
            self::None      => '-',
            self::Knowledge => 'K',
            self::Skill     => 'S',
            self::Mastery   => 'M',
        };
    }

    /**
     * Reads a level from whatever a source wrote — a number, a letter, a word.
     *
     * The spreadsheets are inconsistent: some cells say "M", some "Mastery",
     * some the number 3. One parser rather than three guesses at the call
     * sites.
     */
    public static function parse(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
            return self::tryFrom((int) $value);
        }

        $text = strtolower(trim((string) $value));

        return match ($text) {
            'm', 'mastery'   => self::Mastery,
            's', 'skill'     => self::Skill,
            'k', 'knowledge' => self::Knowledge,
            '', '-', 'none', 'n/a' => null,
            default          => null,
        };
    }

    /**
     * @return list<int>
     */
    public static function values(): array
    {
        return array_map(static fn (self $l): int => $l->value, self::cases());
    }
}
