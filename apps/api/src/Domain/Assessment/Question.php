<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Shared\Difficulty;

/**
 * A question, belonging to a bank rather than to one assessment.
 *
 * That is the whole point of the port: the legacy `questions.assessment_id`
 * meant the same question had to be retyped for every quiz that wanted it.
 */
final readonly class Question
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $bankId,
        public QuestionType $type,
        public string $text,
        public float $points = 1.0,
        public ?string $explanation = null,
        public ?string $hint = null,
        public ?string $mediaUrl = null,
        public ?Difficulty $difficulty = null,
        public ?float $numericAnswer = null,
        public ?float $numericTolerance = null,
        public ?string $modelAnswer = null,
        public bool $isActive = true,
        /** @var list<QuestionOption> */
        public array $options = [],
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
        /** @var list<string> */
        public array $tags = [],
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws ValidationFailed
     */
    public static function fromInput(string $id, string $tenantId, string $bankId, array $input): self
    {
        $violations = [];

        $text = trim((string) ($input['text'] ?? ''));

        if ($text === '') {
            $violations['text'] = 'Enter the question.';
        }

        $type = null;
        $rawType = trim((string) ($input['type'] ?? ''));

        if ($rawType === '') {
            $violations['type'] = 'Choose a question type.';
        } else {
            try {
                $type = QuestionType::fromLegacy($rawType);
            } catch (\ValueError) {
                $violations['type'] = 'Choose one of: ' . implode(', ', array_map(
                    static fn (QuestionType $t): string => $t->value,
                    QuestionType::cases(),
                ));
            }
        }

        $points = (float) ($input['points'] ?? 1);

        if ($points < 0) {
            $violations['points'] = 'Points cannot be negative.';
        }

        $numericAnswer = self::number($input['numericAnswer'] ?? null);
        $numericTolerance = self::number($input['numericTolerance'] ?? null);

        if ($numericTolerance !== null && $numericTolerance < 0) {
            $violations['numericTolerance'] = 'Tolerance cannot be negative.';
        }

        if ($type === QuestionType::Numeric && $numericAnswer === null) {
            $violations['numericAnswer'] = 'A numeric question needs the value that counts as correct.';
        }

        if ($numericTolerance !== null && $numericAnswer === null) {
            $violations['numericAnswer'] = 'A tolerance needs a value to apply to.';
        }

        $options = self::optionsFromInput($tenantId, $id, $type, $input, $violations);

        if ($violations !== [] || $type === null) {
            throw ValidationFailed::fromArray($violations);
        }

        $difficulty = null;

        if (isset($input['difficulty']) && trim((string) $input['difficulty']) !== '') {
            try {
                $difficulty = Difficulty::fromLegacy((string) $input['difficulty']);
            } catch (\ValueError) {
                throw ValidationFailed::fromArray([
                    'difficulty' => 'Choose beginner, intermediate or professional.',
                ]);
            }
        }

        return new self(
            id: $id,
            tenantId: $tenantId,
            bankId: $bankId,
            type: $type,
            text: $text,
            points: $points,
            explanation: self::text($input['explanation'] ?? null),
            hint: self::text($input['hint'] ?? null),
            mediaUrl: self::text($input['mediaUrl'] ?? null),
            difficulty: $difficulty,
            numericAnswer: $numericAnswer,
            numericTolerance: $numericTolerance,
            modelAnswer: self::text($input['modelAnswer'] ?? null),
            isActive: filter_var($input['isActive'] ?? true, FILTER_VALIDATE_BOOL),
            options: $options,
            tags: self::tagsFromInput($input['tags'] ?? null),
        );
    }

    /**
     * Tags may arrive as a list or a single comma-separated string. Either way
     * they are trimmed, blanks dropped, and de-duplicated case-insensitively so
     * "Safety" and "safety" do not both survive; the first spelling seen wins.
     *
     * @return list<string>
     */
    private static function tagsFromInput(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }

        if (!is_array($raw)) {
            return [];
        }

        $tags = [];
        $seen = [];

        foreach ($raw as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $tag = trim((string) $value);
            $key = mb_strtolower($tag);

            if ($tag === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $tags[] = $tag;
        }

        return $tags;
    }

    /**
     * Builds and checks the options, which is where most of the type-specific
     * rules live.
     *
     * @param array<string, mixed>  $input
     * @param array<string, string> $violations
     *
     * @return list<QuestionOption>
     */
    private static function optionsFromInput(
        string $tenantId,
        string $questionId,
        ?QuestionType $type,
        array $input,
        array &$violations,
    ): array {
        $raw = $input['options'] ?? null;
        $options = [];

        if (is_array($raw)) {
            $position = 0;

            foreach ($raw as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $optionText = trim((string) ($row['text'] ?? ''));

                if ($optionText === '') {
                    continue;
                }

                $options[] = new QuestionOption(
                    id: is_string($row['id'] ?? null) && $row['id'] !== '' ? $row['id'] : '',
                    tenantId: $tenantId,
                    questionId: $questionId,
                    text: $optionText,
                    isCorrect: filter_var($row['isCorrect'] ?? false, FILTER_VALIDATE_BOOL),
                    feedback: self::text($row['feedback'] ?? null),
                    sortOrder: isset($row['sortOrder']) ? (int) $row['sortOrder'] : $position,
                    matchText: self::text($row['matchText'] ?? null),
                );

                $position++;
            }
        }

        if ($type === null) {
            return $options;
        }

        if (!$type->requiresOptions()) {
            return $options;
        }

        if (count($options) < 2) {
            $violations['options'] = 'Give at least two options.';

            return $options;
        }

        $correct = array_filter($options, static fn (QuestionOption $o): bool => $o->isCorrect);

        // A question with no right answer marks every learner wrong, and the
        // author only finds out from the complaints.
        if (
            $correct === []
            && in_array($type, [
                QuestionType::MultipleChoiceSingle,
                QuestionType::MultipleChoiceMultiple,
                QuestionType::TrueFalse,
            ], true)
        ) {
            $violations['options'] = 'Mark at least one option as correct.';
        }

        if (
            count($correct) > 1
            && in_array($type, [QuestionType::MultipleChoiceSingle, QuestionType::TrueFalse], true)
        ) {
            $violations['options'] = 'This type allows only one correct option.';
        }

        if ($type === QuestionType::TrueFalse && count($options) !== 2) {
            $violations['options'] = 'A true/false question has exactly two options.';
        }

        if ($type === QuestionType::Matching) {
            foreach ($options as $option) {
                if ($option->matchText === null) {
                    $violations['options'] = 'Every matching option needs the term it pairs with.';
                    break;
                }
            }
        }

        return $options;
    }

    /**
     * What this question is worth in a given assessment.
     */
    public function pointsIn(?float $override): float
    {
        return $override ?? $this->points;
    }

    /**
     * @param list<QuestionOption> $options
     */
    public function withOptions(array $options): self
    {
        return new self(
            id: $this->id,
            tenantId: $this->tenantId,
            bankId: $this->bankId,
            type: $this->type,
            text: $this->text,
            points: $this->points,
            explanation: $this->explanation,
            hint: $this->hint,
            mediaUrl: $this->mediaUrl,
            difficulty: $this->difficulty,
            numericAnswer: $this->numericAnswer,
            numericTolerance: $this->numericTolerance,
            modelAnswer: $this->modelAnswer,
            isActive: $this->isActive,
            options: $options,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
            tags: $this->tags,
        );
    }

    /**
     * @param list<string> $tags
     */
    public function withTags(array $tags): self
    {
        return new self(
            id: $this->id,
            tenantId: $this->tenantId,
            bankId: $this->bankId,
            type: $this->type,
            text: $this->text,
            points: $this->points,
            explanation: $this->explanation,
            hint: $this->hint,
            mediaUrl: $this->mediaUrl,
            difficulty: $this->difficulty,
            numericAnswer: $this->numericAnswer,
            numericTolerance: $this->numericTolerance,
            modelAnswer: $this->modelAnswer,
            isActive: $this->isActive,
            options: $this->options,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
            tags: $tags,
        );
    }

    /**
     * The payload for someone sitting the assessment.
     *
     * The answer key is stripped: no `isCorrect`, no `explanation`, no model
     * or numeric answer. A learner who opens the network tab must not find the
     * answers there — which is exactly what shipping one serialiser for both
     * audiences leads to.
     *
     * @return array<string, mixed>
     */
    public function toArrayForLearner(?float $pointsOverride = null): array
    {
        return [
            'id'       => $this->id,
            'type'     => $this->type->value,
            'text'     => $this->text,
            'points'   => $this->pointsIn($pointsOverride),
            'hint'     => $this->hint,
            'mediaUrl' => $this->mediaUrl,
            'options'  => array_map(
                static fn (QuestionOption $o): array => $o->toArrayForLearner(),
                $this->options,
            ),
        ];
    }

    /**
     * The full payload, for authors and for released results.
     *
     * @return array<string, mixed>
     */
    public function toArrayForReview(): array
    {
        return [
            'id'               => $this->id,
            'bankId'           => $this->bankId,
            'type'             => $this->type->value,
            'typeLabel'        => $this->type->label(),
            'text'             => $this->text,
            'points'           => $this->points,
            'explanation'      => $this->explanation,
            'hint'             => $this->hint,
            'mediaUrl'         => $this->mediaUrl,
            'difficulty'       => $this->difficulty?->value,
            'numericAnswer'    => $this->numericAnswer,
            'numericTolerance' => $this->numericTolerance,
            'modelAnswer'      => $this->modelAnswer,
            'isActive'         => $this->isActive,
            'isAutoGradable'   => $this->type->isAutoGradable(),
            'options'          => array_map(
                static fn (QuestionOption $o): array => $o->toArrayForReview(),
                $this->options,
            ),
            'tags'      => $this->tags,
            'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }

    private static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === null || $value === '' ? null : $value;
    }

    private static function number(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
