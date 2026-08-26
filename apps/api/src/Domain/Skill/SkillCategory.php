<?php

declare(strict_types=1);

namespace Central\Api\Domain\Skill;

/**
 * A grouping of skills, so a long list reads as a few short ones.
 */
final readonly class SkillCategory
{
    public function __construct(
        public ?string $id,
        public string $tenantId,
        public string $name,
        public ?string $description = null,
        public string $colour = '#6c757d',
        public bool $isActive = true,
        public ?\DateTimeImmutable $createdAt = null,
        /** Derived, never stored. */
        public int $skillCount = 0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'description' => $this->description,
            'colour'      => $this->colour,
            'isActive'    => $this->isActive,
            'skillCount'  => $this->skillCount,
            'createdAt'   => $this->createdAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
