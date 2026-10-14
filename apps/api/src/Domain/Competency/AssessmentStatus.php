<?php

declare(strict_types=1);

namespace Central\Api\Domain\Competency;

/**
 * Where an assessment has got to.
 *
 * The order is the process, and each stage unlocks only after the one before:
 * the person self-assesses, a subject-matter expert reviews, and the line
 * manager confirms — which is the final stage, so `finalized` means the line
 * manager has signed off. A gap is only trustworthy once it has been confirmed,
 * so the outcome report counts finalised assessments and treats the rest as in
 * progress.
 */
enum AssessmentStatus: string
{
    case Draft        = 'draft';
    case SelfAssessed = 'self_assessed';
    /** The SME has reviewed; the line manager is next. */
    case Reviewed     = 'reviewed';
    /** The line manager has confirmed — the assessment is complete. */
    case Finalized    = 'finalized';

    public function label(): string
    {
        return match ($this) {
            self::Draft        => 'Not started',
            self::SelfAssessed => 'Self-assessed',
            self::Reviewed     => 'SME reviewed',
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
