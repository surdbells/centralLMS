<?php

declare(strict_types=1);

namespace Central\Api\Domain\Skill;

/**
 * How well somebody holds a skill.
 *
 * Four points, and deliberately not {@see \Central\Api\Domain\Shared\Difficulty},
 * which has three. They are different measurements that happen to share two
 * words: difficulty describes the material, proficiency describes the person.
 * A beginner course can leave somebody expert in the one narrow thing it
 * covers, and an advanced course can leave them a beginner at it. Folding them
 * together would mean a course's difficulty deciding a learner's competence.
 */
enum Proficiency: string
{
    case Beginner     = 'beginner';
    case Intermediate = 'intermediate';
    case Advanced     = 'advanced';
    case Expert       = 'expert';

    /**
     * Where this sits on the ladder. Higher is better.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Beginner     => 1,
            self::Intermediate => 2,
            self::Advanced     => 3,
            self::Expert       => 4,
        };
    }

    /**
     * The better of two.
     *
     * Somebody who has finished a beginner course and an expert one in the
     * same skill is an expert at it. The legacy worked this out by
     * `GROUP_CONCAT`ing the levels and walking a hardcoded list of four
     * strings in PHP.
     */
    public function bestOf(self $other): self
    {
        return $other->rank() > $this->rank() ? $other : $this;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }

    public static function fromLegacy(?string $value): ?self
    {
        return self::tryFrom(strtolower(trim((string) $value)));
    }

    /**
     * The highest of a set, or null if there are none.
     *
     * @param list<self> $proficiencies
     */
    public static function highest(array $proficiencies): ?self
    {
        $highest = null;

        foreach ($proficiencies as $proficiency) {
            $highest = $highest === null ? $proficiency : $highest->bestOf($proficiency);
        }

        return $highest;
    }
}
