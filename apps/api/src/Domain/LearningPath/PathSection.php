<?php

declare(strict_types=1);

namespace Central\Api\Domain\LearningPath;

/**
 * A named group of items within a path.
 *
 * A milestone section gates the ones after it when the path is paced by
 * milestone; an ordinary section is a heading. `unlock_after_section_id` and
 * the four unlock types it came with are not carried — the order is the gate,
 * and a section that unlocks one out of order is a graph nobody drew.
 */
final readonly class PathSection
{
    public function __construct(
        public ?string $id,
        public string $tenantId,
        public string $pathId,
        public string $title,
        public ?string $description = null,
        public int $sortOrder = 0,
        public bool $isMilestone = false,
        /** How many of its items must be done. Null means all the required ones. */
        public ?int $minItemsToComplete = null,
        public ?\DateTimeImmutable $createdAt = null,
        /** Derived, never stored — `total_items` was a column that drifted. */
        public int $itemCount = 0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'                 => $this->id,
            'pathId'             => $this->pathId,
            'title'              => $this->title,
            'description'        => $this->description,
            'sortOrder'          => $this->sortOrder,
            'isMilestone'        => $this->isMilestone,
            'minItemsToComplete' => $this->minItemsToComplete,
            'itemCount'          => $this->itemCount,
            'createdAt'          => $this->createdAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
