<?php

declare(strict_types=1);

namespace Central\Api\Domain\Proctoring;

/**
 * How much confidence the recorded events leave in a sitting.
 *
 * Computed from the violations, never stored: the legacy session row kept
 * `violation_score`, `trust_score`, `total_violations` and five per-type
 * counters alongside the rows they summarised, which is the same shape as the
 * enrolments `is_completed` flag that contradicted its own status in 16 of 159
 * rows.
 *
 * The number is advisory. It orders a review queue; it does not decide
 * anything. A person decides, which is why `proctoring_sessions` has
 * `reviewed_by` and no automatic verdict column.
 */
final readonly class TrustScore
{
    private function __construct(
        public float $score,
        public int $violationCount,
        /** @var array<string, int> severity => how many */
        public array $bySeverity,
        public bool $hasCritical,
    ) {
    }

    /**
     * @param list<array{severity: ViolationSeverity, score: float, reviewStatus: string}> $violations
     */
    public static function fromViolations(array $violations): self
    {
        $penalty = 0.0;
        $counted = 0;
        $bySeverity = ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
        $hasCritical = false;

        foreach ($violations as $violation) {
            // A reviewer who marked something a false positive has said it did
            // not happen. Continuing to count it would make review pointless,
            // and is why the score is derived rather than stamped once.
            if ($violation['reviewStatus'] === 'false_positive') {
                continue;
            }

            $counted++;
            $bySeverity[$violation['severity']->value]++;
            $penalty += $violation['score'];

            if ($violation['severity'] === ViolationSeverity::Critical) {
                $hasCritical = true;
            }
        }

        return new self(
            // Floored at zero: a session cannot be worse than untrustworthy,
            // and a negative number would sort below one with no events.
            score: max(0.0, round(100.0 - $penalty, 2)),
            violationCount: $counted,
            bySeverity: $bySeverity,
            hasCritical: $hasCritical,
        );
    }

    /**
     * Whether a person should look at this sitting.
     *
     * Two independent triggers, because they catch different things: one
     * serious event, or an accumulation of small ones.
     */
    public function needsReview(): bool
    {
        return $this->hasCritical || $this->score < 70.0;
    }

    /**
     * A word for the number, so a report does not have to invent one.
     */
    public function band(): string
    {
        return match (true) {
            $this->hasCritical => 'critical',
            $this->score >= 90.0 => 'clear',
            $this->score >= 70.0 => 'minor concerns',
            $this->score >= 40.0 => 'significant concerns',
            default => 'severe concerns',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'score'          => $this->score,
            'band'           => $this->band(),
            'violationCount' => $this->violationCount,
            'bySeverity'     => $this->bySeverity,
            'hasCritical'    => $this->hasCritical,
            'needsReview'    => $this->needsReview(),
        ];
    }
}
