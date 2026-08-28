<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

use Central\Api\Domain\Exception\ValidationFailed;

/**
 * How a written answer gets marked the same way twice.
 *
 * A rubric names the things a marker should judge and, for each, the bands
 * they choose between. Two people marking the same essay against the same
 * rubric land close to the same score, and a learner asking why they got what
 * they got has an answer beyond one marker's impression of it.
 *
 * The total is counted from the criteria rather than stored. A stored total is
 * wrong the first time somebody edits a criterion and right only by accident
 * after that.
 */
final readonly class Rubric
{
    /**
     * @param list<RubricCriterion> $criteria
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $name,
        public ?string $description,
        public RubricKind $kind = RubricKind::Analytic,
        public bool $isActive = true,
        public ?string $createdBy = null,
        public array $criteria = [],
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
        /** Counted on read: how many questions are marked against this. */
        public int $questionCount = 0,
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
        array $input,
        ?string $createdBy = null,
    ): self {
        $violations = [];

        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            $violations['name'] = 'Give the rubric a name.';
        } elseif (mb_strlen($name) > 255) {
            $violations['name'] = 'A rubric name must be 255 characters or fewer.';
        }

        $kind = RubricKind::tryFrom((string) ($input['kind'] ?? RubricKind::Analytic->value));

        if ($kind === null) {
            $violations['kind'] = sprintf(
                'Choose one of: %s.',
                implode(', ', RubricKind::values()),
            );
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
            name: $name,
            description: $description === '' ? null : $description,
            kind: $kind ?? RubricKind::Analytic,
            isActive: (bool) ($input['isActive'] ?? true),
            createdBy: $createdBy,
        );
    }

    /**
     * @param list<RubricCriterion> $criteria
     */
    public function withCriteria(array $criteria): self
    {
        return new self(
            id: $this->id,
            tenantId: $this->tenantId,
            name: $this->name,
            description: $this->description,
            kind: $this->kind,
            isActive: $this->isActive,
            createdBy: $this->createdBy,
            criteria: $criteria,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
            questionCount: $this->questionCount,
        );
    }

    /** How many questions are marked against this, counted on read. */
    public function withQuestionCount(int $count): self
    {
        return new self(
            id: $this->id,
            tenantId: $this->tenantId,
            name: $this->name,
            description: $this->description,
            kind: $this->kind,
            isActive: $this->isActive,
            createdBy: $this->createdBy,
            criteria: $this->criteria,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
            questionCount: $count,
        );
    }

    /** The most this rubric can award, from its criteria. */
    public function totalPoints(): float
    {
        return array_sum(array_map(
            static fn (RubricCriterion $c): float => $c->effectiveMaxPoints(),
            $this->criteria,
        ));
    }

    /**
     * Whether this rubric can actually be used to mark anything.
     *
     * A rubric with no criteria, or criteria with no bands, is a title and
     * nothing else — a marker opening it has nothing to choose between. Saying
     * so when it is attached to a question beats discovering it mid-marking.
     */
    public function isUsable(): bool
    {
        if ($this->criteria === []) {
            return false;
        }

        foreach ($this->criteria as $criterion) {
            if ($criterion->levels === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function toArray(array $extra = []): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'description'   => $this->description,
            'kind'          => $this->kind->value,
            'kindLabel'     => $this->kind->label(),
            'isActive'      => $this->isActive,
            'isUsable'      => $this->isUsable(),
            // Counted from the criteria, never stored — see the class note.
            'totalPoints'   => $this->totalPoints(),
            'criterionCount' => count($this->criteria),
            'questionCount' => $this->questionCount,
            'criteria'      => array_map(
                static fn (RubricCriterion $c): array => $c->toArray(),
                $this->criteria,
            ),
            'createdBy'     => $this->createdBy,
            'createdAt'     => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'updatedAt'     => $this->updatedAt?->format(\DateTimeInterface::ATOM),
            ...$extra,
        ];
    }
}
