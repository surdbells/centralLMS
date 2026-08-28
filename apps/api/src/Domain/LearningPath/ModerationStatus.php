<?php

declare(strict_types=1);

namespace Central\Api\Domain\LearningPath;

/**
 * Whether somebody has looked at a review yet.
 *
 * Separate from whether it is published: a review can be approved and later
 * hidden without becoming "pending" again, and a rejected one must not return
 * to the queue every time its author edits it.
 */
enum ModerationStatus: string
{
    case Pending  = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending  => 'Waiting to be checked',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    /** Whether a review in this state may be shown to other learners. */
    public function canBePublished(): bool
    {
        return $this === self::Approved;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
