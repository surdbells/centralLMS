<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Enrollment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Enrollment\AssignmentRequest;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v1/courses/{courseId}/enroll
 *
 * A learner putting themselves on a course from the catalogue.
 *
 * Every gate is checked here rather than trusted from the catalogue listing:
 * the course must be published, must allow self-enrolment, must be inside its
 * window, and must have room.
 */
final class SelfEnrollAction extends EnrollmentAction
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

        $tenantId = $this->tenantId($request);
        $userId = $this->userId($request);
        $courseId = $args['courseId'] ?? '';

        $course = $this->requireCourse($request, $courseId);

        if (!$course->status->isVisibleToLearners()) {
            // Not a 403: an unpublished course should not be discoverable by
            // watching which ids give which error.
            throw new EntityNotFound('Course', $courseId);
        }

        $now = new \DateTimeImmutable();

        if (!$course->isOpenForSelfEnrollment($now)) {
            throw new ConflictingState(
                $course->allowSelfEnrollment
                    ? 'This course is not open for enrolment at the moment.'
                    : 'This course is assigned by an administrator, not self-service.',
                'self_enrollment_closed',
            );
        }

        $existing = $this->enrollments->findForUserAndCourse($tenantId, $userId, $courseId);

        if ($existing !== null && $existing->status->grantsAccess()) {
            // Idempotent rather than an error: a learner who double-clicks
            // Enrol should land on the course, not on a failure page.
            return Json::ok($response, $existing->toArray());
        }

        // The limit is checked here and not in the domain object because it
        // needs a live count — see Course::isOpenForSelfEnrollment().
        if ($course->enrollmentLimit !== null) {
            $stats = $this->enrollments->statsForCourse($tenantId, $courseId);
            $taken = $stats['total'] - $stats['dropped'];

            if ($taken >= $course->enrollmentLimit) {
                throw new ConflictingState(
                    'This course is full.',
                    'course_full',
                );
            }
        }

        $this->enrollments->assign(
            $tenantId,
            $courseId,
            AssignmentRequest::selfEnrolment($userId),
            // No assigning administrator: they did this themselves.
            null,
        );

        $enrollment = $this->enrollments->findForUserAndCourse($tenantId, $userId, $courseId)
            ?? throw new \RuntimeException('Enrolment could not be read back.');

        return Json::created(
            $response,
            $enrollment->toArray(),
            '/v1/me/enrollments/' . $courseId,
        );
    }
}
