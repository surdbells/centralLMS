<?php

declare(strict_types=1);

namespace Central\Api\Domain\Skill;

/**
 * Something an organisation says its people can know how to do.
 *
 * Reference data, like a department or a job title, with two extra things a
 * lookup has not got: a proficiency scale and a set of courses that teach it.
 */
final readonly class Skill
{
    public function __construct(
        public ?string $id,
        public string $tenantId,
        public string $name,
        public Proficiency $level = Proficiency::Intermediate,
        public ?string $description = null,
        public ?string $categoryId = null,
        public ?string $icon = null,
        public string $colour = '#c9a227',
        public bool $isActive = true,
        public ?\DateTimeImmutable $createdAt = null,
        /** Resolved for display. Read-only. */
        public ?string $categoryName = null,
        public ?string $categoryColour = null,
        /** How many courses teach this. Derived, never stored. */
        public int $courseCount = 0,
        /** How many people have finished at least one of them. */
        public int $learnerCount = 0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'description'    => $this->description,
            'level'          => $this->level->value,
            'levelLabel'     => $this->level->label(),
            'icon'           => $this->icon,
            'colour'         => $this->colour,
            'isActive'       => $this->isActive,
            'categoryId'     => $this->categoryId,
            'categoryName'   => $this->categoryName,
            'categoryColour' => $this->categoryColour,
            'courseCount'    => $this->courseCount,
            'learnerCount'   => $this->learnerCount,
            'createdAt'      => $this->createdAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
