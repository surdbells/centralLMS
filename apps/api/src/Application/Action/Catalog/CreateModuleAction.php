<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Catalog\CourseModule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;

/**
 * POST /v1/courses/{courseId}/modules
 *
 * A module with no explicit position goes to the end. Defaulting to 0 instead
 * would stack every new module on top of the first one and leave the order
 * decided by the tie-break.
 */
final class CreateModuleAction extends StructureAction
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

        $tenantId = $this->tenantId($request);
        $course = $this->requireCourse($request, $args['courseId'] ?? '');
        $this->mustBeEditable($course);

        $input = $this->body($request);

        if (!isset($input['sortOrder'])) {
            $input['sortOrder'] = $this->modules->nextSortOrder($tenantId, $course->id);
        }

        $module = CourseModule::fromInput(Uuid::uuid4()->toString(), $tenantId, $course->id, $input);
        $created = $this->modules->create($module);

        return Json::created(
            $response,
            $created->toArray(),
            sprintf('/v1/courses/%s/modules/%s', $course->id, $created->id),
        );
    }
}
