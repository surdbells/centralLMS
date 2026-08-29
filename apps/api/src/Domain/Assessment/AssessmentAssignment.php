<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

/**
 * One assignment of an assessment: a target that must sit it, and by when.
 *
 * The target is a person, a department, a team, a branch, or everyone. Its
 * name is resolved for display when the assignment is read; it is not stored,
 * so renaming a department renames it here too.
 */
final readonly class AssessmentAssignment
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $assessmentId,
        public string $assignmentType,
        public ?string $targetId,
        public ?string $targetName,
        public ?\DateTimeImmutable $dueDate,
        public ?\DateTimeImmutable $createdAt = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'assessmentId'   => $this->assessmentId,
            'assignmentType' => $this->assignmentType,
            'targetId'       => $this->targetId,
            'targetName'     => $this->targetName ?? ($this->assignmentType === 'all' ? 'Everyone' : null),
            'dueDate'        => $this->dueDate?->format(\DateTimeInterface::ATOM),
            'createdAt'      => $this->createdAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
