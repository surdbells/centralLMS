<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Catalog\Lesson;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/courses/{courseId}/modules/{moduleId}/lessons
 */
final class ListLessonsAction extends StructureAction
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

        $module = $this->requireModule($request, $args['courseId'] ?? '', $args['moduleId'] ?? '');

        $lessons = $this->lessons->forModule(
            $this->tenantId($request),
            $module->id,
            $this->activeOnly($request),
        );

        return Json::ok($response, array_map(static fn (Lesson $l): array => $l->toArray(), $lessons));
    }
}
