<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Assessment;

use Central\Api\Domain\Assessment\GradeResult;
use Central\Api\Domain\Assessment\Grader;
use Central\Api\Domain\Assessment\Question;
use Central\Api\Domain\Assessment\QuestionOption;
use Central\Api\Domain\Assessment\QuestionType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Grader::class)]
#[CoversClass(GradeResult::class)]
final class GraderTest extends TestCase
{
    private Grader $grader;

    protected function setUp(): void
    {
        $this->grader = new Grader();
    }

    /**
     * @param list<array{string, bool, int, string|null}> $options id, correct, order, matchText
     */
    private static function question(QuestionType $type, array $options = [], mixed ...$extra): Question
    {
        return new Question(
            id: 'q1',
            tenantId: 't1',
            bankId: 'b1',
            type: $type,
            text: 'Q?',
            numericAnswer: $extra['numericAnswer'] ?? null,
            numericTolerance: $extra['numericTolerance'] ?? null,
            modelAnswer: $extra['modelAnswer'] ?? null,
            options: array_map(
                static fn (array $o): QuestionOption => new QuestionOption(
                    id: $o[0],
                    tenantId: 't1',
                    questionId: 'q1',
                    text: $o[4] ?? $o[0],
                    isCorrect: $o[1],
                    sortOrder: $o[2],
                    matchText: $o[3],
                ),
                $options,
            ),
        );
    }

    // --- single choice ----------------------------------------------------

    #[Test]
    public function the_right_single_choice_earns_full_marks(): void
    {
        $q = self::question(QuestionType::MultipleChoiceSingle, [
            ['a', true, 0, null],
            ['b', false, 1, null],
        ]);

        $result = $this->grader->grade($q, ['optionIds' => ['a']], 5.0);

        self::assertSame(5.0, $result->pointsEarned);
        self::assertTrue($result->isCorrect);
        self::assertFalse($result->needsHumanGrading);
    }

    #[Test]
    public function the_wrong_single_choice_earns_nothing(): void
    {
        $q = self::question(QuestionType::MultipleChoiceSingle, [
            ['a', true, 0, null],
            ['b', false, 1, null],
        ]);

        self::assertSame(0.0, $this->grader->grade($q, ['optionIds' => ['b']], 5.0)->pointsEarned);
    }

    #[Test]
    public function selecting_two_options_for_a_single_choice_question_is_not_a_partial_answer(): void
    {
        // Including the right one among two selections is not "half right";
        // it is not an answer to the question that was asked.
        $q = self::question(QuestionType::MultipleChoiceSingle, [
            ['a', true, 0, null],
            ['b', false, 1, null],
        ]);

        self::assertSame(0.0, $this->grader->grade($q, ['optionIds' => ['a', 'b']], 5.0)->pointsEarned);
    }

    #[Test]
    public function an_empty_or_malformed_answer_earns_nothing_rather_than_failing(): void
    {
        $q = self::question(QuestionType::MultipleChoiceSingle, [['a', true, 0, null]]);

        foreach ([[], ['optionIds' => []], ['optionIds' => 'a'], ['nonsense' => 1]] as $answer) {
            self::assertSame(0.0, $this->grader->grade($q, $answer, 5.0)->pointsEarned);
        }
    }

    #[Test]
    public function an_unknown_option_id_earns_nothing(): void
    {
        $q = self::question(QuestionType::MultipleChoiceSingle, [['a', true, 0, null]]);

        self::assertSame(0.0, $this->grader->grade($q, ['optionIds' => ['not-an-option']], 5.0)->pointsEarned);
    }

    // --- multiple choice --------------------------------------------------

    #[Test]
    public function every_right_option_and_no_wrong_ones_earns_full_marks(): void
    {
        $q = self::question(QuestionType::MultipleChoiceMultiple, [
            ['a', true, 0, null],
            ['b', true, 1, null],
            ['c', false, 2, null],
        ]);

        $result = $this->grader->grade($q, ['optionIds' => ['a', 'b']], 6.0);

        self::assertSame(6.0, $result->pointsEarned);
        self::assertTrue($result->isCorrect);
    }

    #[Test]
    public function finding_half_the_right_options_earns_half(): void
    {
        $q = self::question(QuestionType::MultipleChoiceMultiple, [
            ['a', true, 0, null],
            ['b', true, 1, null],
            ['c', false, 2, null],
        ]);

        $result = $this->grader->grade($q, ['optionIds' => ['a']], 6.0);

        self::assertSame(3.0, $result->pointsEarned);
        self::assertFalse($result->isCorrect);
    }

