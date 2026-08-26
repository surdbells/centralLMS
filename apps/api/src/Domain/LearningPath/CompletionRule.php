<?php

declare(strict_types=1);

namespace Central\Api\Domain\LearningPath;

/**
 * What counts as finishing a path.
 *
 * Two, where the legacy declared four. `points` and `assessment` are not
 * carried: nothing summed the points to compare them with a target, and
 * "finish when you pass the assessment" is the same thing as making that
 * assessment the only required item, said once instead of twice.
 */
enum CompletionRule: string
{
    /** Everything marked required. */
    case AllRequiredItems = 'all_required_items';

    /** Enough of it, by the threshold on the path. */
    case Percentage = 'percentage';

    public function label(): string
    {
        return match ($this) {
            self::AllRequiredItems => 'Every required item',
            self::Percentage       => 'A percentage of the path',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}
