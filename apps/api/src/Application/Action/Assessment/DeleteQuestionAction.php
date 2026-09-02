<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\EntityNotFound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /v1/question-banks/{bankId}/questions/{id}
 *
 * Soft-deletes a bank question. An assessment that still lists the question
 * keeps its `assessment_questions` row, but the paper builder skips a question
 * it can no longer load — the same behaviour as deleting the whole bank.
 */
final class DeleteQuestionAction extends AssessmentAction
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
        $id = $args['id'] ?? '';

        $existing = $this->questions->findQuestion($tenantId, $id);

        if ($existing === null || $existing->bankId !== $bank->id) {
            throw new EntityNotFound('Question', $id);
        }

        // Which assessments to re-check is captured before the delete, while the
        // link rows still exist.
        $affected = $this->questions->questionIsInUse($tenantId, $id)
            ? $this->assessments->assessmentIdsUsingQuestion($tenantId, $id)
            : [];

        $this->questions->softDeleteQuestion($tenantId, $id);

        // Losing a manually-marked question can leave an assessment stuck in the
        // grading queue, so its flag is refreshed.
        foreach ($affected as $assessmentId) {
            $this->assessments->refreshManualGradingFlag($tenantId, $assessmentId);
        }

        return Json::noContent($response);
    }
}
