<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\EntityNotFound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /v1/question-banks/{bankId}/questions/{id}/move  body { targetBankId }
 *
 * Moves a question to another bank of the same tenant. `updateQuestion` refuses
 * to change a question's bank because that silently changes what assessments
 * drawing on those banks contain; this route is the explicit way to do it.
 */
final class MoveQuestionAction extends AssessmentAction
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
        $source = $this->requireBank($request, $args['bankId'] ?? '');
        $id = $args['id'] ?? '';

        $existing = $this->questions->findQuestion($tenantId, $id);

        if ($existing === null || $existing->bankId !== $source->id) {
            throw new EntityNotFound('Question', $id);
        }

        $targetId = trim((string) ($this->body($request)['targetBankId'] ?? ''));

        // Resolving the target through the repository is what enforces that it
        // belongs to the caller's tenant: an id from elsewhere is a 404.
        $target = $this->requireBank($request, $targetId);

        if ($target->id !== $source->id) {
            $moved = $this->questions->moveQuestion($tenantId, $id, $source->id, $target->id);

            if (!$moved) {
                throw new EntityNotFound('Question', $id);
            }
        }

        $question = $this->questions->findQuestion($tenantId, $id)
            ?? throw new EntityNotFound('Question', $id);

        return Json::ok($response, $question->toArrayForReview());
    }
}
