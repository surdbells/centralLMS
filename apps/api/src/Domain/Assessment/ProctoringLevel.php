<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

/**
 * How closely an attempt is supervised.
 *
 * This enum is the seam between the assessment core and the proctoring module.
 * A course quiz and an ILT assessment are simply `None`; everything above that
 * is the paid advanced module.
 *
 * The rule the rest of the codebase depends on: when this is `None`, no row
 * exists in any `proctoring_*` table and no proctoring code runs. Core code
 * must never need to know which level is set beyond asking `isEnabled()`.
 */
enum ProctoringLevel: string
{
    /** Course quizzes and ILT assessments. No supervision, no module needed. */
    case None = 'none';

    /** Browser lockdown only: tab-switch and copy-paste prevention. */
    case Basic = 'basic';

    /** Adds webcam presence checks and periodic snapshots. */
    case Standard = 'standard';

    /** Adds identity verification, room scan, recording and AI analysis. */
    case Advanced = 'advanced';

    public function isEnabled(): bool
    {
        return $this !== self::None;
    }

    public function label(): string
    {
        return match ($this) {
            self::None     => 'Not proctored',
            self::Basic    => 'Basic — browser lockdown',
            self::Standard => 'Standard — webcam monitoring',
            self::Advanced => 'Advanced — identity verification and recording',
        };
    }

    public function requiresWebcam(): bool
    {
        return match ($this) {
            self::Standard, self::Advanced => true,
            self::None, self::Basic        => false,
        };
    }

    public function requiresIdentityVerification(): bool
    {
        return $this === self::Advanced;
    }

    public function recordsMedia(): bool
    {
        return $this === self::Advanced;
    }

    /**
     * Levels that capture webcam, screen or audio of a learner. Anything true
     * here carries data-protection obligations: retention limits, a lawful
     * basis, and a restricted set of reviewers.
     */
    public function capturesPersonalData(): bool
    {
        return $this->requiresWebcam() || $this->recordsMedia();
    }

    /**
     * Levels a tenant may pick. Without the advanced-module entitlement the
     * only legal value is None.
     *
     * @return list<self>
     */
    public static function availableTo(bool $hasProctoringModule): array
    {
        return $hasProctoringModule ? self::cases() : [self::None];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $l): string => $l->value, self::cases());
    }
}
