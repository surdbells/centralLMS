<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

use Central\Api\Domain\Exception\ValidationFailed;

/**
 * A named collection of questions.
 *
 * The unit of reuse: assessments draw from banks, so a question written once
 * can be asked by every quiz that needs it. Legacy questions were bound to a
 * single assessment by `questions.assessment_id`.
 */
final readonly class QuestionBank
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $name,
        public ?string $description = null,
        public ?string $categoryId = null,
        public bool $isActive = true,
        public ?string $createdBy = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
        /** Counted on read. */
        public int $questionCount = 0,
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

        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            $violations['name'] = 'Enter a name for the bank.';
        } elseif (mb_strlen($name) > 255) {
            $violations['name'] = 'Name must be 255 characters or fewer.';
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        $description = is_string($input['description'] ?? null) ? trim($input['description']) : null;
        $categoryId = is_string($input['categoryId'] ?? null) ? trim($input['categoryId']) : null;

        return new self(
            id: $id,
            tenantId: $tenantId,
            name: $name,
            description: $description === '' ? null : $description,
            categoryId: $categoryId === '' ? null : $categoryId,
            isActive: filter_var($input['isActive'] ?? true, FILTER_VALIDATE_BOOL),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'description'   => $this->description,
            'categoryId'    => $this->categoryId,
            'isActive'      => $this->isActive,
            'questionCount' => $this->questionCount,
            'createdAt'     => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'updatedAt'     => $this->updatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
