<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Assessment\Question;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\EntityNotFound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;

/**
 * POST /v1/question-banks/{bankId}/questions
 * PUT  /v1/question-banks/{bankId}/questions/{id}
 */
final class SaveQuestionAction extends AssessmentAction
{
    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::AssessmentManage);

        $tenantId = $this->tenantId($request);
        $bank = $this->requireBank($request, $args['bankId'] ?? '');
        $id = $args['id'] ?? null;

        $existing = null;

        if ($id !== null) {
            $existing = $this->questions->findQuestion($tenantId, $id);

            // Checked against the bank in the path, so a question id from
            // another bank cannot be edited through this route.
            if ($existing === null || $existing->bankId !== $bank->id) {
                throw new EntityNotFound('Question', $id);
            }
        }

        $question = Question::fromInput(
            $existing->id ?? Uuid::uuid4()->toString(),
            $tenantId,
            $bank->id,
            $existing === null
                ? $this->body($request)
                : [...$existing->toArrayForReview(), ...$this->body($request)],
        );

        if ($existing === null) {
            $created = $this->questions->createQuestion($question);

            return Json::created(
                $response,
                $created->toArrayForReview(),
                sprintf('/v1/question-banks/%s/questions/%s', $bank->id, $created->id),
            );
        }

        $updated = $this->questions->updateQuestion($question);

        // Editing a question can change whether an assessment needs a human
        // marker — turning a multiple choice into an essay, for instance.
        foreach ($this->assessmentsUsing($tenantId, $updated->id) as $assessmentId) {
            $this->assessments->refreshManualGradingFlag($tenantId, $assessmentId);
        }

        return Json::ok($response, $updated->toArrayForReview());
    }

    /**
     * @return list<string>
     */
    private function assessmentsUsing(string $tenantId, string $questionId): array
    {
        // Cheap: a question is on a handful of assessments, not thousands.
        return $this->questions->questionIsInUse($tenantId, $questionId)
            ? $this->assessments->assessmentIdsUsingQuestion($tenantId, $questionId)
            : [];
    }
}
