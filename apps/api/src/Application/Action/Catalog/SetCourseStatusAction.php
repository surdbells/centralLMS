<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Catalog\CourseStatus;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /v1/courses/{id}/status
 *
 * Publishing needs `course.publish`, which instructors do not hold — they can
 * write a course but not release it. That split existed in the legacy
 * permission list and is worth keeping.
 */
final class SetCourseStatusAction extends CourseAction
{
    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::CoursePublish);

        $tenantId = $this->tenantId($request);
        $course = $this->requireCourse($request, $args['id'] ?? '');

        $requested = (string) ($this->body($request)['status'] ?? '');
        $status = CourseStatus::tryFrom(strtolower(trim($requested)));

        if ($status === null) {
            throw ValidationFailed::fromArray([
                'status' => 'Choose one of: ' . implode(', ', array_map(
                    static fn (CourseStatus $s): string => $s->value,
                    CourseStatus::cases(),
                )),
            ]);
        }

        if ($status === CourseStatus::Published && !$this->courses->hasActiveLessons($tenantId, $course->id)) {
            // Publishing an empty course puts something in the catalogue that
            // a learner can enrol on and then find nothing to do.
            throw new ConflictingState(
                'This course has no lessons yet, so there would be nothing to complete.',
                'course_empty',
            );
        }

        $updated = $this->courses->setStatus($tenantId, $course->id, $status);

        return $updated === null
            ? throw new EntityNotFound('Course', $course->id)
            : Json::ok($response, $updated->toArray());
    }
}
