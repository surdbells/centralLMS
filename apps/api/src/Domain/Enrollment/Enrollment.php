<?php

declare(strict_types=1);

namespace Central\Api\Domain\Enrollment;

/**
 * One learner's place on one course.
 *
 * Read-mostly: the fields that move — progress, status, timestamps — are
 * written by the progress recorder in the same transaction as the lesson row
 * they derive from, never assembled by a caller. That is why there is no
 * `fromInput()` here as there is on `Course`: nothing about an enrolment's
 * state comes from a request body.
 */
final readonly class Enrollment
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $courseId,
        public string $userId,
        public EnrollmentStatus $status = EnrollmentStatus::NotStarted,
        public EnrollmentSource $source = EnrollmentSource::Direct,
        public ?string $assignedBy = null,
        public bool $isMandatory = true,
        public ?\DateTimeImmutable $dueDate = null,
        public float $progressPercentage = 0.0,
        public ?float $finalScore = null,
        public int $timeSpentSeconds = 0,
        public ?string $lastLessonId = null,
        public ?\DateTimeImmutable $startedAt = null,
        public ?\DateTimeImmutable $completedAt = null,
        public ?\DateTimeImmutable $lastActivityAt = null,
        public ?\DateTimeImmutable $enrolledAt = null,
        /** Denormalised for lists, so showing 50 enrolments is not 50 lookups. */
        public ?string $courseTitle = null,
        public ?string $courseThumbnailUrl = null,
        public ?string $courseLevel = null,
        public ?string $courseCategory = null,
        public ?string $userName = null,
        public ?string $userEmail = null,
    ) {
    }

    /**
     * Whether the learner has missed their deadline.
     *
     * Completed and dropped enrolments are never overdue however old the due
     * date is — see EnrollmentStatus::canBeOverdue().
     *
     * Compared as whole days, not instants: a deadline is a date, so something
     * due today is not overdue at one minute past midnight. Comparing the raw
     * values makes every learner overdue for the whole of their last day.
     */
    public function isOverdue(\DateTimeImmutable $now): bool
    {
        return ($this->daysRemaining($now) ?? 0) < 0;
    }

    /**
     * Days until the deadline; negative once it has passed.
     *
     * Null when there is no deadline or none can still apply, so a caller
     * cannot accidentally render "0 days left" for a finished course.
     */
    public function daysRemaining(\DateTimeImmutable $now): ?int
    {
        if ($this->dueDate === null || !$this->status->canBeOverdue()) {
            return null;
        }

        // Compare whole days: a deadline is a date, not an instant, so
        // something due today is not overdue at nine in the morning.
        $due = $this->dueDate->setTime(0, 0);
        $today = $now->setTime(0, 0);

        return (int) $today->diff($due)->format('%r%a');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $now = new \DateTimeImmutable();

        return [
            'id'                 => $this->id,
            'courseId'           => $this->courseId,
            'courseTitle'        => $this->courseTitle,
            'courseThumbnailUrl' => $this->courseThumbnailUrl,
            'courseLevel'        => $this->courseLevel,
            'courseCategory'     => $this->courseCategory,
            'userId'             => $this->userId,
            'userName'           => $this->userName,
            'userEmail'          => $this->userEmail,
            'status'             => $this->status->value,
            'statusLabel'        => $this->status->label(),
            'source'             => $this->source->value,
            'sourceLabel'        => $this->source->label(),
            'assignedBy'         => $this->assignedBy,
            'isMandatory'        => $this->isMandatory,
            'dueDate'            => $this->dueDate?->format('Y-m-d'),
            'isOverdue'          => $this->isOverdue($now),
            'daysRemaining'      => $this->daysRemaining($now),
            'progressPercentage' => $this->progressPercentage,
            'finalScore'         => $this->finalScore,
            'timeSpentSeconds'   => $this->timeSpentSeconds,
            'lastLessonId'       => $this->lastLessonId,
            'startedAt'          => $this->startedAt?->format(\DateTimeInterface::ATOM),
            'completedAt'        => $this->completedAt?->format(\DateTimeInterface::ATOM),
            'lastActivityAt'     => $this->lastActivityAt?->format(\DateTimeInterface::ATOM),
            'enrolledAt'         => $this->enrolledAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
