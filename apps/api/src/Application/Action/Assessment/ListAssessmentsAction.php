<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Assessment\Assessment;
use Central\Api\Domain\Assessment\AssessmentStatus;
use Central\Api\Domain\Authz\Permission;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/assessments
 *
 * The authoring list, every status included. Learners reach an assessment
 * through its course, not through this.
 */
final class ListAssessmentsAction extends AssessmentAction
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::AssessmentManage);

        $listing = $this->listing($request);
        $query = $request->getQueryParams();

        $result = $this->assessments->paginate(
            $this->tenantId($request),
            $listing['page'],
            $listing['perPage'],
            $listing['search'],
            isset($query['status']) && $query['status'] !== ''
                ? AssessmentStatus::tryFrom((string) $query['status'])
                : null,
            isset($query['courseId']) ? (string) $query['courseId'] : null,
        );

        return Json::page(
            $response,
            array_map(static fn (Assessment $a): array => $a->toArray(), $result['items']),
            $result['total'],
            $listing['page'],
            $listing['perPage'],
        );
    }
}
