<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Assessment;

use Central\Api\Domain\Assessment\AssessmentStatus;
use Central\Api\Domain\Assessment\AttemptStatus;
use Central\Api\Domain\Shared\Difficulty;
use Central\Api\Domain\Assessment\PassingType;
use Central\Api\Domain\Assessment\ProctoringLevel;
use Central\Api\Domain\Assessment\QuestionSelectionMode;
use Central\Api\Domain\Assessment\QuestionType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Guards the merge of the two legacy assessment vocabularies.
 *
 * The enum values below are transcribed from the MySQL enum definitions in
 * `legacy/db/central_lxp.sql`. If a value in production stops mapping, the
 * data migration would silently drop or mis-convert rows — so every one is
 * asserted explicitly rather than trusted.
 */
#[CoversClass(QuestionType::class)]
#[CoversClass(AttemptStatus::class)]
#[CoversClass(AssessmentStatus::class)]
#[CoversClass(Difficulty::class)]
#[CoversClass(ProctoringLevel::class)]
#[CoversClass(QuestionSelectionMode::class)]
#[CoversClass(PassingType::class)]
final class LegacyVocabularyTest extends TestCase
{
    /**
     * `questions.question_type` — the generic side, 6 values.
     *
     * @return iterable<string, array{string, QuestionType}>
     */
    public static function genericQuestionTypes(): iterable
    {
        yield 'single_choice'   => ['single_choice', QuestionType::MultipleChoiceSingle];
        yield 'multiple_choice' => ['multiple_choice', QuestionType::MultipleChoiceMultiple];
        yield 'true_false'      => ['true_false', QuestionType::TrueFalse];
        yield 'short_answer'    => ['short_answer', QuestionType::ShortAnswer];
        yield 'essay'           => ['essay', QuestionType::LongAnswer];
        yield 'fill_blank'      => ['fill_blank', QuestionType::FillInBlanks];
    }

    #[Test]
    #[DataProvider('genericQuestionTypes')]
    public function every_generic_question_type_maps(string $legacy, QuestionType $expected): void
    {
        self::assertSame($expected, QuestionType::fromLegacy($legacy));
    }

    /**
     * `proc_questions.question_type` — the proctored side, 11 values. These are
     * already the unified vocabulary, so each must map to itself.
     */
    #[Test]
    public function every_proctored_question_type_maps_to_itself(): void
    {
        $legacyValues = [
            'multiple_choice_single', 'multiple_choice_multiple', 'true_false',
            'fill_in_blanks', 'short_answer', 'long_answer', 'matching',
            'ordering', 'hotspot', 'numeric', 'file_upload',
        ];

        foreach ($legacyValues as $value) {
            self::assertSame($value, QuestionType::fromLegacy($value)->value);
        }

        // And the unified enum is exactly that set — no more, no less.
        self::assertEqualsCanonicalizing($legacyValues, QuestionType::values());
    }

    #[Test]
    public function an_unknown_question_type_is_rejected_rather_than_defaulted(): void
    {
        // Defaulting here would mis-grade learners silently.
        $this->expectException(\ValueError::class);
        QuestionType::fromLegacy('drag_and_drop');
    }

    #[Test]
    public function the_generic_set_is_a_strict_subset_of_the_unified_set(): void
    {
        foreach (QuestionType::genericSet() as $type) {
            self::assertContains($type, QuestionType::cases());
        }

        self::assertLessThan(
            count(QuestionType::cases()),
            count(QuestionType::genericSet()),
            'The proctored vocabulary must remain strictly richer.',
        );
    }

    /**
     * `assessment_attempts.status` — the generic side, 4 values.
     *
     * @return iterable<string, array{string, AttemptStatus}>
     */
    public static function genericAttemptStatuses(): iterable
    {
        yield 'in_progress' => ['in_progress', AttemptStatus::InProgress];
        yield 'completed'   => ['completed', AttemptStatus::Graded];
        yield 'timed_out'   => ['timed_out', AttemptStatus::AutoSubmitted];
        yield 'cancelled'   => ['cancelled', AttemptStatus::Abandoned];
    }

    #[Test]
    #[DataProvider('genericAttemptStatuses')]
    public function every_generic_attempt_status_maps(string $legacy, AttemptStatus $expected): void
    {
        self::assertSame($expected, AttemptStatus::fromLegacy($legacy));
    }

