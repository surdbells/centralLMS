<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Assessment\Assessment;
use Central\Api\Domain\Assessment\AssessmentStatus;
use Central\Api\Domain\Assessment\Attempt;
use Central\Api\Domain\Assessment\Grader;
use Central\Api\Domain\Assessment\PaperBuilder;
use Central\Api\Domain\Assessment\PassingType;
use Central\Api\Domain\Assessment\Question;
use Central\Api\Domain\Assessment\QuestionBank;
use Central\Api\Domain\Assessment\QuestionOption;
use Central\Api\Domain\Assessment\QuestionType;
use Central\Api\Infrastructure\Persistence\DbalAssessmentRepository;
use Central\Api\Infrastructure\Persistence\DbalAttemptRepository;
use Central\Api\Infrastructure\Persistence\DbalQuestionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Assessments, attempts and marking against a real PostgreSQL.
 *
 * The submission path is why this file exists: totals are computed from the
 * stored answers inside the transaction that closes the attempt, and the
 * check constraints refuse any row where the status and the result disagree.
 *
 * Skipped when the database is unreachable, so a checkout without the Docker
 * stack still gets a green suite.
 */
#[CoversClass(DbalAssessmentRepository::class)]
#[CoversClass(DbalQuestionRepository::class)]
#[CoversClass(DbalAttemptRepository::class)]
final class DbalAssessmentRepositoryTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalQuestionRepository $questions;
    private DbalAssessmentRepository $assessments;
    private DbalAttemptRepository $attempts;
    private Grader $grader;
    private PaperBuilder $papers;

    private string $tenantA;
    private string $tenantB;
    private string $learner;
    private string $marker;
    private QuestionBank $bank;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable — start it with: pnpm up');
        }

        $this->questions = new DbalQuestionRepository(self::$connection);
        $this->assessments = new DbalAssessmentRepository(self::$connection, $this->questions);
        $this->attempts = new DbalAttemptRepository(self::$connection, $this->questions);
        $this->grader = new Grader();
        $this->papers = new PaperBuilder();

        $this->tenantA = $this->createTenant('asm-a');
        $this->tenantB = $this->createTenant('asm-b');
        $this->learner = $this->createUser($this->tenantA, 'learner');
        $this->marker = $this->createUser($this->tenantA, 'marker');

        $this->bank = $this->questions->createBank(
            new QuestionBank(id: '', tenantId: $this->tenantA, name: 'Bank ' . substr(Uuid::uuid4()->toString(), 0, 8)),
        );
    }

    protected function tearDown(): void
    {
        if (self::$connection === null) {
            return;
        }

        foreach ([$this->tenantA ?? null, $this->tenantB ?? null] as $tenantId) {
            if ($tenantId === null) {
                continue;
            }

            // users.tenant_id does not cascade from tenants; noted in
            // PROGRESS.md as an inconsistency awaiting a decision.
            self::$connection->executeStatement('DELETE FROM users WHERE tenant_id = :id', ['id' => $tenantId]);
            self::$connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $tenantId]);
        }
    }

    // --- banks and questions ---------------------------------------------

    #[Test]
    public function a_question_round_trips_with_its_options(): void
    {
        $question = $this->multipleChoice('Capital of France?', ['Paris' => true, 'Lyon' => false]);

        $read = $this->questions->findQuestion($this->tenantA, $question->id);

        self::assertNotNull($read);
        self::assertSame('Capital of France?', $read->text);
        self::assertCount(2, $read->options);
        self::assertSame('Paris', $read->options[0]->text);
        self::assertTrue($read->options[0]->isCorrect);
    }

    #[Test]
    public function updating_a_question_replaces_its_options_rather_than_stacking_them(): void
    {
        $question = $this->multipleChoice('Q?', ['a' => true, 'b' => false]);

        $this->questions->updateQuestion($question->withOptions([
            new QuestionOption(id: '', tenantId: $this->tenantA, questionId: $question->id, text: 'c', isCorrect: true),
        ]));

        $read = $this->questions->findQuestion($this->tenantA, $question->id);

        self::assertNotNull($read);
        self::assertCount(1, $read->options);
        self::assertSame('c', $read->options[0]->text);
    }

    #[Test]
    public function another_tenant_cannot_read_the_bank_or_its_questions(): void
    {
        $question = $this->multipleChoice('Secret?', ['a' => true, 'b' => false]);

        self::assertNull($this->questions->findBank($this->tenantB, $this->bank->id));
        self::assertNull($this->questions->findQuestion($this->tenantB, $question->id));
        self::assertSame(
            0,
            $this->questions->paginateQuestions($this->tenantB, $this->bank->id, 1, 50)['total'],
        );
    }

    #[Test]
    public function deleting_a_bank_takes_its_questions_with_it(): void
    {
        $question = $this->multipleChoice('Q?', ['a' => true, 'b' => false]);

        self::assertTrue($this->questions->softDeleteBank($this->tenantA, $this->bank->id));
        self::assertNull($this->questions->findBank($this->tenantA, $this->bank->id));
        self::assertNull($this->questions->findQuestion($this->tenantA, $question->id));
    }

    #[Test]
    public function bank_names_are_unique_within_a_tenant_but_not_across_them(): void
    {
        self::assertTrue($this->questions->bankHasName($this->tenantA, $this->bank->name));
        // Case-insensitively.
        self::assertTrue($this->questions->bankHasName($this->tenantA, strtoupper($this->bank->name)));
        // ...but the bank does not clash with itself when renamed.
        self::assertFalse($this->questions->bankHasName($this->tenantA, $this->bank->name, $this->bank->id));
        self::assertFalse($this->questions->bankHasName($this->tenantB, $this->bank->name));
    }

    // --- assembling an assessment -----------------------------------------

    #[Test]
    public function the_question_count_and_total_are_computed_not_stored(): void
    {
        // The legacy total_questions disagreed with reality in 16 of 19 rows.
        $assessment = $this->assessment();

        self::assertSame(0, $assessment->questionCount);
        self::assertSame(0.0, $assessment->totalPoints);

        $this->attach($assessment, $this->multipleChoice('One?', ['a' => true, 'b' => false]), 5.0);
        $this->attach($assessment, $this->multipleChoice('Two?', ['a' => true, 'b' => false]), 3.0);

        $reread = $this->assessments->find($this->tenantA, $assessment->id);

        self::assertNotNull($reread);
        self::assertSame(2, $reread->questionCount);
        self::assertSame(8.0, $reread->totalPoints);
    }

    #[Test]
    public function a_points_override_replaces_the_banks_value_without_changing_it(): void
    {
        $assessment = $this->assessment();
        $question = $this->multipleChoice('Q?', ['a' => true, 'b' => false], points: 1.0);

        $this->attach($assessment, $question, 10.0);

        $rows = $this->assessments->questionsFor($this->tenantA, $assessment->id);

        self::assertSame(10.0, $rows[0]['pointsOverride']);
        // The bank's own value is untouched, so the same question is worth 1
        // in a practice quiz and 10 in the final.
        self::assertSame(1.0, $rows[0]['question']->points);
        self::assertSame(10.0, $rows[0]['question']->pointsIn($rows[0]['pointsOverride']));
    }

    #[Test]
    public function the_same_question_cannot_be_attached_twice(): void
    {
        $assessment = $this->assessment();
        $question = $this->multipleChoice('Q?', ['a' => true, 'b' => false]);

        self::assertTrue($this->attach($assessment, $question));
        // Asking the same question twice is always a mistake, and it would
        // double its weight silently.
        self::assertFalse($this->attach($assessment, $question));
        self::assertSame(1, $this->assessments->find($this->tenantA, $assessment->id)?->questionCount);
    }

    #[Test]
    public function attaching_an_essay_marks_the_assessment_as_needing_a_marker(): void
    {
        $assessment = $this->assessment();

        self::assertFalse($assessment->requiresManualGrading);

        $essay = $this->questions->createQuestion(new Question(
            id: '',
            tenantId: $this->tenantA,
            bankId: $this->bank->id,
            type: QuestionType::LongAnswer,
            text: 'Discuss.',
            points: 10.0,
        ));

        $this->attach($assessment, $essay);

        $withEssay = $this->assessments->find($this->tenantA, $assessment->id);
        self::assertNotNull($withEssay);
        self::assertTrue($withEssay->requiresManualGrading);

        // ...and removing it clears the flag again.
        $this->assessments->detachQuestion($this->tenantA, $assessment->id, $essay->id);

        $without = $this->assessments->find($this->tenantA, $assessment->id);
        self::assertNotNull($without);
        self::assertFalse($without->requiresManualGrading);
    }

    #[Test]
    public function reordering_applies_the_whole_order_at_once(): void
    {
        $assessment = $this->assessment();
        $a = $this->multipleChoice('A?', ['a' => true, 'b' => false]);
        $b = $this->multipleChoice('B?', ['a' => true, 'b' => false]);
        $c = $this->multipleChoice('C?', ['a' => true, 'b' => false]);

        foreach ([$a, $b, $c] as $q) {
            $this->attach($assessment, $q);
        }

        $this->assessments->reorderQuestions($this->tenantA, $assessment->id, [$c->id, $a->id, $b->id]);

        self::assertSame(
            ['C?', 'A?', 'B?'],
            array_map(
                static fn (array $row): string => $row['question']->text,
                $this->assessments->questionsFor($this->tenantA, $assessment->id),
            ),
        );
    }

    #[Test]
    public function a_question_deleted_from_its_bank_drops_out_rather_than_breaking_the_assessment(): void
    {
        $assessment = $this->assessment();
        $kept = $this->multipleChoice('Kept?', ['a' => true, 'b' => false]);
        $gone = $this->multipleChoice('Gone?', ['a' => true, 'b' => false]);

        $this->attach($assessment, $kept);
        $this->attach($assessment, $gone);

        $this->questions->softDeleteQuestion($this->tenantA, $gone->id);

        $rows = $this->assessments->questionsFor($this->tenantA, $assessment->id);

        self::assertCount(1, $rows);
        self::assertSame('Kept?', $rows[0]['question']->text);
    }

    // --- attempts ---------------------------------------------------------

    #[Test]
    public function attempt_numbers_increase_and_only_one_may_be_open(): void
    {
        $assessment = $this->publishedWithOneQuestion();

        $first = $this->attempts->start($this->tenantA, $assessment->id, $this->learner, null);
        self::assertSame(1, $first->attemptNumber);

        // A second open attempt is refused by the partial unique index.
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->attempts->start($this->tenantA, $assessment->id, $this->learner, null);
    }

    #[Test]
    public function a_second_attempt_may_start_once_the_first_is_submitted(): void
    {
        $assessment = $this->publishedWithOneQuestion();

        $first = $this->attempts->start($this->tenantA, $assessment->id, $this->learner, null);
        $this->attempts->submit($this->tenantA, $first, $assessment, autoSubmitted: false);

        $second = $this->attempts->start($this->tenantA, $assessment->id, $this->learner, null);

        self::assertSame(2, $second->attemptNumber);
    }

    #[Test]
    public function saving_an_answer_twice_replaces_it_and_accumulates_the_time(): void
    {
        $assessment = $this->publishedWithOneQuestion();
        $question = $this->assessments->questionsFor($this->tenantA, $assessment->id)[0]['question'];
        $attempt = $this->begin($assessment);

        $wrong = $question->options[1]->id;
        $right = $question->options[0]->id;

        $this->save($attempt, $question, ['optionIds' => [$wrong]], 30);
        $this->save($attempt, $question, ['optionIds' => [$right]], 45);

        $answers = $this->attempts->answersFor($this->tenantA, $attempt->id);

        self::assertCount(1, $answers);
        self::assertTrue($answers[$question->id]['isCorrect']);
        // Both visits counted.
        self::assertSame(75, $answers[$question->id]['timeSpentSeconds']);
    }

    #[Test]
    public function submitting_totals_the_answers_and_stamps_the_result(): void
    {
        $assessment = $this->assessment(passingScore: 50.0);
        $a = $this->multipleChoice('A?', ['right' => true, 'wrong' => false]);
        $b = $this->multipleChoice('B?', ['right' => true, 'wrong' => false]);
        $this->attach($assessment, $a, 5.0);
        $this->attach($assessment, $b, 5.0);
        $assessment = $this->publish($assessment);

        $attempt = $this->begin($assessment);

        $this->save($attempt, $this->reload($a), ['optionIds' => [$this->correctOption($a)]], 10);
        $this->save($attempt, $this->reload($b), ['optionIds' => [$this->wrongOption($b)]], 10);

        $submitted = $this->attempts->submit($this->tenantA, $attempt, $assessment, autoSubmitted: false);

        self::assertSame(5.0, $submitted->rawScore);
        self::assertSame(10.0, $submitted->maxScore);
        self::assertSame(50.0, $submitted->percentage);
        self::assertTrue($submitted->passed);
        self::assertNotNull($submitted->gradedAt);
    }

    #[Test]
    public function an_unanswered_question_costs_its_marks(): void
    {
        // The denominator is what the assessment is worth, not what was
        // answered — otherwise skipping everything but one easy question
        // scores 100%.
        $assessment = $this->assessment(passingScore: 50.0);
        $a = $this->multipleChoice('A?', ['right' => true, 'wrong' => false]);
        $b = $this->multipleChoice('B?', ['right' => true, 'wrong' => false]);
        $this->attach($assessment, $a, 5.0);
        $this->attach($assessment, $b, 5.0);
        $assessment = $this->publish($assessment);

        $attempt = $this->begin($assessment);
        $this->save($attempt, $this->reload($a), ['optionIds' => [$this->correctOption($a)]], 10);

        $submitted = $this->attempts->submit($this->tenantA, $attempt, $assessment, autoSubmitted: false);

        self::assertSame(5.0, $submitted->rawScore);
        self::assertSame(10.0, $submitted->maxScore);
        self::assertSame(50.0, $submitted->percentage);
    }

    #[Test]
    public function an_attempt_with_an_essay_waits_for_a_marker_rather_than_being_scored(): void
    {
        $assessment = $this->assessment(passingScore: 50.0);
        $mcq = $this->multipleChoice('A?', ['right' => true, 'wrong' => false]);
        $essay = $this->questions->createQuestion(new Question(
            id: '',
            tenantId: $this->tenantA,
            bankId: $this->bank->id,
            type: QuestionType::LongAnswer,
            text: 'Discuss.',
            points: 5.0,
        ));

        $this->attach($assessment, $mcq, 5.0);
        $this->attach($assessment, $essay, 5.0);
        $assessment = $this->publish($assessment);

        $attempt = $this->begin($assessment);
        $this->save($attempt, $this->reload($mcq), ['optionIds' => [$this->correctOption($mcq)]], 10);
        $this->save($attempt, $essay, ['text' => 'An essay.'], 60);

        $submitted = $this->attempts->submit($this->tenantA, $attempt, $assessment, autoSubmitted: false);

        // Not graded, so no score and no pass — the check constraint enforces
        // that pairing and it is the honest state.
        self::assertSame('grading', $submitted->status->value);
        self::assertNull($submitted->passed);
        self::assertNull($submitted->rawScore);
        self::assertSame(1, $this->attempts->ungradedCount($this->tenantA, $attempt->id));
    }

    #[Test]
    public function marking_the_last_answer_totals_the_attempt(): void
    {
        $assessment = $this->assessment(passingScore: 50.0);
        $mcq = $this->multipleChoice('A?', ['right' => true, 'wrong' => false]);
        $essay = $this->questions->createQuestion(new Question(
            id: '',
            tenantId: $this->tenantA,
            bankId: $this->bank->id,
            type: QuestionType::LongAnswer,
            text: 'Discuss.',
            points: 5.0,
        ));

        $this->attach($assessment, $mcq, 5.0);
        $this->attach($assessment, $essay, 5.0);
        $assessment = $this->publish($assessment);

        $attempt = $this->begin($assessment);
        $this->save($attempt, $this->reload($mcq), ['optionIds' => [$this->correctOption($mcq)]], 10);
        $this->save($attempt, $essay, ['text' => 'An essay.'], 60);
        $submitted = $this->attempts->submit($this->tenantA, $attempt, $assessment, autoSubmitted: false);

        $result = $this->attempts->gradeAnswer(
            $this->tenantA,
            $submitted,
            $assessment,
            $essay->id,
            4.0,
            'Good, but thin on evidence.',
            $this->marker,
        );

        self::assertSame(0, $result['remaining']);
        self::assertSame('graded', $result['attempt']->status->value);
        self::assertSame(9.0, $result['attempt']->rawScore);
        self::assertSame(90.0, $result['attempt']->percentage);
        self::assertTrue($result['attempt']->passed);
    }

    #[Test]
    public function full_marks_from_a_marker_reads_as_correct_and_less_does_not(): void
    {
        $assessment = $this->assessment();
        $essay = $this->questions->createQuestion(new Question(
            id: '',
            tenantId: $this->tenantA,
            bankId: $this->bank->id,
            type: QuestionType::LongAnswer,
            text: 'Discuss.',
            points: 10.0,
        ));
        $this->attach($assessment, $essay, 10.0);
        $assessment = $this->publish($assessment);

        $attempt = $this->begin($assessment);
        $this->save($attempt, $essay, ['text' => 'Essay.'], 0);
        $submitted = $this->attempts->submit($this->tenantA, $attempt, $assessment, autoSubmitted: false);

        $this->attempts->gradeAnswer($this->tenantA, $submitted, $assessment, $essay->id, 7.0, null, $this->marker);
        self::assertFalse($this->attempts->answersFor($this->tenantA, $attempt->id)[$essay->id]['isCorrect']);

        $this->attempts->gradeAnswer($this->tenantA, $submitted, $assessment, $essay->id, 10.0, null, $this->marker);
        self::assertTrue($this->attempts->answersFor($this->tenantA, $attempt->id)[$essay->id]['isCorrect']);
    }

    #[Test]
    public function a_pass_survives_the_pass_mark_being_raised_afterwards(): void
    {
        // The reason `passed` is stored rather than derived: recomputing it
        // later would turn a historical pass into a fail.
        $assessment = $this->assessment(passingScore: 50.0);
        $q = $this->multipleChoice('A?', ['right' => true, 'wrong' => false]);
        $this->attach($assessment, $q, 10.0);
        $assessment = $this->publish($assessment);

        $attempt = $this->begin($assessment);
        $this->save($attempt, $this->reload($q), ['optionIds' => [$this->correctOption($q)]], 5);
        $submitted = $this->attempts->submit($this->tenantA, $attempt, $assessment, autoSubmitted: false);

        self::assertTrue($submitted->passed);

        // The author raises the bar to 100 later.
        $this->assessments->update(new Assessment(
            id: $assessment->id,
            tenantId: $this->tenantA,
            title: $assessment->title,
            passingScore: 100.0,
            passingType: PassingType::Percentage,
        ));

        self::assertTrue($this->attempts->find($this->tenantA, $attempt->id)?->passed);
    }

    #[Test]
    public function a_points_based_pass_mark_is_measured_in_marks_not_percent(): void
    {
        $assessment = $this->assessment(passingScore: 8.0, passingType: PassingType::Points);
        $a = $this->multipleChoice('A?', ['right' => true, 'wrong' => false]);
        $b = $this->multipleChoice('B?', ['right' => true, 'wrong' => false]);
        $this->attach($assessment, $a, 5.0);
        $this->attach($assessment, $b, 5.0);
        $assessment = $this->publish($assessment);

        $attempt = $this->begin($assessment);
        $this->save($attempt, $this->reload($a), ['optionIds' => [$this->correctOption($a)]], 5);
        $this->save($attempt, $this->reload($b), ['optionIds' => [$this->wrongOption($b)]], 5);

        // 5 of 10 is 50%, which would pass a 50% threshold but not 8 marks.
        $submitted = $this->attempts->submit($this->tenantA, $attempt, $assessment, autoSubmitted: false);

        self::assertSame(50.0, $submitted->percentage);
        self::assertFalse($submitted->passed);
    }

    // --- tenant isolation -------------------------------------------------

    #[Test]
    public function another_tenant_cannot_see_the_assessment_or_its_attempts(): void
    {
        $assessment = $this->publishedWithOneQuestion();
        $attempt = $this->begin($assessment);

        self::assertNull($this->assessments->find($this->tenantB, $assessment->id));
        self::assertNull($this->attempts->find($this->tenantB, $attempt->id));
        self::assertSame(0, $this->assessments->paginate($this->tenantB, 1, 50)['total']);
        self::assertSame(
            0,
            $this->attempts->forAssessment($this->tenantB, $assessment->id, 1, 50)['total'],
        );
        self::assertSame([], $this->attempts->answersFor($this->tenantB, $attempt->id));
    }

    #[Test]
    public function another_tenant_cannot_publish_or_delete_it(): void
    {
        $assessment = $this->assessment();

        self::assertNull($this->assessments->setStatus($this->tenantB, $assessment->id, AssessmentStatus::Published));
        self::assertFalse($this->assessments->softDelete($this->tenantB, $assessment->id));
        self::assertSame(
            AssessmentStatus::Draft,
            $this->assessments->find($this->tenantA, $assessment->id)?->status,
        );
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @param array<string, bool> $options text => isCorrect
     */
    private function multipleChoice(string $text, array $options, float $points = 1.0): Question
    {
        $position = 0;
        $built = [];

        foreach ($options as $optionText => $isCorrect) {
            $built[] = new QuestionOption(
                id: '',
                tenantId: $this->tenantA,
                questionId: '',
                text: (string) $optionText,
                isCorrect: $isCorrect,
                sortOrder: $position++,
            );
        }

        return $this->questions->createQuestion(new Question(
            id: '',
            tenantId: $this->tenantA,
            bankId: $this->bank->id,
            type: QuestionType::MultipleChoiceSingle,
            text: $text,
            points: $points,
            options: $built,
        ));
    }

    private function assessment(
        float $passingScore = 70.0,
        PassingType $passingType = PassingType::Percentage,
    ): Assessment {
        return $this->assessments->create(new Assessment(
            id: '',
            tenantId: $this->tenantA,
            title: 'Assessment ' . substr(Uuid::uuid4()->toString(), 0, 8),
            passingScore: $passingScore,
            passingType: $passingType,
        ));
    }

    private function publish(Assessment $assessment): Assessment
    {
        return $this->assessments->setStatus($this->tenantA, $assessment->id, AssessmentStatus::Published)
            ?? self::fail('Could not publish.');
    }

    /**
     * Starts an attempt and deals it a paper, as the action does.
     *
     * Splitting the two in the repository keeps the draw testable on its own;
     * every caller must do both, and this helper is where that is encoded.
     */
    private function begin(Assessment $assessment): Attempt
    {
        $attempt = $this->attempts->start($this->tenantA, $assessment->id, $this->learner, null);

        $this->attempts->dealPaper($this->tenantA, $attempt->id, $this->papers->build(
            $assessment,
            $attempt->id,
            $this->assessments->questionsFor($this->tenantA, $assessment->id),
            $this->assessments->sectionsFor($this->tenantA, $assessment->id),
        ));

        return $attempt;
    }

    private function publishedWithOneQuestion(): Assessment
    {
        $assessment = $this->assessment();
        $this->attach($assessment, $this->multipleChoice('Q?', ['right' => true, 'wrong' => false]), 5.0);

        return $this->publish($assessment);
    }

    private function attach(Assessment $assessment, Question $question, ?float $points = null): bool
    {
        return $this->assessments->attachQuestion(
            $this->tenantA,
            $assessment->id,
            $question->id,
            $points,
            null,
            true,
        );
    }

    private function reload(Question $question): Question
    {
        return $this->questions->findQuestion($this->tenantA, $question->id)
            ?? self::fail('Question vanished.');
    }

    private function correctOption(Question $question): string
    {
        foreach ($this->reload($question)->options as $option) {
            if ($option->isCorrect) {
                return $option->id;
            }
        }

        self::fail('No correct option.');
    }

    private function wrongOption(Question $question): string
    {
        foreach ($this->reload($question)->options as $option) {
            if (!$option->isCorrect) {
                return $option->id;
            }
        }

        self::fail('No incorrect option.');
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function save(Attempt $attempt, Question $question, array $answer, int $seconds): void
    {
        $points = $question->points;
        $rows = $this->assessments->questionsFor($this->tenantA, $attempt->assessmentId);

        foreach ($rows as $row) {
            if ($row['question']->id === $question->id) {
                $points = $row['question']->pointsIn($row['pointsOverride']);
                break;
            }
        }

        $this->attempts->saveAnswer(
            $this->tenantA,
            $attempt,
            $question->id,
            $answer,
            $this->grader->grade($this->reload($question), $answer, $points),
            $seconds,
        );
    }

    private function createTenant(string $prefix): string
    {
        $id = Uuid::uuid4()->toString();

        $this->db()->executeStatement(
            'INSERT INTO tenants (id, name, code, status) VALUES (:id, :name, :code, \'active\')',
            ['id' => $id, 'name' => $prefix . ' test tenant', 'code' => $prefix . '-' . substr($id, 0, 8)],
        );

        return $id;
    }

    private function createUser(string $tenantId, string $who): string
    {
        return (string) $this->db()->fetchOne(
            "INSERT INTO users (tenant_id, email, first_name, last_name, status)
             VALUES (:tenant, :email, :first, 'Tester', 'active') RETURNING id",
            [
                'tenant' => $tenantId,
                'email'  => $who . '-' . Uuid::uuid4()->toString() . '@asm.test',
                'first'  => ucfirst($who),
            ],
        );
    }

    private function db(): Connection
    {
        return self::$connection ?? self::fail('PostgreSQL is not reachable.');
    }

    private static function connect(): ?Connection
    {
        $root = dirname(__DIR__, 2);

        if (is_file($root . '/.env')) {
            \Dotenv\Dotenv::createImmutable($root)->safeLoad();
        }

        try {
            $connection = DriverManager::getConnection([
                'driver'   => 'pdo_pgsql',
                'host'     => $_ENV['DB_HOST'] ?? '127.0.0.1',
                'port'     => (int) ($_ENV['DB_PORT'] ?? 5432),
                'dbname'   => $_ENV['DB_NAME'] ?? 'central_lms',
                'user'     => $_ENV['DB_USER'] ?? 'central_lms',
                'password' => $_ENV['DB_PASSWORD'] ?? '',
            ], new \Doctrine\DBAL\Configuration());

            $connection->executeQuery('SELECT 1');

            return $connection;
        } catch (\Throwable) {
            return null;
        }
    }
}
