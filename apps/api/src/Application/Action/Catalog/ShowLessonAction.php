<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/courses/{courseId}/modules/{moduleId}/lessons/{id}
 *
 * Authoring read. Delivering a lesson to an enrolled learner is the
 * enrolments module's job — it has to check the enrolment and record progress,
 * neither of which belongs here.
 */
final class ShowLessonAction extends StructureAction
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

        $lesson = $this->requireLesson(
            $request,
            $args['courseId'] ?? '',
            $args['moduleId'] ?? '',
            $args['id'] ?? '',
        );

        return Json::ok($response, $lesson->toArray());
    }
}
