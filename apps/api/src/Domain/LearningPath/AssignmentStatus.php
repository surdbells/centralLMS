<?php

declare(strict_types=1);

namespace Central\Api\Domain\LearningPath;

/**
 * Whether a standing assignment rule is still doing anything.
 *
 * None of these touch the enrolments the rule already made. Pausing a rule
 * stops it enrolling anybody new; it does not take a course away from somebody
 * halfway through it, and neither does cancelling.
 */
enum AssignmentStatus: string
{
    case Active    = 'active';
    case Paused    = 'paused';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active    => 'Active',
            self::Paused    => 'Paused',
            self::Completed => 'Finished',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Whether the sweep should still be enrolling people from this rule. */
    public function isRunning(): bool
    {
        return $this === self::Active;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
