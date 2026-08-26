<?php

declare(strict_types=1);

namespace Central\Api\Domain\LearningPath;

/**
 * One step in a path.
 *
 * Points at exactly one thing — a course, an assessment, a session, a piece of
 * media or a link — and the check constraint keeps it that way. The legacy had
 * five nullable ids and nothing stopping a row from setting three of them.
 *
 * There is no `title` for anything but a link, and that is deliberate: a course
 * has a title already, and copying it here is how a course renamed in the
 * catalogue keeps its old name inside a path.
 */
final readonly class PathItem
{
    public function __construct(
        public ?string $id,
        public string $tenantId,
        public string $pathId,
        public PathItemKind $kind,
        public ?string $sectionId = null,
        public ?string $courseId = null,
        public ?string $assessmentId = null,
        public ?string $sessionId = null,
        public ?string $mediaId = null,
        public ?string $externalUrl = null,
        public ?string $title = null,
        public ?string $description = null,
        public int $sortOrder = 0,
        public bool $isRequired = true,
        public int $pointsValue = 0,
        public ?\DateTimeImmutable $dueDate = null,
        public ?int $dueDaysFromEnrolment = null,
        public ?int $estimatedMinutes = null,
        /** Resolved for display. Read-only. */
        public ?string $subjectTitle = null,
        public ?string $sectionTitle = null,
        public bool $isMilestoneSection = false,
    ) {
    }

    /**
     * The id of whatever this points at.
     */
    public function subjectId(): ?string
    {
        return match ($this->kind) {
            PathItemKind::Course     => $this->courseId,
            PathItemKind::Assessment => $this->assessmentId,
            PathItemKind::Session    => $this->sessionId,
            PathItemKind::Media      => $this->mediaId,
            PathItemKind::Link       => null,
        };
    }

    /**
     * What to call it.
     *
     * The subject's own title where there is one, so a renamed course reads
     * correctly inside every path that contains it.
     */
    public function displayTitle(): string
    {
        return $this->subjectTitle ?? $this->title ?? $this->kind->label();
    }

    /**
     * When this item is due for somebody who enrolled on a given day.
     *
     * A fixed date wins where there is one; otherwise it is an offset from the
     * enrolment, which is what makes one path work for cohorts starting in
     * different months.
     */
    public function dueFor(\DateTimeImmutable $enrolledAt): ?\DateTimeImmutable
    {
        if ($this->dueDate !== null) {
            return $this->dueDate;
        }

        if ($this->dueDaysFromEnrolment === null) {
            return null;
        }

        return $enrolledAt->modify(sprintf('+%d days', $this->dueDaysFromEnrolment));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'                   => $this->id,
            'kind'                 => $this->kind->value,
            'kindLabel'            => $this->kind->label(),
            'title'                => $this->displayTitle(),
            'description'          => $this->description,
            'sectionId'            => $this->sectionId,
            'sectionTitle'         => $this->sectionTitle,
            'subjectId'            => $this->subjectId(),
            'externalUrl'          => $this->externalUrl,
            'sortOrder'            => $this->sortOrder,
            'isRequired'           => $this->isRequired,
            'pointsValue'          => $this->pointsValue,
            'dueDate'              => $this->dueDate?->format('Y-m-d'),
            'dueDaysFromEnrolment' => $this->dueDaysFromEnrolment,
            'estimatedMinutes'     => $this->estimatedMinutes,
        ];
    }
}