    #[Test]
    public function selecting_everything_does_not_earn_full_marks(): void
    {
        // Without the penalty for wrong selections, "tick every box" scores
        // full marks on every multiple-answer question ever written.
        $q = self::question(QuestionType::MultipleChoiceMultiple, [
            ['a', true, 0, null],
            ['b', true, 1, null],
            ['c', false, 2, null],
            ['d', false, 3, null],
        ]);

        $result = $this->grader->grade($q, ['optionIds' => ['a', 'b', 'c', 'd']], 8.0);

        self::assertSame(0.0, $result->pointsEarned);
        self::assertFalse($result->isCorrect);
    }

    #[Test]
    public function credit_never_goes_below_zero(): void
    {
        // More wrong selections than right answers must not produce a negative
        // score that eats into other questions.
        $q = self::question(QuestionType::MultipleChoiceMultiple, [
            ['a', true, 0, null],
            ['b', false, 1, null],
            ['c', false, 2, null],
            ['d', false, 3, null],
        ]);

        self::assertSame(0.0, $this->grader->grade($q, ['optionIds' => ['b', 'c', 'd']], 8.0)->pointsEarned);
    }

    #[Test]
    public function a_question_with_no_correct_option_earns_nothing_rather_than_dividing_by_zero(): void
    {
        $q = self::question(QuestionType::MultipleChoiceMultiple, [
            ['a', false, 0, null],
            ['b', false, 1, null],
        ]);

        self::assertSame(0.0, $this->grader->grade($q, ['optionIds' => ['a']], 4.0)->pointsEarned);
    }

    // --- true / false -----------------------------------------------------

    #[Test]
    public function true_false_behaves_as_a_single_choice(): void
    {
        $q = self::question(QuestionType::TrueFalse, [
            ['t', true, 0, null],
            ['f', false, 1, null],
        ]);

        self::assertSame(2.0, $this->grader->grade($q, ['optionIds' => ['t']], 2.0)->pointsEarned);
        self::assertSame(0.0, $this->grader->grade($q, ['optionIds' => ['f']], 2.0)->pointsEarned);
    }

    // --- ordering ---------------------------------------------------------

    #[Test]
    public function the_right_order_earns_full_marks(): void
    {
        $q = self::question(QuestionType::Ordering, [
            ['a', false, 0, null],
            ['b', false, 1, null],
            ['c', false, 2, null],
        ]);

        $result = $this->grader->grade($q, ['optionIds' => ['a', 'b', 'c']], 9.0);

        self::assertSame(9.0, $result->pointsEarned);
        self::assertTrue($result->isCorrect);
    }

    #[Test]
    public function ordering_gives_credit_per_item_left_in_place(): void
    {
        $q = self::question(QuestionType::Ordering, [
            ['a', false, 0, null],
            ['b', false, 1, null],
            ['c', false, 2, null],
        ]);

        // 'a' is right, 'b' and 'c' are swapped.
        $result = $this->grader->grade($q, ['optionIds' => ['a', 'c', 'b']], 9.0);

        self::assertSame(3.0, $result->pointsEarned);
        self::assertFalse($result->isCorrect);
    }

    #[Test]
    public function a_short_ordering_answer_earns_nothing(): void
    {
        // Submitting two of three items is not two-thirds of an ordering.
        $q = self::question(QuestionType::Ordering, [
            ['a', false, 0, null],
            ['b', false, 1, null],
            ['c', false, 2, null],
        ]);

        self::assertSame(0.0, $this->grader->grade($q, ['optionIds' => ['a', 'b']], 9.0)->pointsEarned);
    }

    #[Test]
    public function the_expected_order_comes_from_sort_order_not_row_order(): void
    {
        $q = self::question(QuestionType::Ordering, [
            ['c', false, 2, null],
            ['a', false, 0, null],
            ['b', false, 1, null],
        ]);

        self::assertSame(9.0, $this->grader->grade($q, ['optionIds' => ['a', 'b', 'c']], 9.0)->pointsEarned);
    }

    // --- matching ---------------------------------------------------------

    #[Test]
    public function matching_gives_credit_per_correct_pair(): void
    {
        $q = self::question(QuestionType::Matching, [
            ['a', false, 0, 'Alpha'],
            ['b', false, 1, 'Beta'],
        ]);

        self::assertSame(
            4.0,
            $this->grader->grade($q, ['pairs' => ['a' => 'Alpha', 'b' => 'Beta']], 4.0)->pointsEarned,
        );
        self::assertSame(
            2.0,
            $this->grader->grade($q, ['pairs' => ['a' => 'Alpha', 'b' => 'Wrong']], 4.0)->pointsEarned,
        );
    }

