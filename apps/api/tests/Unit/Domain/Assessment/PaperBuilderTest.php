<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Assessment;

use Central\Api\Domain\Assessment\Assessment;
use Central\Api\Domain\Assessment\AssessmentSection;
use Central\Api\Domain\Assessment\PaperBuilder;
use Central\Api\Domain\Assessment\Question;
use Central\Api\Domain\Assessment\QuestionSelectionMode;
use Central\Api\Domain\Assessment\QuestionType;
use Central\Api\Domain\Assessment\RandomRule;
use Central\Api\Domain\Assessment\SectionSelection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperBuilder::class)]
final class PaperBuilderTest extends TestCase
{
    private PaperBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new PaperBuilder();
    }

    private static function question(string $id, float $points = 1.0): Question
    {
        return new Question(
            id: $id,
            tenantId: 't1',
            bankId: 'b1',
            type: QuestionType::TrueFalse,
            text: 'Q ' . $id,
            points: $points,
        );
    }

    /**
     * @return array{question: Question, pointsOverride: ?float, sortOrder: int, isRequired: bool, sectionId: ?string}
     */
    private static function attached(
        string $id,
        int $order = 0,
        ?string $sectionId = null,
        ?float $override = null,
        float $points = 1.0,
    ): array {
        return [
            'question'       => self::question($id, $points),
            'pointsOverride' => $override,
            'sortOrder'      => $order,
            'isRequired'     => true,
            'sectionId'      => $sectionId,
        ];
    }

    private static function assessment(
        QuestionSelectionMode $mode = QuestionSelectionMode::Manual,
        ?int $randomCount = null,
    ): Assessment {
        return new Assessment(
            id: 'a1',
            tenantId: 't1',
            title: 'A',
            questionSelection: $mode,
            randomQuestionCount: $randomCount,
        );
    }

    /**
     * @param list<array{question: Question, pointsPossible: float, sectionId: ?string, sortOrder: int}> $paper
     *
     * @return list<string>
     */
    private static function ids(array $paper): array
    {
        return array_map(static fn (array $line): string => $line['question']->id, $paper);
    }

    // --- manual -----------------------------------------------------------

    #[Test]
    public function manual_selection_asks_every_attached_question_in_order(): void
    {
        $paper = $this->builder->build(self::assessment(), 'att-1', [
            self::attached('a', 0),
            self::attached('b', 1),
            self::attached('c', 2),
        ]);

        self::assertSame(['a', 'b', 'c'], self::ids($paper));
    }

    #[Test]
    public function a_points_override_is_carried_onto_the_paper(): void
    {
        $paper = $this->builder->build(self::assessment(), 'att-1', [
            self::attached('a', 0, override: 10.0, points: 1.0),
            self::attached('b', 1, points: 3.0),
        ]);

        self::assertSame(10.0, $paper[0]['pointsPossible']);
        self::assertSame(3.0, $paper[1]['pointsPossible']);
    }

    // --- random -----------------------------------------------------------

    #[Test]
    public function random_selection_draws_the_requested_number(): void
    {
        $attached = [];

        foreach (range(1, 10) as $n) {
            $attached[] = self::attached('q' . $n, $n);
        }

        $paper = $this->builder->build(
            self::assessment(QuestionSelectionMode::Random, 4),
            'att-1',
            $attached,
        );

        self::assertCount(4, $paper);
    }

    #[Test]
    public function the_same_attempt_always_draws_the_same_questions(): void
    {
        // A learner who reloads mid-sitting must not be dealt a fresh hand.
        $attached = [];

        foreach (range(1, 20) as $n) {
            $attached[] = self::attached('q' . $n, $n);
        }

        $assessment = self::assessment(QuestionSelectionMode::Random, 5);

        $first = self::ids($this->builder->build($assessment, 'att-1', $attached));
        $second = self::ids($this->builder->build($assessment, 'att-1', $attached));

        self::assertSame($first, $second);
    }

    #[Test]
    public function different_attempts_draw_different_questions(): void
    {
        $attached = [];

        foreach (range(1, 40) as $n) {
            $attached[] = self::attached('q' . $n, $n);
        }

        $assessment = self::assessment(QuestionSelectionMode::Random, 5);

        // Not guaranteed for any one pair, so compare several: if the seed
        // were being ignored, every attempt would draw an identical paper.
        $papers = [];

        foreach (['att-1', 'att-2', 'att-3', 'att-4'] as $id) {
            $papers[] = implode(',', self::ids($this->builder->build($assessment, $id, $attached)));
        }

        self::assertGreaterThan(1, count(array_unique($papers)));
    }

    #[Test]
    public function a_random_draw_keeps_the_authors_order(): void
    {
        // A random *selection* should not also be a random ordering; that is
        // what shuffleQuestions is for, and it applies later.
        $attached = [];

        foreach (range(1, 10) as $n) {
            $attached[] = self::attached('q' . $n, $n);
        }

        $ids = self::ids($this->builder->build(
            self::assessment(QuestionSelectionMode::Random, 5),
            'att-1',
            $attached,
        ));

        $positions = array_map(static fn (string $id): int => (int) substr($id, 1), $ids);
        $sorted = $positions;
        sort($sorted);

        self::assertSame($sorted, $positions);
    }

    #[Test]
    public function asking_for_more_than_exists_asks_everything(): void
    {
        $paper = $this->builder->build(
            self::assessment(QuestionSelectionMode::Random, 50),
            'att-1',
            [self::attached('a'), self::attached('b')],
        );

        self::assertCount(2, $paper);
    }

    #[Test]
    public function the_drawn_paper_is_numbered_from_zero_without_gaps(): void
    {
        $attached = [];

        foreach (range(1, 10) as $n) {
            $attached[] = self::attached('q' . $n, $n);
        }

        $paper = $this->builder->build(
            self::assessment(QuestionSelectionMode::Random, 3),
            'att-1',
            $attached,
        );

        self::assertSame([0, 1, 2], array_column($paper, 'sortOrder'));
    }

    // --- sections ---------------------------------------------------------

    #[Test]
    public function sections_are_asked_in_their_own_order(): void
    {
        $sections = [
            new AssessmentSection(id: 's2', tenantId: 't1', assessmentId: 'a1', title: 'Second', sortOrder: 1),
            new AssessmentSection(id: 's1', tenantId: 't1', assessmentId: 'a1', title: 'First', sortOrder: 0),
        ];

        $paper = $this->builder->build(self::assessment(), 'att-1', [
            self::attached('b1', 0, 's2'),
            self::attached('a1q', 0, 's1'),
        ], $sections);

        self::assertSame(['a1q', 'b1'], self::ids($paper));
    }

    #[Test]
    public function questions_in_no_section_come_last_rather_than_disappearing(): void
    {
        // An author who sections half their paper should not find the rest
        // silently dropped.
        $sections = [
            new AssessmentSection(id: 's1', tenantId: 't1', assessmentId: 'a1', title: 'One', sortOrder: 0),
        ];

        $paper = $this->builder->build(self::assessment(), 'att-1', [
            self::attached('loose', 0),
            self::attached('filed', 1, 's1'),
        ], $sections);

        self::assertSame(['filed', 'loose'], self::ids($paper));
    }

    #[Test]
    public function a_section_may_sample_its_own_questions(): void
    {
        // "Two from each topic" without any rule at all.
        $sections = [
            new AssessmentSection(
                id: 's1',
                tenantId: 't1',
                assessmentId: 'a1',
                title: 'Topic',
                selectionType: SectionSelection::Random,
                randomCount: 2,
                sortOrder: 0,
            ),
        ];

        $attached = [];

        foreach (range(1, 6) as $n) {
            $attached[] = self::attached('q' . $n, $n, 's1');
        }

        $paper = $this->builder->build(self::assessment(), 'att-1', $attached, $sections);

        self::assertCount(2, $paper);
        self::assertSame(['s1', 's1'], array_column($paper, 'sectionId'));
    }

    #[Test]
    public function two_sampling_sections_do_not_draw_the_same_positions(): void
    {
        // Each section is seeded separately; sharing one seed would make every
        // section pick the same slots from its list.
        $sections = [];
        $attached = [];

        foreach (['s1', 's2'] as $index => $sectionId) {
            $sections[] = new AssessmentSection(
                id: $sectionId,
                tenantId: 't1',
                assessmentId: 'a1',
                title: 'S',
                selectionType: SectionSelection::Random,
                randomCount: 2,
                sortOrder: $index,
            );

            foreach (range(1, 6) as $n) {
                $attached[] = self::attached($sectionId . '-q' . $n, $n, $sectionId);
            }
        }

        $paper = $this->builder->build(self::assessment(), 'att-1', $attached, $sections);

        $first = array_map(
            static fn (string $id): string => substr($id, 3),
            array_slice(self::ids($paper), 0, 2),
        );
        $second = array_map(
            static fn (string $id): string => substr($id, 3),
            array_slice(self::ids($paper), 2, 2),
        );

        self::assertCount(4, $paper);
        self::assertNotSame($first, $second);
    }

    // --- pool -------------------------------------------------------------

    #[Test]
    public function pool_selection_draws_from_the_rules_not_the_attached_list(): void
    {
        $rule = new RandomRule(
            id: 'r1',
            tenantId: 't1',
            assessmentId: 'a1',
            bankId: 'b1',
            questionCount: 2,
            pointsPerQuestion: 5.0,
        );

        $paper = $this->builder->build(
            self::assessment(QuestionSelectionMode::Pool),
            'att-1',
            // Deliberately non-empty: pool mode must ignore it.
            [self::attached('attached-and-ignored')],
            [],
            [$rule],
            ['r1' => [self::question('p1'), self::question('p2'), self::question('p3')]],
        );

        self::assertCount(2, $paper);
        self::assertNotContains('attached-and-ignored', self::ids($paper));
        self::assertSame(5.0, $paper[0]['pointsPossible']);
    }

    #[Test]
    public function each_rule_contributes_its_own_count_and_worth(): void
    {
        $rules = [
            new RandomRule(
                id: 'r1',
                tenantId: 't1',
                assessmentId: 'a1',
                bankId: 'b1',
                questionCount: 2,
                pointsPerQuestion: 1.0,
                sortOrder: 0,
            ),
            new RandomRule(
                id: 'r2',
                tenantId: 't1',
                assessmentId: 'a1',
                bankId: 'b2',
                questionCount: 1,
                pointsPerQuestion: 10.0,
                sortOrder: 1,
            ),
        ];

        $paper = $this->builder->build(
            self::assessment(QuestionSelectionMode::Pool),
            'att-1',
            [],
            [],
            $rules,
            [
                'r1' => [self::question('a1q'), self::question('a2'), self::question('a3')],
                'r2' => [self::question('b1q'), self::question('b2')],
            ],
        );

        self::assertCount(3, $paper);
        self::assertSame(1.0, $paper[0]['pointsPossible']);
        self::assertSame(10.0, $paper[2]['pointsPossible']);
    }

    #[Test]
    public function overlapping_rules_never_ask_the_same_question_twice(): void
    {
        // Easy to write by accident, and asking a question twice doubles its
        // weight silently.
        $shared = self::question('shared');

        $rules = [
            new RandomRule(
                id: 'r1',
                tenantId: 't1',
                assessmentId: 'a1',
                bankId: 'b1',
                questionCount: 1,
                sortOrder: 0,
            ),
            new RandomRule(
                id: 'r2',
                tenantId: 't1',
                assessmentId: 'a1',
                bankId: 'b1',
                questionCount: 1,
                sortOrder: 1,
            ),
        ];

        $paper = $this->builder->build(
            self::assessment(QuestionSelectionMode::Pool),
            'att-1',
            [],
            [],
            $rules,
            ['r1' => [$shared], 'r2' => [$shared, self::question('other')]],
        );

        self::assertSame(['shared', 'other'], self::ids($paper));
    }

    #[Test]
    public function a_rule_that_cannot_be_filled_shortens_the_paper_rather_than_repeating(): void
    {
        // Publishing refuses this case; if it somehow reaches the draw, a
        // short paper is honest and a padded one is not.
        $rule = new RandomRule(
            id: 'r1',
            tenantId: 't1',
            assessmentId: 'a1',
            bankId: 'b1',
            questionCount: 5,
        );

        $paper = $this->builder->build(
            self::assessment(QuestionSelectionMode::Pool),
            'att-1',
            [],
            [],
            [$rule],
            ['r1' => [self::question('only')]],
        );

        self::assertSame(['only'], self::ids($paper));
    }

    #[Test]
    public function a_pool_assessment_with_no_rules_produces_nothing(): void
    {
        $paper = $this->builder->build(
            self::assessment(QuestionSelectionMode::Pool),
            'att-1',
            [self::attached('ignored')],
        );

        self::assertSame([], $paper);
    }

    #[Test]
    public function the_global_random_generator_is_left_alone(): void
    {
        // The builder seeds mt_rand to make the draw repeatable. If it left
        // the seed set, everything else in the process would inherit a
        // predictable sequence — including token generation.
        $attached = [];

        foreach (range(1, 10) as $n) {
            $attached[] = self::attached('q' . $n, $n);
        }

        $this->builder->build(self::assessment(QuestionSelectionMode::Random, 3), 'att-1', $attached);
        $a = mt_rand();

        $this->builder->build(self::assessment(QuestionSelectionMode::Random, 3), 'att-1', $attached);
        $b = mt_rand();

        self::assertNotSame($a, $b);
    }
}
