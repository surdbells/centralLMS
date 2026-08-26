<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Gamification\Awarder;
use Central\Api\Domain\Assessment\AssessmentRepository;
use Central\Api\Domain\Assessment\AttemptRepository;
use Central\Api\Domain\Assessment\QuestionRepository;
use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Gamification\PointReason;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v1/attempts/{id}/submit
 *
 * Closes the sitting and totals it. An attempt whose clock has already run out
 * is still accepted, and recorded as auto-submitted rather than refused —
 * refusing would lose the work the learner did before the deadline.
 */
final class SubmitAttemptAction extends AssessmentAction
{
    public function __construct(
        AssessmentRepository $assessments,
        QuestionRepository $questions,
        AttemptRepository $attempts,
        private readonly Awarder $awarder,
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

        if ($attempt->userId !== $this->userId($request)) {
            throw new AccessDenied(Permission::AssessmentTake->value);
        }

        if (!$attempt->status->isOpen()) {
            throw new ConflictingState('This attempt has already been submitted.', 'attempt_closed');
        }

        $assessment = $this->requireAssessment($request, $attempt->assessmentId);
        $now = new \DateTimeImmutable();

        $submitted = $this->attempts->submit(
            $tenantId,
            $attempt,
            $assessment,
            autoSubmitted: $attempt->hasExpired($now),
        );

        // Only a pass earns points, and only once per assessment — the
        // unique index on (user, reason, subject) settles the second part.
        if ($submitted->passed === true) {
            $this->awarder->award(
                $tenantId,
                $submitted->userId,
                PointReason::AssessmentPassed,
                $assessment->id,
                $assessment->title,
            );
        }

        return Json::ok($response, $this->result($tenantId, $submitted, $assessment));
    }

    /**
     * What the learner is allowed to see straight after submitting.
     *
     * Governed by the assessment's own settings: `showScoreImmediately` for
     * the mark, `revealAnswers` for the answer key. An assessment set to hide
     * both returns confirmation and nothing else.
     *
     * @return array<string, mixed>
     */
    private function result(
        string $tenantId,
        \Central\Api\Domain\Assessment\Attempt $attempt,
        \Central\Api\Domain\Assessment\Assessment $assessment,
    ): array {
        $payload = $attempt->toArray();

        if (!$assessment->showScoreImmediately) {
            // Withheld rather than omitted silently, so a client knows the
            // difference between "not marked yet" and "not shown to you".
            $payload['rawScore'] = null;
            $payload['maxScore'] = null;
            $payload['percentage'] = null;
            $payload['passed'] = null;
            $payload['scoreWithheld'] = true;
        }

        $previous = $this->attempts->forLearner($tenantId, $assessment->id, $attempt->userId);
        $used = count(array_filter(
            $previous,
            static fn (\Central\Api\Domain\Assessment\Attempt $a): bool => $a->status->countsAsAttempt(),
        ));

        $exhausted = $assessment->maxAttempts !== null && $used >= $assessment->maxAttempts;
        $deadlinePassed = $assessment->availableUntil !== null
            && new \DateTimeImmutable() > $assessment->availableUntil;

        $payload['answersRevealed'] = $assessment->revealAnswers->allowsRevealNow($deadlinePassed, $exhausted);
        $payload['attemptsRemaining'] = $assessment->maxAttempts === null
            ? null
            : max(0, $assessment->maxAttempts - $used);
        $payload['awaitingMarking'] = $this->attempts->ungradedCount($tenantId, $attempt->id);

        return $payload;
    }
}
