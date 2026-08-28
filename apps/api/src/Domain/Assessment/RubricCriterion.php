<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

use Central\Api\Domain\Exception\ValidationFailed;

/**
 * One thing a rubric asks a marker to judge.
 *
 * "Evidence", "Structure", "Accuracy". Each carries the bands a marker picks
 * between, and what it is worth at most.
 */
final readonly class RubricCriterion
{
    /**
     * @param list<RubricLevel> $levels
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $rubricId,
        public string $title,
        public ?string $description,
        public float $maxPoints,
        public int $sortOrder = 0,
        public array $levels = [],
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws ValidationFailed
     */
    public static function fromInput(
        string $id,
        string $tenantId,
        string $rubricId,
        array $input,
    ): self {
        $violations = [];

        $title = trim((string) ($input['title'] ?? ''));

        if ($title === '') {
            $violations['title'] = 'Name what this criterion judges.';
        } elseif (mb_strlen($title) > 255) {
            $violations['title'] = 'A criterion title must be 255 characters or fewer.';
        }

        $maxPoints = (float) ($input['maxPoints'] ?? 0);

        if ($maxPoints < 0) {
            $violations['maxPoints'] = 'A criterion cannot be worth less than nothing.';
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        $description = is_string($input['description'] ?? null)
            ? trim($input['description'])
            : null;

        return new self(
            id: $id,
            tenantId: $tenantId,
            rubricId: $rubricId,
            title: $title,
            description: $description === '' ? null : $description,
            maxPoints: $maxPoints,
            sortOrder: (int) ($input['sortOrder'] ?? 0),
        );
    }

    /**
     * @param list<RubricLevel> $levels
     */
    public function withLevels(array $levels): self
    {
        return new self(
            id: $this->id,
            tenantId: $this->tenantId,
            rubricId: $this->rubricId,
            title: $this->title,
            description: $this->description,
            maxPoints: $this->maxPoints,
            sortOrder: $this->sortOrder,
            levels: $levels,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
        );
    }

    /**
     * The most a marker could award here.
     *
     * Taken from the bands when there are any: a criterion whose top band is
     * worth 4 is worth 4, whatever the stored `maxPoints` says. Trusting the
     * stored figure is how a rubric comes to total more than its own bands
     * can produce.
     */
    public function effectiveMaxPoints(): float
    {
        if ($this->levels === []) {
            return $this->maxPoints;
        }

        return max(array_map(static fn (RubricLevel $l): float => $l->points, $this->levels));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'rubricId'    => $this->rubricId,
            'title'       => $this->title,
            'description' => $this->description,
            'maxPoints'   => $this->effectiveMaxPoints(),
            'sortOrder'   => $this->sortOrder,
            'levels'      => array_map(
                static fn (RubricLevel $l): array => $l->toArray(),
                $this->levels,
            ),
        ];
    }
}
