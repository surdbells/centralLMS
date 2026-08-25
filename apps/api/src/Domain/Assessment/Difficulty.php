<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

/**
 * A single three-point difficulty scale.
 *
 * The legacy schema managed to have two of these *within the same family*:
 * `proc_assessments.difficulty` was `Beginner|Intermediate|Professional` while
 * `proc_questions.difficulty` was `easy|medium|hard`. Same scale, different
 * words, so no query could compare a question's difficulty to its assessment's.
 *
 * The learner-facing vocabulary wins; the internal one folds into it.
 */
enum Difficulty: string
{
    case Beginner     = 'beginner';
    case Intermediate = 'intermediate';
    case Professional = 'professional';

    private const LEGACY_QUESTION_SCALE = [
        'easy'   => self::Beginner,
        'medium' => self::Intermediate,
        'hard'   => self::Professional,
    ];

    public static function fromLegacy(string $value): self
    {
        $value = strtolower(trim($value));

        return self::tryFrom($value)
            ?? self::LEGACY_QUESTION_SCALE[$value]
            ?? throw new \ValueError(sprintf('Unknown legacy difficulty "%s".', $value));
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** 1–3, for sorting and for weighting random question selection. */
    public function rank(): int
    {
        return match ($this) {
            self::Beginner     => 1,
            self::Intermediate => 2,
            self::Professional => 3,
        };
    }
}
