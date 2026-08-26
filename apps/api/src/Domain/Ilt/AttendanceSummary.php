<?php

declare(strict_types=1);

namespace Central\Api\Domain\Ilt;

/**
 * What a learner's attendance adds up to.
 */
final readonly class AttendanceSummary
{
    /**
     * @param list<string> $missedSessionIds
     */
    public function __construct(
        public float $percentage,
        public int $sessionsAttended,
        public int $sessionsCounted,
        public int $sessionsExcused,
        public array $missedSessionIds,
        public float $required,
    ) {
    }

    /**
     * Whether the requirement is met.
     *
     * Compared with a small tolerance, because the percentage is rounded to
     * two places: two of three sessions is 66.67, and a programme requiring
     * "66.67%" would otherwise fail a learner who met it exactly.
     */
    public function meetsRequirement(): bool
    {
        return $this->percentage + 0.005 >= $this->required;
    }

    /**
     * How many more sessions would be needed to meet it.
     *
     * Null once it is met, and null when it can no longer be met by attending
     * — the caller needs to tell those apart to say anything useful.
     */
    public function sessionsStillNeeded(int $remainingSessions): ?int
    {
        if ($this->meetsRequirement()) {
            return null;
        }

        // How many of the remaining sessions must be attended for the final
        // figure to clear the bar.
        $total = $this->sessionsCounted + $remainingSessions;

        if ($total === 0) {
            return null;
        }

        $needed = (int) ceil($this->required / 100 * $total) - $this->sessionsAttended;

        return $needed > $remainingSessions ? null : max(0, $needed);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'percentage'       => $this->percentage,
            'sessionsAttended' => $this->sessionsAttended,
            'sessionsCounted'  => $this->sessionsCounted,
            'sessionsExcused'  => $this->sessionsExcused,
            'missedSessions'   => count($this->missedSessionIds),
            'required'         => $this->required,
            'meetsRequirement' => $this->meetsRequirement(),
        ];
    }
}
