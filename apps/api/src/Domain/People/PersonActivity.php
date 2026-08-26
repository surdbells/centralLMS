<?php

declare(strict_types=1);

namespace Central\Api\Domain\People;

/**
 * What somebody has actually done, counted from the enrolment rows.
 *
 * Never stored. The legacy computed each of these with its own query per user
 * — `getUserCourseCount`, `getUserCourseStarted`, `getUserCourseCompleted`,
 * `getUserActivityCount` — called inside the loop that built the user list, so
 * a page of 25 people cost 100 round trips.
 */
final readonly class PersonActivity
{
    public function __construct(
        public int $enrolments = 0,
        public int $inProgress = 0,
        public int $completed = 0,
        public int $overdue = 0,
        public int $secondsSpent = 0,
        public ?\DateTimeImmutable $lastActivityAt = null,
    ) {
    }

    public function completionRate(): float
    {
        return $this->enrolments === 0
            ? 0.0
            : round(100 * $this->completed / $this->enrolments, 1);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enrolments'     => $this->enrolments,
            'inProgress'     => $this->inProgress,
            'completed'      => $this->completed,
            'overdue'        => $this->overdue,
            'completionRate' => $this->completionRate(),
            'secondsSpent'   => $this->secondsSpent,
            'lastActivityAt' => $this->lastActivityAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
