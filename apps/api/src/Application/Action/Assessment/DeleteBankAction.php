<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\EntityNotFound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /v1/question-banks/{id}
 *
 * Soft-deletes the bank and the questions in it. Assessments already asking
 * those questions keep working: `questionsFor()` skips what it cannot load,
 * so a live sitting loses a question rather than failing outright.
 */
final class DeleteBankAction extends AssessmentAction
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

        $bank = $this->requireBank($request, $args['id'] ?? '');

        return $this->questions->softDeleteBank($this->tenantId($request), $bank->id)
            ? Json::noContent($response)
            : throw new EntityNotFound('Question bank', $bank->id);
    }
}
