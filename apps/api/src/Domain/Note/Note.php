<?php

declare(strict_types=1);

namespace Central\Api\Domain\Note;

/**
 * Something a learner wrote down while working through a lesson.
 *
 * Always somebody's own. There is no shared note and no note about another
 * person, which is why nothing here carries an author the caller can choose —
 * `user/notes/create.php` took `user_id` from the request body.
 */
final readonly class Note
{
    public function __construct(
        public ?string $id,
        public string $tenantId,
        public string $userId,
        public string $lessonId,
        public string $courseId,
        public string $content,
        public ?string $title = null,
        public ?int $positionSeconds = null,
        public bool $isPinned = false,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
        /** Resolved for display. Read-only. */
        public ?string $lessonTitle = null,
        public ?string $moduleTitle = null,
        public ?string $courseTitle = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'lessonId'        => $this->lessonId,
            'courseId'        => $this->courseId,
            'title'           => $this->title,
            'content'         => $this->content,
            'positionSeconds' => $this->positionSeconds,
            'isPinned'        => $this->isPinned,
            'lessonTitle'     => $this->lessonTitle,
            'moduleTitle'     => $this->moduleTitle,
            'courseTitle'     => $this->courseTitle,
            'createdAt'       => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'updatedAt'       => $this->updatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
