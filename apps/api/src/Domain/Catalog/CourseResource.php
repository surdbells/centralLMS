<?php

declare(strict_types=1);

namespace Central\Api\Domain\Catalog;

/**
 * One file attached to a course as a downloadable resource.
 *
 * The material that sits alongside the lessons — a deck, a checklist, a
 * standard — of any file type. It is not a step in the path and carries no
 * progress; it is simply offered from the player's Resources tab.
 */
final readonly class CourseResource
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $courseId,
        public string $title,
        public string $originalFilename,
        public string $storageKey,
        public string $mimeType,
        public int $sizeBytes,
        public int $position = 0,
        public ?string $createdBy = null,
        public ?\DateTimeImmutable $createdAt = null,
    ) {
    }

    /** A short label for the kind of file, from its type, for the badge. */
    public function kind(): string
    {
        $ext = strtolower(pathinfo($this->originalFilename, PATHINFO_EXTENSION));

        return $ext !== '' ? $ext : 'file';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'courseId'         => $this->courseId,
            'title'            => $this->title,
            'originalFilename' => $this->originalFilename,
            'mimeType'         => $this->mimeType,
            'sizeBytes'        => $this->sizeBytes,
            'kind'             => $this->kind(),
            'position'         => $this->position,
            'createdAt'        => $this->createdAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
