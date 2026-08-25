<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Catalog\CourseModule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/courses/{courseId}/modules
 *
 * Authoring view: every module including the deactivated ones, unless
 * `?activeOnly=true`. Learners read the structure through the course outline
 * instead, which hides drafts.
 */
final class ListModulesAction extends StructureAction
{
    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::CourseManage);

        $course = $this->requireCourse($request, $args['courseId'] ?? '');

        $modules = $this->modules->forCourse(
            $this->tenantId($request),
            $course->id,
            $this->activeOnly($request),
        );

        return Json::ok($response, array_map(
            static fn (CourseModule $m): array => $m->toArray(),
            $modules,
        ));
    }
}
