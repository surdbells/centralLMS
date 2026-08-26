<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Enrollment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Enrollment\LessonProgress;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/me/enrollments/{courseId}
 *
 * The learner's enrolment plus their progress on every lesson, keyed by lesson
 * id so the player can merge it into the outline without searching.
 */
final class MyCourseProgressAction extends EnrollmentAction
{
    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::EnrollmentSelf);

        $enrollment = $this->requireOwnEnrollment($request, $args['courseId'] ?? '');

        $progress = $this->progress->forEnrollment($this->tenantId($request), $enrollment->id);

        return Json::ok($response, [
            'enrollment' => $enrollment->toArray(),
            'lessons'    => array_map(
                static fn (LessonProgress $p): array => $p->toArray(),
                $progress,
            ),
            'completedLessons' => count(array_filter(
                $progress,
                static fn (LessonProgress $p): bool => $p->status->isComplete(),
            )),
        ]);
    }
}
