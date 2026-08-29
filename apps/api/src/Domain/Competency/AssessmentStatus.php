<?php

declare(strict_types=1);

namespace Central\Api\Domain\Competency;

/**
 * Where an assessment has got to.
 *
 * The order is the process: the person self-assesses, a subject-matter expert
 * reviews, and it is finalised. A gap is only trustworthy once the expert has
 * confirmed the level, so the outcome report counts finalised assessments and
 * treats the rest as in progress.
 */
enum AssessmentStatus: string
{
    case Draft        = 'draft';
    case SelfAssessed = 'self_assessed';
    case Reviewed     = 'reviewed';
    case Finalized    = 'finalized';

    public function label(): string
    {
        return match ($this) {
            self::Draft        => 'Not started',
            self::SelfAssessed => 'Self-assessed',
            self::Reviewed     => 'Reviewed',
            self::Finalized    => 'Finalised',
        };
    }

    /** Whether its gaps are confirmed enough to report on. */
    public function isConfirmed(): bool
    {
        return $this === self::Finalized;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
