<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\EntityNotFound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /v1/courses/{courseId}/modules/{moduleId}/lessons/{id}
 *
 * Soft-deletes, for the same reason courses do: progress rows and
 * certificates reference lessons, and a completion record pointing at a row
 * that no longer exists cannot be explained to an auditor.
 */
final class DeleteLessonAction extends StructureAction
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
        $moduleId = $args['moduleId'] ?? '';
        $this->mustBeEditable($this->requireCourse($request, $courseId));

        $lesson = $this->requireLesson($request, $courseId, $moduleId, $args['id'] ?? '');

        return $this->lessons->softDelete($this->tenantId($request), $moduleId, $lesson->id)
            ? Json::noContent($response)
            : throw new EntityNotFound('Lesson', $lesson->id);
    }
}
