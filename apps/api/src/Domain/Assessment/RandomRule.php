<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Shared\Difficulty;

/**
 * "Draw N questions from this bank, matching these filters."
 *
 * Used by `pool` selection. Several rules compose: three questions of one
 * difficulty, two of another, all from a named bank.
 */
final readonly class RandomRule
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $assessmentId,
        public string $bankId,
        public int $questionCount,
        public float $pointsPerQuestion = 1.0,
        public ?string $sectionId = null,
        public ?string $categoryId = null,
        public ?Difficulty $difficulty = null,
        /** @var list<QuestionType> */
        public array $questionTypes = [],
        public int $sortOrder = 0,
        /** How many questions actually match, counted on read. */
        public int $availableCount = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws ValidationFailed
     */
    public static function fromInput(string $id, string $tenantId, string $assessmentId, array $input): self
    {
        $violations = [];

        $bankId = trim((string) ($input['bankId'] ?? ''));

        if ($bankId === '') {
            $violations['bankId'] = 'Choose the bank to draw from.';
        }

        $count = (int) ($input['questionCount'] ?? 0);

        if ($count < 1) {
            $violations['questionCount'] = 'Draw at least one question.';
        }

        $points = (float) ($input['pointsPerQuestion'] ?? 1);

        if ($points < 0) {
            $violations['pointsPerQuestion'] = 'Points cannot be negative.';
        }

        $difficulty = null;
        $rawDifficulty = trim((string) ($input['difficulty'] ?? ''));

        // 'any' is how the legacy column spelled "no filter"; it is absence,
        // not a fourth difficulty.
        if ($rawDifficulty !== '' && strtolower($rawDifficulty) !== 'any') {
            try {
                $difficulty = Difficulty::fromLegacy($rawDifficulty);
            } catch (\ValueError) {
                $violations['difficulty'] = 'Choose beginner, intermediate, professional, or any.';
            }
        }

        $types = [];

        if (isset($input['questionTypes']) && is_array($input['questionTypes'])) {
            foreach ($input['questionTypes'] as $raw) {
                if (!is_string($raw) || trim($raw) === '') {
                    continue;
                }

                try {
                    $types[] = QuestionType::fromLegacy($raw);
                } catch (\ValueError) {
                    $violations['questionTypes'] = sprintf('"%s" is not a question type.', $raw);
                    break;
                }
            }
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        $sectionId = self::text($input['sectionId'] ?? null);
        $categoryId = self::text($input['categoryId'] ?? null);

        return new self(
            id: $id,
            tenantId: $tenantId,
            assessmentId: $assessmentId,
            bankId: $bankId,
            questionCount: $count,
            pointsPerQuestion: $points,
            sectionId: $sectionId,
            categoryId: $categoryId,
            difficulty: $difficulty,
            questionTypes: array_values(array_unique($types, SORT_REGULAR)),
            sortOrder: (int) ($input['sortOrder'] ?? 0),
        );
    }

    /**
     * Whether the bank actually holds enough matching questions.
     *
     * A rule asking for ten when six match draws six and quietly shortens
     * every paper. Publishing checks this so the author hears about it before
     * a learner does.
     */
    public function isSatisfiable(): bool
    {
        return $this->availableCount >= $this->questionCount;
    }

    public function withAvailableCount(int $count): self
    {
        return new self(
            id: $this->id,
            tenantId: $this->tenantId,
            assessmentId: $this->assessmentId,
            bankId: $this->bankId,
            questionCount: $this->questionCount,
            pointsPerQuestion: $this->pointsPerQuestion,
            sectionId: $this->sectionId,
            categoryId: $this->categoryId,
            difficulty: $this->difficulty,
            questionTypes: $this->questionTypes,
            sortOrder: $this->sortOrder,
            availableCount: $count,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'assessmentId'  => $this->assessmentId,
            'sectionId'     => $this->sectionId,
            'bankId'        => $this->bankId,
            'categoryId'    => $this->categoryId,
            'difficulty'    => $this->difficulty?->value,
            'questionTypes' => array_map(
                static fn (QuestionType $t): string => $t->value,
                $this->questionTypes,
            ),
            'questionCount'     => $this->questionCount,
            'pointsPerQuestion' => $this->pointsPerQuestion,
            'sortOrder'         => $this->sortOrder,
            'availableCount'    => $this->availableCount,
            'isSatisfiable'     => $this->isSatisfiable(),
        ];
    }

    private static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === null || $value === '' ? null : $value;
    }
}
