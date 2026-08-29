<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\EntityNotFound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v1/assessments/{id}/duplicate
 *
 * Copies an assessment into a fresh draft — its configuration, questions,
 * sections and random rules — so a new one can start from a working one.
 */
final class DuplicateAssessmentAction extends AssessmentAction
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
        $copy = $this->assessments->duplicate($this->tenantId($request), $id);

        if ($copy === null) {
            throw new EntityNotFound('Assessment', $id);
        }

        return Json::created($response, $copy->toArray());
    }
}
