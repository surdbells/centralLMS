<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

/**
 * Who is marking one attempt, and how urgently.
 *
 * Deliberately *only* the decisions a person made. What needs marking is
 * derived — it is the attempts holding an ungraded written answer — and
 * storing that would be a cache that goes stale the moment somebody submits
 * or a marker finishes. What cannot be derived is who a coordinator handed it
 * to, what order they want it done in, and whether a marker set it aside.
 *
 * There is no "unassigned" state. A paper goes back into the general queue by
 * this row being removed, which is one fewer status to forget to set.
 */
final readonly class GradingAssignment
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $attemptId,
        public ?string $assignedTo,
        public ?string $assignedBy = null,
        /** Higher is sooner. */
        public int $priority = 0,
        public ?\DateTimeImmutable $skippedAt = null,
        public ?string $skipReason = null,
        public ?string $notes = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
        /** Joined for display. */
        public ?string $assigneeName = null,
    ) {
    }

    /**
     * Whether this is still somebody's to mark.
     *
     * A skipped assignment stays on the row so the next coordinator can see
     * that it was set aside and why, rather than reassigning it to the same
     * person who could not do it.
     */
    public function isOpen(): bool
    {
        return $this->assignedTo !== null && $this->skippedAt === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'attemptId'  => $this->attemptId,
            'assignedTo' => $this->assignedTo,
            'assigneeName' => $this->assigneeName,
            'assignedBy' => $this->assignedBy,
            'priority'   => $this->priority,
            'isOpen'     => $this->isOpen(),
            'skippedAt'  => $this->skippedAt?->format(\DateTimeInterface::ATOM),
            'skipReason' => $this->skipReason,
            'notes'      => $this->notes,
            'createdAt'  => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'updatedAt'  => $this->updatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
