<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Catalog\Lesson;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /v1/courses/{courseId}/modules/{moduleId}/lessons/{id}
 */
final class UpdateLessonAction extends StructureAction
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

        $existing = $this->requireLesson($request, $courseId, $moduleId, $args['id'] ?? '');

        $lesson = Lesson::fromInput(
            $existing->id,
            $this->tenantId($request),
            $existing->courseId,
            $existing->moduleId,
            [
                // The current state is the base, so a form that edits only the
                // title leaves everything else alone. Derived from the entity
                // rather than listed by hand: a hand-written list silently
                // drops whichever field it forgets, and `durationMinutes` and
                // `description` were exactly that.
                ...$existing->toArray(),
                ...$this->body($request),
            ],
        );

        return Json::ok($response, $this->lessons->update($lesson)->toArray());
    }
}
