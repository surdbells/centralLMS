<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/courses/{courseId}/modules/{id}
 *
 * Includes the module's lessons — a module on its own is a title, and every
 * caller that wants one wants its contents.
 */
final class ShowModuleAction extends StructureAction
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

        $module = $this->requireModule($request, $args['courseId'] ?? '', $args['id'] ?? '');

        $lessons = $this->lessons->forModule(
            $this->tenantId($request),
            $module->id,
            $this->activeOnly($request),
        );

        return Json::ok($response, $module->withLessons($lessons)->toArray(withLessons: true));
    }
}
