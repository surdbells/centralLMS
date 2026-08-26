<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Assessment\Assessment;
use Central\Api\Domain\Assessment\AssessmentRepository;
use Central\Api\Domain\Assessment\Attempt;
use Central\Api\Domain\Assessment\AttemptRepository;
use Central\Api\Domain\Assessment\PaperBuilder;
use Central\Api\Domain\Assessment\Question;
use Central\Api\Domain\Assessment\QuestionRepository;
use Central\Api\Domain\Assessment\QuestionSelectionMode;
use Central\Api\Domain\Assessment\RandomRule;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v1/assessments/{id}/attempts
 *
 * Starts a sitting, or resumes the one already open. Returns the paper: the
 * questions as the learner should see them, with no answer key.
 *
 * The draw happens once and is written to `attempt_questions`. Resuming reads
 * that back rather than drawing again — a learner whose browser crashed mid
 * exam must land back on the same paper with the clock still running, which
 * under random selection means the same *questions*, not merely the same
 * attempt row.
 */
final class StartAttemptAction extends AssessmentAction
{
    public function __construct(
        AssessmentRepository $assessments,
        QuestionRepository $questions,
        AttemptRepository $attempts,
        private readonly PaperBuilder $papers,
    ) {
        parent::__construct($assessments, $questions, $attempts);
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::AssessmentTake);

        $tenantId = $this->tenantId($request);
        $userId = $this->userId($request);
        $assessmentId = $args['id'] ?? '';

        $assessment = $this->requireAssessment($request, $assessmentId);

        if (!$assessment->status->isAvailableToLearners()) {
            // A 404, not a 403: whether an unpublished assessment exists is
            // not something a learner should be able to probe.
            throw new EntityNotFound('Assessment', $assessmentId);
        }

        $now = new \DateTimeImmutable();
        $open = $this->attempts->findOpen($tenantId, $assessment->id, $userId);

        if ($open !== null && !$open->hasExpired($now)) {
            return Json::ok($response, $this->paper($tenantId, $assessment, $open));
        }

        if ($open !== null) {
            // The clock ran out while they were away. Close it before opening
            // another, or the one-open-attempt index rejects the new one.
            $this->attempts->submit($tenantId, $open, $assessment, autoSubmitted: true);
        }

        $refusal = Attempt::refusalToStart(
            $assessment,
            $this->attempts->forLearner($tenantId, $assessment->id, $userId),
            $now,
        );

        if ($refusal !== null) {
            throw new ConflictingState($refusal['message'], $refusal['code']);
        }

        $attempt = $this->attempts->start(
            $tenantId,
            $assessment->id,
            $userId,
            $assessment->deadlineFor($now),
        );

        $this->attempts->dealPaper($tenantId, $attempt->id, $this->draw($tenantId, $assessment, $attempt->id));

        return Json::created(
            $response,
            $this->paper($tenantId, $assessment, $attempt),
            sprintf('/v1/attempts/%s', $attempt->id),
        );
    }

    /**
     * Chooses the questions for a new attempt.
     *
     * @return list<array{question: Question, pointsPossible: float, sectionId: ?string, sortOrder: int}>
     */
    private function draw(string $tenantId, Assessment $assessment, string $attemptId): array
    {
        $rules = [];
        $draws = [];

        if ($assessment->questionSelection === QuestionSelectionMode::Pool) {
            $rules = $this->assessments->rulesFor($tenantId, $assessment->id);

            foreach ($rules as $rule) {
                $draws[$rule->id] = $this->assessments->candidatesForRule($tenantId, $rule);
            }
        }

        return $this->papers->build(
            $assessment,
            $attemptId,
            $this->assessments->questionsFor($tenantId, $assessment->id),
            $this->assessments->sectionsFor($tenantId, $assessment->id),
            $rules,
            $draws,
        );
    }

    /**
     * The paper as the learner should see it, read back from the draw.
     *
     * @return array<string, mixed>
     */
    private function paper(string $tenantId, Assessment $assessment, Attempt $attempt): array
    {
        $paper = $this->attempts->paperFor($tenantId, $attempt->id);

        if ($assessment->shuffleQuestions) {
            // Seeded from the attempt id, so reloading shows the same order
            // rather than reshuffling the paper under the learner.
            mt_srand(crc32($attempt->id));
            shuffle($paper);
            mt_srand();
        }

        $answers = $this->attempts->answersFor($tenantId, $attempt->id);

        return [
            'attempt'  => $attempt->toArray(['totalPoints' => $this->attempts->paperTotal($tenantId, $attempt->id)]),
            'sections' => array_map(
                // Instructions and per-section clocks; the learner needs both
                // to know what they are being asked to do.
                static fn ($section): array => $section->toArray(),
                $this->assessments->sectionsFor($tenantId, $assessment->id),
            ),
            'questions' => array_map(
                static function (array $line) use ($answers): array {
                    /** @var Question $question */
                    $question = $line['question'];

                    return [
                        // No isCorrect, no explanation, no model answer.
                        ...$question->toArrayForLearner($line['pointsPossible']),
                        'sectionId' => $line['sectionId'],
                        // What they have already put down, so a resumed
                        // attempt shows their working.
                        'yourAnswer' => $answers[$question->id]['answer'] ?? null,
                    ];
                },
                $paper,
            ),
        ];
    }
}
