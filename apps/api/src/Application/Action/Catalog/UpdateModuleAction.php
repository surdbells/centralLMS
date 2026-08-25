<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Catalog\CourseModule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /v1/courses/{courseId}/modules/{id}
 */
final class UpdateModuleAction extends StructureAction
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

        $courseId = $args['courseId'] ?? '';
        $course = $this->requireCourse($request, $courseId);
        $this->mustBeEditable($course);

        $existing = $this->requireModule($request, $courseId, $args['id'] ?? '');

        $module = CourseModule::fromInput(
            $existing->id,
            $this->tenantId($request),
            $course->id,
            [
                // The current state is the base, so editing a title through a
                // form that knows nothing about ordering does not move the
                // module to the top or drop its description. Derived from the
                // entity so it cannot fall behind as fields are added.
                ...$existing->toArray(),
                ...$this->body($request),
            ],
        );

        return Json::ok($response, $this->modules->update($module)->toArray());
    }
}
