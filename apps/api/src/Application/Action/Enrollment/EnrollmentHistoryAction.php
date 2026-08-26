<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Enrollment;

use Central\Api\Application\Http\Json;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/courses/{courseId}/enrollments/{userId}/history
 *
 * When this person was put on the course, by whom, how, and when they were
 * taken off again. The current row cannot answer any of that, and it is
 * exactly what is asked when a compliance deadline is missed.
 */
final class EnrollmentHistoryAction extends EnrollmentAction
{
    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->mustSeeOthersProgress($request);

        $tenantId = $this->tenantId($request);
        $course = $this->requireCourse($request, $args['courseId'] ?? '');
        $userId = $args['userId'] ?? '';

        return Json::ok($response, [
            'courseId' => $course->id,
            'userId'   => $userId,
            // The enrolment may be gone from the live list while its history
            // remains, so this is not gated on finding one.
            'enrollment' => $this->enrollments
                ->findForUserAndCourse($tenantId, $userId, $course->id)
                ?->toArray(),
            'events' => $this->enrollments->history($tenantId, $course->id, $userId),
        ]);
    }
}
