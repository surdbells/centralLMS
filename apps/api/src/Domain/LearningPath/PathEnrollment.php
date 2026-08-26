<?php

declare(strict_types=1);

namespace Central\Api\Domain\LearningPath;

/**
 * Somebody on a path.
 *
 * Six of the legacy's columns are not here — `progress_percentage`,
 * `items_completed`, `items_total`, `current_item_id`, `total_points_earned`
 * and `avg_assessment_score` — because {@see PathProgress} answers all six from
 * the items and what has been finished. `items_total` was 4 on every one of the
 * thirty-three rows, for a path with two claimed items and none active.
 */
final readonly class PathEnrollment
{
    public function __construct(
        public ?string $id,
        public string $tenantId,
        public string $pathId,
        public string $userId,
        public string $status = 'active',
        public string $source = 'direct',
        public bool $isMandatory = false,
        public ?string $assignedBy = null,
        public ?\DateTimeImmutable $dueDate = null,
        public ?\DateTimeImmutable $enrolledAt = null,
        public ?\DateTimeImmutable $startedAt = null,
        public ?\DateTimeImmutable $completedAt = null,
        public ?string $certificateId = null,
        /** Resolved for display. Read-only. */
        public ?string $pathTitle = null,
        public ?string $learnerName = null,
        public ?string $learnerEmail = null,
        /** Derived, never stored. */
        public ?PathProgress $progress = null,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->status === 'completed';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Whether the deadline has passed with work still outstanding.
     */
    public function isOverdue(?\DateTimeImmutable $now = null): bool
    {
        if ($this->dueDate === null || $this->isComplete() || $this->status === 'withdrawn') {
            return false;
        }

        return $this->dueDate < ($now ?? new \DateTimeImmutable('today'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'pathId'        => $this->pathId,
            'pathTitle'     => $this->pathTitle,
            'userId'        => $this->userId,
            'learnerName'   => $this->learnerName,
            'learnerEmail'  => $this->learnerEmail,
            'status'        => $this->status,
            'source'        => $this->source,
            'isMandatory'   => $this->isMandatory,
            'assignedBy'    => $this->assignedBy,
            'dueDate'       => $this->dueDate?->format('Y-m-d'),
            'isOverdue'     => $this->isOverdue(),
            'certificateId' => $this->certificateId,
            'enrolledAt'    => $this->enrolledAt?->format(\DateTimeInterface::ATOM),
            'startedAt'     => $this->startedAt?->format(\DateTimeInterface::ATOM),
            'completedAt'   => $this->completedAt?->format(\DateTimeInterface::ATOM),
            'progress'      => $this->progress?->toArray(),
        ];
    }
}
