<?php

declare(strict_types=1);

namespace Central\Api\Domain\LearningPath;

/**
 * The order a path must be taken in.
 *
 * These three are the whole unlock engine. The legacy had them too, and then
 * six more rules per item — `immediate`, `previous_item`, `specific_items`,
 * `date`, `score`, `manual` — with a JSON list of item ids, an unlock date and
 * a minimum score beside them. Every item row in the database says
 * `previous_item` with all four of the others null, so the engine has one
 * setting and it is the one the path already decides.
 */
enum PathPacing: string
{
    /** One after another, in order. */
    case Sequential = 'sequential';

    /** Any order, all of it. */
    case Flexible = 'flexible';

    /** Sections gate each other; within a section, any order. */
    case Milestone = 'milestone';

    public function label(): string
    {
        return match ($this) {
            self::Sequential => 'One at a time, in order',
            self::Flexible   => 'Any order',
            self::Milestone  => 'Section by section',
        };
    }

    /**
     * Whether anything is ever locked under this pacing.
     */
    public function gates(): bool
    {
        return $this !== self::Flexible;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }
}
