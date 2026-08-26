<?php

declare(strict_types=1);

namespace Central\Api\Domain\Proctoring;

/**
 * Where a proctored sitting has got to.
 *
 * Distinct from the attempt's own status: an attempt can be `in_progress`
 * while its proctoring session is still `pending_check`, which is exactly the
 * state a learner is in while granting camera permission.
 */
enum SessionStatus: string
{
    case PendingCheck = 'pending_check';
    case SystemCheck = 'system_check';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';

    /** Ended early, by a proctor or by a critical violation. */
    case Terminated = 'terminated';

    public static function fromLegacy(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::PendingCheck;
    }

    /** Whether events may still be recorded against it. */
    public function acceptsEvents(): bool
    {
        return $this === self::Active || $this === self::Paused;
    }

    public function hasEnded(): bool
    {
        return $this === self::Completed || $this === self::Terminated;
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingCheck => 'Awaiting checks',
            self::SystemCheck  => 'Running checks',
            self::Active       => 'In progress',
            self::Paused       => 'Paused',
            self::Completed    => 'Completed',
            self::Terminated   => 'Terminated',
        };
    }
}
