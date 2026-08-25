<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

/**
 * The one question vocabulary, shared by course quizzes, ILT assessments and
 * the proctored module.
 *
 * The legacy system had two: `questions.question_type` (6 values) and
 * `proc_questions.question_type` (11). The proctored set is a strict superset,
 * so this enum is that set, and {@see self::fromLegacy()} folds the older
 * vocabulary into it. Encoding the mapping once — here, with a test — is what
 * stops each porting task from inventing its own translation.
 */
enum QuestionType: string
{
    case MultipleChoiceSingle   = 'multiple_choice_single';
    case MultipleChoiceMultiple = 'multiple_choice_multiple';
    case TrueFalse              = 'true_false';
    case FillInBlanks           = 'fill_in_blanks';
    case ShortAnswer            = 'short_answer';
    case LongAnswer             = 'long_answer';
    case Matching               = 'matching';
    case Ordering               = 'ordering';
    case Hotspot                = 'hotspot';
    case Numeric                = 'numeric';
    case FileUpload             = 'file_upload';

    /**
     * Legacy `questions.question_type` values, which existed only on the
     * generic side. Every one maps with no loss of meaning.
     */
    private const LEGACY_GENERIC = [
        'single_choice'   => self::MultipleChoiceSingle,
        'multiple_choice' => self::MultipleChoiceMultiple,
        'true_false'      => self::TrueFalse,
        'short_answer'    => self::ShortAnswer,
        'essay'           => self::LongAnswer,
        'fill_blank'      => self::FillInBlanks,
    ];

    /**
     * Accepts a value from either legacy table.
     *
     * @throws \ValueError on an unrecognised value — a silent default here
     *                     would mis-grade learners
     */
    public static function fromLegacy(string $value): self
    {
        $value = strtolower(trim($value));

        return self::tryFrom($value)
            ?? self::LEGACY_GENERIC[$value]
            ?? throw new \ValueError(sprintf('Unknown legacy question type "%s".', $value));
    }

    public function label(): string
    {
        return match ($this) {
            self::MultipleChoiceSingle   => 'Multiple choice (one answer)',
            self::MultipleChoiceMultiple => 'Multiple choice (several answers)',
            self::TrueFalse              => 'True / false',
            self::FillInBlanks           => 'Fill in the blanks',
            self::ShortAnswer            => 'Short answer',
            self::LongAnswer             => 'Long answer',
            self::Matching               => 'Matching',
            self::Ordering               => 'Ordering',
            self::Hotspot                => 'Hotspot',
            self::Numeric                => 'Numeric',
            self::FileUpload             => 'File upload',
        };
    }

    /**
     * Whether the system can score this without a human.
     *
     * Drives `attempts.requires_grading`, so getting it wrong either strands
     * attempts in a grading queue forever or publishes ungraded results.
     */
    public function isAutoGradable(): bool
    {
        return match ($this) {
            self::LongAnswer, self::FileUpload => false,
            default                            => true,
        };
    }

    /**
     * Whether the question is meaningless without rows in `question_options`.
     */
    public function requiresOptions(): bool
    {
        return match ($this) {
            self::MultipleChoiceSingle,
            self::MultipleChoiceMultiple,
            self::TrueFalse,
            self::Matching,
            self::Ordering,
            self::Hotspot => true,
            default       => false,
        };
    }

    /**
     * Whether a partly-correct response can earn part of the points.
     */
    public function supportsPartialCredit(): bool
    {
        return match ($this) {
            self::MultipleChoiceMultiple,
            self::Matching,
            self::Ordering,
            self::FillInBlanks => true,
            default            => false,
        };
    }

    /**
     * Types the generic (non-proctored) authoring UI offers. The remainder are
     * available once the advanced module is enabled.
     *
     * @return list<self>
     */
    public static function genericSet(): array
    {
        return array_values(self::LEGACY_GENERIC);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
