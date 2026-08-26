<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Enrollment;

use Central\Api\Application\Http\Json;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/courses/{courseId}/enrollments
 *
 * Who is on this course, with `?status=`, `?search=` and `?overdue=true`.
 * Names and emails come from the same query, so a page of 50 learners is one
 * round trip rather than 51.
 */
final class ListCourseEnrollmentsAction extends EnrollmentAction
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

        $course = $this->requireCourse($request, $args['courseId'] ?? '');
        $listing = $this->listing($request);

        $result = $this->enrollments->forCourse(
            $this->tenantId($request),
            $course->id,
            $listing['page'],
            $listing['perPage'],
            $listing['status'],
            $listing['search'],
            $this->flag($request, 'overdue'),
        );

        return Json::page(
            $response,
            $this->serialise($result['items']),
            $result['total'],
            $listing['page'],
            $listing['perPage'],
        );
    }
}
