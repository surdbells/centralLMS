<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Enrollment;

use Central\Api\Application\Http\Json;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/courses/{courseId}/enrollments/stats
 *
 * The course dashboard's numbers, from one grouped query rather than a count
 * per status.
 */
final class CourseEnrollmentStatsAction extends EnrollmentAction
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
        $stats = $this->enrollments->statsForCourse($this->tenantId($request), $course->id);

        // Of those still on the course. Including dropped learners in the
        // denominator makes a course look worse the more people are taken off
        // it, which reads backwards on a dashboard.
        $active = $stats['total'] - $stats['dropped'];

        return Json::ok($response, [
            'courseId'       => $course->id,
            'courseTitle'    => $course->title,
            ...$stats,
            'completionRate' => $active > 0 ? round($stats['completed'] * 100 / $active, 2) : 0.0,
        ]);
    }
}
