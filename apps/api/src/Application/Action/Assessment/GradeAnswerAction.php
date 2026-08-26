<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Assessment\Question;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /v1/attempts/{id}/answers/{questionId}/grade
 *
 * A marker's decision on one answer. When the last unmarked answer is graded
 * the attempt is totalled and its result stamped, in the same transaction.
 *
 * Requires `assessment.grade`, which authors do not automatically hold:
 * writing a question and deciding whether someone passed are different jobs.
 */
final class GradeAnswerAction extends AssessmentAction
{
    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::AssessmentGrade);

        $tenantId = $this->tenantId($request);
        $attemptId = $args['id'] ?? '';

        $attempt = $this->attempts->find($tenantId, $attemptId)
            ?? throw new EntityNotFound('Attempt', $attemptId);

        if ($attempt->status->isOpen()) {
            throw new ConflictingState(
                'This attempt has not been submitted yet.',
                'attempt_still_open',
            );
        }

        $assessment = $this->requireAssessment($request, $attempt->assessmentId);
        $questionId = $args['questionId'] ?? '';

        $answers = $this->attempts->answersFor($tenantId, $attempt->id);

        if (!array_key_exists($questionId, $answers)) {
            throw new EntityNotFound('Answer', $questionId);
        }

        $body = $this->body($request);
        $raw = $body['pointsEarned'] ?? null;

        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            throw ValidationFailed::fromArray(['pointsEarned' => 'Enter the marks awarded.']);
        }

        $points = (float) $raw;
        $possible = $answers[$questionId]['pointsPossible'];

        if ($points < 0) {
            throw ValidationFailed::fromArray(['pointsEarned' => 'Marks cannot be negative.']);
        }

        if ($points > $possible) {
            // The database would reject it anyway; saying so plainly is more
            // use to a marker than a constraint violation.
            throw ValidationFailed::fromArray([
                'pointsEarned' => sprintf('This question is worth %s marks.', rtrim(rtrim(number_format($possible, 2, '.', ''), '0'), '.')),
            ]);
        }

        $feedback = isset($body['feedback']) && is_string($body['feedback']) && trim($body['feedback']) !== ''
            ? trim($body['feedback'])
            : null;

        $result = $this->attempts->gradeAnswer(
            $tenantId,
            $attempt,
            $assessment,
            $questionId,
            $points,
            $feedback,
            $this->userId($request),
        );

        return Json::ok($response, [
            'attempt'   => $result['attempt']->toArray(),
            'remaining' => $result['remaining'],
        ]);
    }

    /**
     * GET /v1/assessments/{id}/attempts — the marking queue.
     *
     * @param array<string, string> $args
     */
    public function queue(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::AssessmentGrade);

        $tenantId = $this->tenantId($request);
        $assessment = $this->requireAssessment($request, $args['id'] ?? '');
        $listing = $this->listing($request);

        $rawStatus = $request->getQueryParams()['status'] ?? null;

        $result = $this->attempts->forAssessment(
            $tenantId,
            $assessment->id,
            $listing['page'],
            $listing['perPage'],
            is_string($rawStatus) && $rawStatus !== ''
                ? \Central\Api\Domain\Assessment\AttemptStatus::tryFrom($rawStatus)
                : null,
        );

        return Json::page(
            $response,
            array_map(
                fn (\Central\Api\Domain\Assessment\Attempt $a): array => $a->toArray([
                    'awaitingMarking' => $this->attempts->ungradedCount($tenantId, $a->id),
                ]),
                $result['items'],
            ),
            $result['total'],
            $listing['page'],
            $listing['perPage'],
        );
    }

    /**
     * GET /v1/attempts/{id} — one attempt with its answers, for a marker.
     *
     * @param array<string, string> $args
     */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::AssessmentGrade);

        $tenantId = $this->tenantId($request);
        $attempt = $this->attempts->find($tenantId, $args['id'] ?? '')
            ?? throw new EntityNotFound('Attempt', $args['id'] ?? '');

        $answers = $this->attempts->answersFor($tenantId, $attempt->id);
        // The paper this learner was dealt, which under random selection is
        // not the assessment's full question list.
        $rows = $this->attempts->paperFor($tenantId, $attempt->id);

        return Json::ok($response, $attempt->toArray([
            'answers' => array_map(
                static function (array $line) use ($answers): array {
                    /** @var Question $question */
                    $question = $line['question'];
                    $given = $answers[$question->id] ?? null;

                    return [
                        // The full key: a marker needs to see what was correct.
                        'question' => $question->toArrayForReview(),
                        'answer'   => $given['answer'] ?? null,
                        'isCorrect' => $given['isCorrect'] ?? null,
                        'pointsEarned' => $given['pointsEarned'] ?? null,
                        'pointsPossible' => $given['pointsPossible'] ?? $line['pointsPossible'],
                        'feedback' => $given['feedback'] ?? null,
                        'isGraded' => $given['isGraded'] ?? false,
                        'needsMarker' => !$question->type->isAutoGradable(),
                    ];
                },
                $rows,
            ),
        ]));
    }
}
