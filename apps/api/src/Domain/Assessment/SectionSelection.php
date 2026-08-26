<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

/**
 * Whether a section asks all its questions or a sample.
 *
 * Separate from {@see QuestionSelectionMode}, which governs the assessment as
 * a whole. A section cannot draw from a bank by rule; it samples the questions
 * already filed under it.
 */
enum SectionSelection: string
{
    case All = 'all';
    case Random = 'random';

    public function isSample(): bool
    {
        return $this === self::Random;
    }

    public function label(): string
    {
        return match ($this) {
            self::All    => 'All questions',
            self::Random => 'A random selection',
        };
    }
}
