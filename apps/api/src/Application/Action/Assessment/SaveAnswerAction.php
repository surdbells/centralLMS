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
        $line = $this->questionOnPaper($tenantId, $attempt->id, $questionId);

        if ($line === null) {
            // Not on *this learner's* paper. Under random selection that is a
            // stricter test than "on the assessment", and the right one: it
            // stops a learner answering a question they were never dealt.
            throw new EntityNotFound('Question', $questionId);
        }

        /** @var Question $question */
        $question = $line['question'];

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

        // The worth frozen at the draw, not the question's current value:
        // an author editing it mid-sitting must not change what the learner in
        // front of it is being marked out of.
        $grade = $this->grader->grade($question, $answer, $line['pointsPossible']);

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
     * @return array{question: Question, pointsPossible: float, sectionId: ?string, sortOrder: int}|null
     */
    private function questionOnPaper(string $tenantId, string $attemptId, string $questionId): ?array
    {
        foreach ($this->attempts->paperFor($tenantId, $attemptId) as $line) {
            if ($line['question']->id === $questionId) {
                return $line;
            }
        }

        return null;
    }
}