    #[Test]
    public function matching_ignores_case_and_surrounding_space(): void
    {
        $q = self::question(QuestionType::Matching, [['a', false, 0, 'Alpha']]);

        self::assertSame(4.0, $this->grader->grade($q, ['pairs' => ['a' => '  alpha ']], 4.0)->pointsEarned);
    }

    // --- fill in the blanks -----------------------------------------------

    #[Test]
    public function each_blank_is_marked_separately(): void
    {
        $q = self::question(QuestionType::FillInBlanks, [
            ['o1', true, 0, null, 'red'],
            ['o2', true, 1, null, 'blue'],
        ]);

        self::assertSame(4.0, $this->grader->grade($q, ['texts' => ['red', 'blue']], 4.0)->pointsEarned);
        self::assertSame(2.0, $this->grader->grade($q, ['texts' => ['red', 'green']], 4.0)->pointsEarned);
        self::assertSame(0.0, $this->grader->grade($q, ['texts' => ['x', 'y']], 4.0)->pointsEarned);
    }

    #[Test]
    public function an_author_can_offer_alternatives_for_one_blank(): void
    {
        // Two options sharing a sortOrder are two acceptable answers to the
        // same blank, not two blanks.
        $q = self::question(QuestionType::FillInBlanks, [
            ['o1', true, 0, null, 'colour'],
            ['o2', true, 0, null, 'color'],
        ]);

        self::assertSame(3.0, $this->grader->grade($q, ['texts' => ['color']], 3.0)->pointsEarned);
        self::assertSame(3.0, $this->grader->grade($q, ['texts' => ['colour']], 3.0)->pointsEarned);
        self::assertSame(0.0, $this->grader->grade($q, ['texts' => ['colur']], 3.0)->pointsEarned);
    }

    #[Test]
    public function a_missing_blank_costs_only_that_blank(): void
    {
        $q = self::question(QuestionType::FillInBlanks, [
            ['o1', true, 0, null, 'red'],
            ['o2', true, 1, null, 'blue'],
        ]);

        self::assertSame(2.0, $this->grader->grade($q, ['texts' => ['red']], 4.0)->pointsEarned);
    }

    // --- short answer -----------------------------------------------------

    #[Test]
    public function a_short_answer_matches_the_model_answer(): void
    {
        $q = self::question(QuestionType::ShortAnswer, [], modelAnswer: 'Photosynthesis');

        self::assertSame(3.0, $this->grader->grade($q, ['text' => 'photosynthesis'], 3.0)->pointsEarned);
        self::assertSame(3.0, $this->grader->grade($q, ['text' => '  PHOTOSYNTHESIS  '], 3.0)->pointsEarned);
        self::assertSame(0.0, $this->grader->grade($q, ['text' => 'respiration'], 3.0)->pointsEarned);
    }

    #[Test]
    public function a_near_miss_is_marked_wrong_rather_than_fuzzily_right(): void
    {
        // The legacy code used similar_text() for this. A wrong answer marked
        // right issues a certificate in error; a near-miss marked wrong is a
        // complaint. These are not the same cost.
        $q = self::question(QuestionType::ShortAnswer, [], modelAnswer: 'Photosynthesis');

        self::assertSame(0.0, $this->grader->grade($q, ['text' => 'Photosynthesi'], 3.0)->pointsEarned);
        self::assertSame(0.0, $this->grader->grade($q, ['text' => 'Photo synthesis'], 3.0)->pointsEarned);
    }

    #[Test]
    public function internal_whitespace_differences_are_forgiven(): void
    {
        $q = self::question(QuestionType::ShortAnswer, [], modelAnswer: 'New York');

        self::assertSame(3.0, $this->grader->grade($q, ['text' => 'New   York'], 3.0)->pointsEarned);
    }

    #[Test]
    public function a_short_answer_can_also_match_any_correct_option(): void
    {
        $q = self::question(QuestionType::ShortAnswer, [
            ['o1', true, 0, null, 'H2O'],
            ['o2', true, 1, null, 'water'],
            ['o3', false, 2, null, 'ice'],
        ]);

        self::assertSame(2.0, $this->grader->grade($q, ['text' => 'water'], 2.0)->pointsEarned);
        self::assertSame(2.0, $this->grader->grade($q, ['text' => 'h2o'], 2.0)->pointsEarned);
        self::assertSame(0.0, $this->grader->grade($q, ['text' => 'ice'], 2.0)->pointsEarned);
    }

