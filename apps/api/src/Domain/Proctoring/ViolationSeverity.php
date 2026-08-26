<?php

declare(strict_types=1);

namespace Central\Api\Domain\Proctoring;

/**
 * How much weight a violation carries.
 */
enum ViolationSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public static function fromLegacy(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::Medium;
    }

    /**
     * What one event of this severity costs the trust score.
     *
     * The gaps are wide on purpose: four "looked away" events should not add
     * up to one "someone else is sitting the exam".
     */
    public function weight(): float
    {
        return match ($this) {
            self::Low      => 0.5,
            self::Medium   => 2.0,
            self::High     => 8.0,
            self::Critical => 25.0,
        };
    }

    /**
     * Whether one event of this severity is enough to stop the sitting.
     *
     * Only critical. Ending an exam is not recoverable for the learner, so it
     * is reserved for the cases that are not ambiguous.
     */
    public function endsTheSitting(): bool
    {
        return $this === self::Critical;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
