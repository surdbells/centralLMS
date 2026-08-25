<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\EntityNotFound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/courses/{id}
 */
final class ShowCourseAction extends CourseAction
{
    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $user = $this->caller($request);
        $user->mustHave(Permission::CourseView);

        $course = $this->requireCourse($request, $args['id'] ?? '');

        // A learner may read a published course. Anything else needs the
        // manage permission, so a draft is a 404 to them rather than a 403 —
        // the existence of an unreleased course is itself information.
        if (!$course->status->isVisibleToLearners() && !$user->can(Permission::CourseManage)) {
            throw new EntityNotFound('Course', $course->id);
        }

        return Json::ok($response, $course->toArray());
    }
}
