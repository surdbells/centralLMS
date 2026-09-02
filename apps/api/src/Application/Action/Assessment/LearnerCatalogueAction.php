<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Assessment\LearnerAssessment;
use Central\Api\Domain\Authz\Permission;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/me/assessments
 *
 * The signed-in learner's assessment catalogue: every published assessment,
 * with whether it is assigned to them (and by when) and how their own attempts
 * have gone. `assigned=1` narrows it to the assigned ones — the dashboard's
 * "assigned / upcoming" list. Self-scoped, so it needs only `assessment.take`.
 */
final class LearnerCatalogueAction extends AssessmentAction
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::AssessmentTake);

        $query = $request->getQueryParams();
        $assigned = filter_var($query['assigned'] ?? false, FILTER_VALIDATE_BOOL);
        $search = trim((string) ($query['search'] ?? ''));
        $category = trim((string) ($query['categoryId'] ?? ''));
        $kind = trim((string) ($query['kind'] ?? ''));
        $limit = max(1, min(500, (int) ($query['limit'] ?? 500)));

        $rows = $this->assessments->catalogueForLearner(
            $this->tenantId($request),
            $this->userId($request),
            $assigned,
            $search !== '' ? $search : null,
            $category !== '' ? $category : null,
            $kind !== '' ? $kind : null,
            $limit,
        );

        return Json::ok($response, array_map(static fn (LearnerAssessment $a): array => $a->toArray(), $rows));
    }
}
