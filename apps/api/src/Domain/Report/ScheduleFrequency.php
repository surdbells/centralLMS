<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

/**
 * How often a saved report runs itself.
 */
enum ScheduleFrequency: string
{
    case Daily   = 'daily';
    case Weekly  = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $f): string => $f->value, self::cases());
    }
}
