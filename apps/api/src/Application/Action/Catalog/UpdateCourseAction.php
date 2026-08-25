<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Catalog\Course;
use Central\Api\Domain\Exception\ConflictingState;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /v1/courses/{id}
 *
 * Content only — status is not accepted here. An archived course is frozen.
 */
final class UpdateCourseAction extends CourseAction
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
        $existing = $this->requireCourse($request, $args['id'] ?? '');

        if (!$existing->status->isEditable()) {
            throw new ConflictingState(
                'An archived course cannot be edited. Move it back to draft first.',
                'course_archived',
            );
        }

        $course = Course::fromInput($existing->id, $tenantId, [
            ...$this->body($request),
            // Preserved: publishing is setStatus()'s job, and letting an
            // update change it would bypass the publish permission.
            'status' => $existing->status->value,
        ]);

        if ($this->courses->existsWithTitle($tenantId, $course->title, $course->id)) {
            throw new ConflictingState(
                sprintf('A course titled "%s" already exists.', $course->title),
                'duplicate_title',
            );
        }

        return Json::ok($response, $this->courses->update($course)->toArray());
    }
}
