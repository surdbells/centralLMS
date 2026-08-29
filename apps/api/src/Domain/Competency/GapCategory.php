<?php

declare(strict_types=1);

namespace Central\Api\Domain\Competency;

/**
 * How far a person falls short of what their job requires, in one competency.
 *
 * The three the outcome report is built on — a gap of one level is Minor, two
 * Major, three Significant — plus Met, which is not a gap and is counted apart
 * so a compliance rate is not diluted by the competencies people already have.
 */
enum GapCategory: string
{
    case Met         = 'met';
    case Minor       = 'minor';
    case Major       = 'major';
    case Significant = 'significant';

    /**
     * The category for a gap of this size.
     *
     * The gap is required minus demonstrated, never below nought — exceeding a
     * requirement is Met, not a negative gap.
     */
    public static function fromGap(int $gap): self
    {
        return match (max(0, $gap)) {
            0       => self::Met,
            1       => self::Minor,
            2       => self::Major,
            default => self::Significant,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Met         => 'Met',
            self::Minor       => 'Minor gap',
            self::Major       => 'Major gap',
            self::Significant => 'Significant gap',
        };
    }

    /**
     * A rough estimate of how long the gap takes to close.
     *
     * Nought for Met — nothing to close. The estimates are what the exercise
     * uses to plan development, not a promise; the report labels them as
     * estimates.
     */
    public function estimatedMonths(): int
    {
        return match ($this) {
            self::Met         => 0,
            self::Minor       => 3,
            self::Major       => 6,
            self::Significant => 12,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
