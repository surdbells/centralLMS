<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Assessment\Attempt;
use Central\Api\Domain\Assessment\Question;
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
 * Resuming rather than refusing matters — a learner whose browser crashed mid
 * exam must land back in the same attempt with the clock still running, not
 * be told they already have one open.
 */
final class StartAttemptAction extends AssessmentAction
{
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
            return Json::ok($response, $this->paper($tenantId, $assessment->id, $open, $assessment->shuffleQuestions));
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

        return Json::created(
            $response,
            $this->paper($tenantId, $assessment->id, $attempt, $assessment->shuffleQuestions),
            sprintf('/v1/attempts/%s', $attempt->id),
        );
    }

    /**
     * The questions as the learner should see them.
     *
     * @return array<string, mixed>
     */
    private function paper(string $tenantId, string $assessmentId, Attempt $attempt, bool $shuffle): array
    {
        $rows = $this->assessments->questionsFor($tenantId, $assessmentId);

        if ($shuffle) {
            // Seeded from the attempt id, so a learner reloading the page sees
            // the same order rather than a reshuffled paper.
            mt_srand(crc32($attempt->id));
            shuffle($rows);
            mt_srand();
        }

        $answers = $this->attempts->answersFor($tenantId, $attempt->id);

        return [
            'attempt'   => $attempt->toArray(),
            'questions' => array_map(
                static function (array $row) use ($answers): array {
                    /** @var Question $question */
                    $question = $row['question'];

                    return [
                        // No isCorrect, no explanation, no model answer.
                        ...$question->toArrayForLearner($row['pointsOverride']),
                        'isRequired' => $row['isRequired'],
                        // What they have already put down, so a resumed
                        // attempt shows their working.
                        'yourAnswer' => $answers[$question->id]['answer'] ?? null,
                    ];
                },
                $rows,
            ),
        ];
    }
}