    #[Test]
    public function every_proctored_attempt_status_maps_to_itself(): void
    {
        $legacyValues = [
            'not_started', 'in_progress', 'submitted',
            'auto_submitted', 'grading', 'graded', 'abandoned',
        ];

        foreach ($legacyValues as $value) {
            self::assertSame($value, AttemptStatus::fromLegacy($value)->value);
        }
    }

    #[Test]
    public function an_abandoned_attempt_does_not_consume_one_of_the_learners_attempts(): void
    {
        self::assertFalse(AttemptStatus::Abandoned->countsAsAttempt());
        self::assertFalse(AttemptStatus::InProgress->countsAsAttempt());

        // A timed-out attempt does count — otherwise a learner could farm
        // unlimited looks at the questions by letting the timer run out.
        self::assertTrue(AttemptStatus::AutoSubmitted->countsAsAttempt());
        self::assertTrue(AttemptStatus::Submitted->countsAsAttempt());
        self::assertTrue(AttemptStatus::Graded->countsAsAttempt());
    }

    #[Test]
    public function both_legacy_difficulty_scales_collapse_onto_one(): void
    {
        // proc_questions used easy/medium/hard...
        self::assertSame(Difficulty::Beginner, Difficulty::fromLegacy('easy'));
        self::assertSame(Difficulty::Intermediate, Difficulty::fromLegacy('medium'));
        self::assertSame(Difficulty::Professional, Difficulty::fromLegacy('hard'));

        // ...while proc_assessments used Beginner/Intermediate/Professional.
        self::assertSame(Difficulty::Beginner, Difficulty::fromLegacy('Beginner'));
        self::assertSame(Difficulty::Intermediate, Difficulty::fromLegacy('Intermediate'));
        self::assertSame(Difficulty::Professional, Difficulty::fromLegacy('Professional'));

        // Ranks must be consistent across both origins.
        self::assertSame(
            Difficulty::fromLegacy('hard')->rank(),
            Difficulty::fromLegacy('Professional')->rank(),
        );
    }

    #[Test]
    public function every_legacy_assessment_status_maps(): void
    {
        foreach (['draft', 'published', 'closed', 'archived'] as $value) {
            self::assertSame($value, AssessmentStatus::fromLegacy($value)->value);
        }

        // The generic side only ever had these two.
        self::assertSame(AssessmentStatus::Draft, AssessmentStatus::fromLegacy('draft'));
        self::assertSame(AssessmentStatus::Published, AssessmentStatus::fromLegacy('published'));
    }

    #[Test]
    public function a_generic_assessment_becomes_an_unproctored_one(): void
    {
        // The whole basis of the merge: 'none' is a real level, so a course
        // quiz is expressible without the advanced module.
        self::assertFalse(ProctoringLevel::None->isEnabled());
        self::assertSame([ProctoringLevel::None], ProctoringLevel::availableTo(false));
        self::assertCount(4, ProctoringLevel::availableTo(true));
    }

    #[Test]
    public function only_supervised_levels_capture_personal_data(): void
    {
        // Drives retention policy and who may view recordings.
        self::assertFalse(ProctoringLevel::None->capturesPersonalData());
        self::assertFalse(ProctoringLevel::Basic->capturesPersonalData());
        self::assertTrue(ProctoringLevel::Standard->capturesPersonalData());
        self::assertTrue(ProctoringLevel::Advanced->capturesPersonalData());
    }

    #[Test]
    public function generic_assessments_default_to_the_modes_they_effectively_used(): void
    {
        // Generic assessments had no selection mode and no passing type; the
        // defaults must reproduce their old behaviour exactly.
        self::assertSame(QuestionSelectionMode::Manual, QuestionSelectionMode::fromLegacy(null));
        self::assertSame(QuestionSelectionMode::Manual, QuestionSelectionMode::fromLegacy(''));
        self::assertSame(PassingType::Percentage, PassingType::fromLegacy(null));
    }

    #[Test]
    public function long_answer_and_file_upload_are_the_only_types_needing_a_human(): void
    {
        foreach (QuestionType::cases() as $type) {
            $expected = in_array($type, [QuestionType::LongAnswer, QuestionType::FileUpload], true);

            self::assertSame(
                !$expected,
                $type->isAutoGradable(),
                sprintf('%s auto-gradability is wrong', $type->value),
            );
        }
    }
}
