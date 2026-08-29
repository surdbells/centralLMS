<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/assessments/stats
 *
 * The headline figures the authoring list opens with — how many assessments
 * sit in each status, and how they are doing across all attempts.
 */
final class AssessmentStatsAction extends AssessmentAction
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::AssessmentManage);

        return Json::ok($response, $this->assessments->stats($this->tenantId($request)));
    }
}
