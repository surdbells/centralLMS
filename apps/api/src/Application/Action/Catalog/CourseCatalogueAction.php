<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/courses/catalogue
 *
 * What a learner browses: published courses only. A separate endpoint backed
 * by a separate repository method, rather than a `?status=published` filter on
 * the admin list — a filter is something a caller can forget, and forgetting
 * it here would expose every draft.
 */
final class CourseCatalogueAction extends CourseAction
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::CourseView);

        $paging = $this->paging($request);

        $result = $this->courses->catalogue(
            $this->tenantId($request),
            $paging['page'],
            $paging['perPage'],
            $paging['search'],
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
