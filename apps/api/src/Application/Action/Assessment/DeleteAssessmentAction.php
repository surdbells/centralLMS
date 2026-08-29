<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\EntityNotFound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /v1/assessments/{id}
 *
 * A soft delete: the row is kept, archived and hidden, so the attempts made
 * against it are not orphaned. Authoring only.
 */
final class DeleteAssessmentAction extends AssessmentAction
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

        $id = $args['id'] ?? '';

        if (!$this->assessments->softDelete($this->tenantId($request), $id)) {
            throw new EntityNotFound('Assessment', $id);
        }

        return Json::noContent($response);
    }
}
