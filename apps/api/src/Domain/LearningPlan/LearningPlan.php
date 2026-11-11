<?php

declare(strict_types=1);

namespace Central\Api\Domain\LearningPlan;

use Central\Api\Domain\Exception\ValidationFailed;

/**
 * A per-year learning plan: what an organisation intends its people to learn
 * this year, as an ordered list of courses and interventions.
 *
 * (Distinct from a {@see \Central\Api\Domain\LearningPath} — a path is an
 * ordered course sequence a learner works through; a plan is a yearly
 * commitment, scheduled and tracked per person.)
 */
final readonly class LearningPlan
{
    public const STATUSES = ['draft', 'published', 'archived'];

    public function __construct(
        public string $id,
        public string $tenantId,
        public int $year,
        public string $title,
        public ?string $description = null,
        public string $status = 'draft',
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
        /** Counted for the list view, never stored. */
        public int $itemCount = 0,
        public int $memberCount = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws ValidationFailed
     */
    public static function fromInput(string $id, string $tenantId, array $input): self
    {
        $violations = [];

        $year  = (int) ($input['year'] ?? 0);
        $title = trim((string) ($input['title'] ?? ''));

        if ($year < 2000 || $year > 2100) {
            $violations['year'] = 'Give the plan a four-digit year.';
        }

        if ($title === '') {
            $violations['title'] = 'Give the plan a title.';
        }

        $status = (string) ($input['status'] ?? 'draft');

        if (!in_array($status, self::STATUSES, true)) {
            $violations['status'] = 'Unknown status.';
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        $description = is_string($input['description'] ?? null) ? trim((string) $input['description']) : null;

        return new self(
            id: $id,
            tenantId: $tenantId,
            year: $year,
            title: $title,
            description: $description === '' ? null : $description,
            status: $status,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'year'        => $this->year,
            'title'       => $this->title,
            'description' => $this->description,
            'status'      => $this->status,
            'itemCount'   => $this->itemCount,
            'memberCount' => $this->memberCount,
            'createdAt'   => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'updatedAt'   => $this->updatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
