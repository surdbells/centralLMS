<?php

declare(strict_types=1);

namespace Central\Api\Domain\Catalog;

use Central\Api\Domain\Exception\ValidationFailed;

/**
 * A section of a course.
 *
 * Named `CourseModule` rather than `Module` because "module" already means a
 * unit of the product elsewhere in this codebase (the ILT module, the
 * proctoring module) and the two would read as the same thing.
 */
final readonly class CourseModule
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $courseId,
        public string $title,
        public ?string $description = null,
        public int $sortOrder = 0,
        public bool $isActive = true,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
        /**
         * Populated only by the outline read. An empty list means "no lessons";
         * it never means "not loaded", because every read that returns modules
         * either fills this in or leaves it empty deliberately.
         *
         * @var list<Lesson>
         */
        public array $lessons = [],
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws ValidationFailed
     */
    public static function fromInput(string $id, string $tenantId, string $courseId, array $input): self
    {
        $violations = [];

        $title = trim((string) ($input['title'] ?? ''));

        if ($title === '') {
            $violations['title'] = 'Enter a module title.';
        } elseif (mb_strlen($title) > 255) {
            $violations['title'] = 'Title must be 255 characters or fewer.';
        }

        $sortOrder = (int) ($input['sortOrder'] ?? 0);

        if ($sortOrder < 0) {
            $violations['sortOrder'] = 'Position cannot be negative.';
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        return new self(
            id: $id,
            tenantId: $tenantId,
            courseId: $courseId,
            title: $title,
            description: self::text($input['description'] ?? null),
            sortOrder: $sortOrder,
            isActive: filter_var($input['isActive'] ?? true, FILTER_VALIDATE_BOOL),
        );
    }

    /**
     * @param list<Lesson> $lessons
     */
    public function withLessons(array $lessons): self
    {
        return new self(
            id: $this->id,
            tenantId: $this->tenantId,
            courseId: $this->courseId,
            title: $this->title,
            description: $this->description,
            sortOrder: $this->sortOrder,
            isActive: $this->isActive,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
            lessons: $lessons,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $withLessons = false): array
    {
        $out = [
            'id'          => $this->id,
            'courseId'    => $this->courseId,
            'title'       => $this->title,
            'description' => $this->description,
            'sortOrder'   => $this->sortOrder,
            'isActive'    => $this->isActive,
            'createdAt'   => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'updatedAt'   => $this->updatedAt?->format(\DateTimeInterface::ATOM),
        ];

        if ($withLessons) {
            $out['lessons'] = array_map(static fn (Lesson $l): array => $l->toArray(), $this->lessons);
        }

        return $out;
    }

    private static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === null || $value === '' ? null : $value;
    }
}
