<?php

declare(strict_types=1);

namespace Central\Api\Domain\Ilt;

/**
 * Where a training programme has got to.
 */
enum TrainingStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Ongoing = 'ongoing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public static function fromLegacy(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::Draft;
    }

    /** Whether learners can see and enrol on it. */
    public function isOpenToLearners(): bool
    {
        return $this === self::Published || $this === self::Ongoing;
    }

    /**
     * Whether its shape can still change.
     *
     * A programme in progress may still gain a session — people reschedule —
     * but a completed or cancelled one is a record, not a plan.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Published || $this === self::Ongoing;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
