<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Catalog\Lesson;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;

/**
 * POST /v1/courses/{courseId}/modules/{moduleId}/lessons
 *
 * `courseId` is taken from the module rather than the path, so the two can
 * never be written out of step even if a client sends a mismatched pair. The
 * composite foreign key would reject it anyway; this makes the rejection
 * unnecessary.
 */
final class CreateLessonAction extends StructureAction
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
        $courseId = $args['courseId'] ?? '';
        $this->mustBeEditable($this->requireCourse($request, $courseId));

        $module = $this->requireModule($request, $courseId, $args['moduleId'] ?? '');

        $input = $this->body($request);

        if (!isset($input['sortOrder'])) {
            $input['sortOrder'] = $this->lessons->nextSortOrder($tenantId, $module->id);
        }

        $lesson = Lesson::fromInput(
            Uuid::uuid4()->toString(),
            $tenantId,
            $module->courseId,
            $module->id,
            $input,
        );

        $created = $this->lessons->create($lesson);

        return Json::created(
            $response,
            $created->toArray(),
            sprintf('/v1/courses/%s/modules/%s/lessons/%s', $module->courseId, $module->id, $created->id),
        );
    }
}