    #[Test]
    public function a_blank_short_answer_earns_nothing(): void
    {
        $q = self::question(QuestionType::ShortAnswer, [], modelAnswer: 'x');

        self::assertSame(0.0, $this->grader->grade($q, ['text' => '   '], 3.0)->pointsEarned);
        self::assertSame(0.0, $this->grader->grade($q, [], 3.0)->pointsEarned);
    }

    // --- numeric ----------------------------------------------------------

    #[Test]
    public function a_numeric_answer_within_tolerance_is_correct(): void
    {
        $q = self::question(QuestionType::Numeric, [], numericAnswer: 3.14, numericTolerance: 0.01);

        self::assertSame(5.0, $this->grader->grade($q, ['value' => 3.15], 5.0)->pointsEarned);
        self::assertSame(5.0, $this->grader->grade($q, ['value' => 3.13], 5.0)->pointsEarned);
        self::assertSame(0.0, $this->grader->grade($q, ['value' => 3.2], 5.0)->pointsEarned);
    }

    #[Test]
    public function a_tolerance_of_zero_means_exact_not_unset(): void
    {
        $q = self::question(QuestionType::Numeric, [], numericAnswer: 10.0, numericTolerance: 0.0);

        self::assertSame(5.0, $this->grader->grade($q, ['value' => 10], 5.0)->pointsEarned);
        self::assertSame(0.0, $this->grader->grade($q, ['value' => 10.0001], 5.0)->pointsEarned);
    }

    #[Test]
    public function a_numeric_answer_sent_as_a_string_still_grades(): void
    {
        // JSON from a form field arrives as a string more often than not.
        $q = self::question(QuestionType::Numeric, [], numericAnswer: 42.0);

        self::assertSame(5.0, $this->grader->grade($q, ['value' => '42'], 5.0)->pointsEarned);
    }

    #[Test]
    public function a_non_numeric_answer_earns_nothing(): void
    {
        $q = self::question(QuestionType::Numeric, [], numericAnswer: 42.0);

        self::assertSame(0.0, $this->grader->grade($q, ['value' => 'forty-two'], 5.0)->pointsEarned);
    }

    // --- manual grading ---------------------------------------------------

    #[Test]
    public function an_essay_is_handed_to_a_human_rather_than_marked(): void
    {
        $q = self::question(QuestionType::LongAnswer);

        $result = $this->grader->grade($q, ['text' => 'A long and thoughtful essay.'], 10.0);

        self::assertTrue($result->needsHumanGrading);
        self::assertSame(0.0, $result->pointsEarned);
        self::assertSame(10.0, $result->pointsPossible);
        // Not correct while it waits — nothing should read an unmarked essay
        // as a pass.
        self::assertFalse($result->isCorrect);
    }

    #[Test]
    public function a_file_upload_is_handed_to_a_human(): void
    {
        $result = $this->grader->grade(self::question(QuestionType::FileUpload), ['fileUrl' => 'x'], 10.0);

        self::assertTrue($result->needsHumanGrading);
    }

    #[Test]
    public function every_question_type_is_handled(): void
    {
        // A new type added to the enum without a grading rule would otherwise
        // surface as an error at marking time, on someone's real attempt.
        foreach (QuestionType::cases() as $type) {
            $result = $this->grader->grade(self::question($type), [], 1.0);

            self::assertSame(1.0, $result->pointsPossible, $type->value);
            self::assertGreaterThanOrEqual(0.0, $result->pointsEarned, $type->value);
        }
    }

    #[Test]
    public function points_earned_never_exceeds_points_possible(): void
    {
        foreach (QuestionType::cases() as $type) {
            $q = self::question($type, [
                ['a', true, 0, 'Alpha', 'a'],
                ['b', true, 1, 'Beta', 'b'],
            ], modelAnswer: 'a', numericAnswer: 1.0);

            $result = $this->grader->grade($q, [
                'optionIds' => ['a', 'b'],
                'pairs'     => ['a' => 'Alpha', 'b' => 'Beta'],
                'texts'     => ['a', 'b'],
                'text'      => 'a',
                'value'     => 1.0,
            ], 7.0);

            self::assertLessThanOrEqual(7.0, $result->pointsEarned, $type->value);
        }
    }
}
