<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Catalog\CourseStatus;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/courses
 *
 * Administration list — every status, including drafts. Requires
 * `course.manage`, so a learner cannot reach unpublished courses through it.
 */
final class ListCoursesAction extends CourseAction
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::CourseManage);

        $paging = $this->paging($request);
        $query = $request->getQueryParams();

        $status = isset($query['status']) && $query['status'] !== ''
            ? CourseStatus::tryFrom((string) $query['status'])
            : null;

        $result = $this->courses->paginate(
            $this->tenantId($request),
            $paging['page'],
            $paging['perPage'],
            $paging['search'],
            $status,
            isset($query['categoryId']) ? (string) $query['categoryId'] : null,
            $paging['sortBy'],
            $paging['sortDir'],
        );

        return Json::page(
            $response,
            $this->serialise($result['items']),
            $result['total'],
            $paging['page'],
            $paging['perPage'],
        );
    }
}
