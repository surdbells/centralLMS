<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Assessment\Grader;
use Central\Api\Domain\Assessment\Question;
use Central\Api\Domain\Assessment\AssessmentRepository;
use Central\Api\Domain\Assessment\AttemptRepository;
use Central\Api\Domain\Assessment\QuestionRepository;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /v1/attempts/{id}/answers/{questionId}
 *
 * Saves one answer. Called as the learner works, so it must be cheap and
 * idempotent: re-saving the same question replaces the answer rather than
 * adding a second one.
 *
 * The answer is graded here, on save, rather than at submission. That keeps
 * submission a single cheap statement, and means an attempt that is never
 * submitted still has its answers marked for the record. The result is not
 * returned — telling a learner "correct" as they go would be a different
 * product, and the assessment's own `revealAnswers` setting decides that.
 */
final class SaveAnswerAction extends AssessmentAction
{
    public function __construct(
        AssessmentRepository $assessments,
        QuestionRepository $questions,
        AttemptRepository $attempts,
        private readonly Grader $grader,
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
        $attempt = $this->attempts->find($tenantId, $args['id'] ?? '')
            ?? throw new EntityNotFound('Attempt', $args['id'] ?? '');

        // An attempt belongs to one learner and nobody else may write to it.
        if ($attempt->userId !== $this->userId($request)) {
            throw new AccessDenied(Permission::AssessmentTake->value);
        }

        $now = new \DateTimeImmutable();

        if (!$attempt->acceptsAnswers($now)) {
            throw new ConflictingState(
                $attempt->hasExpired($now)
                    ? 'Time is up for this attempt.'
                    : 'This attempt has already been submitted.',
                $attempt->hasExpired($now) ? 'attempt_expired' : 'attempt_closed',
            );
        }

        $questionId = $args['questionId'] ?? '';
        $row = $this->questionOn($tenantId, $attempt->assessmentId, $questionId);

        if ($row === null) {
            // Not on this assessment, so not answerable here — whether it
            // exists elsewhere is not this endpoint's business to reveal.
            throw new EntityNotFound('Question', $questionId);
        }

        /** @var Question $question */
        $question = $row['question'];

        $body = $this->body($request);
        $answer = $body['answer'] ?? null;

        if (!is_array($answer)) {
            throw ValidationFailed::fromArray([
                'answer' => 'Send the answer as an object, for example {"optionIds": ["…"]}.',
            ]);
        }

        $seconds = (int) ($body['secondsSpent'] ?? 0);

        if ($seconds < 0 || $seconds > 43200) {
            throw ValidationFailed::fromArray([
                'secondsSpent' => 'That is not a plausible amount of time for one question.',
            ]);
        }

        $points = $question->pointsIn($row['pointsOverride']);
        $grade = $this->grader->grade($question, $answer, $points);

        $this->attempts->saveAnswer($tenantId, $attempt, $question->id, $answer, $grade, $seconds);

        return Json::ok($response, [
            'attemptId'  => $attempt->id,
            'questionId' => $question->id,
            'saved'      => true,
            // Deliberately no score and no correctness: see the class docblock.
            'awaitingMarker'   => $grade->needsHumanGrading,
            'secondsRemaining' => $attempt->secondsRemaining($now),
        ]);
    }

    /**
     * @return array{question: Question, pointsOverride: ?float, sortOrder: int, isRequired: bool}|null
     */
    private function questionOn(string $tenantId, string $assessmentId, string $questionId): ?array
    {
        foreach ($this->assessments->questionsFor($tenantId, $assessmentId) as $row) {
            if ($row['question']->id === $questionId) {
                return $row;
            }
        }

        return null;
    }
}
