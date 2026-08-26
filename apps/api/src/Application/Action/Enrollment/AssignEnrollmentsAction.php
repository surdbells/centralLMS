<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Enrollment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Enrollment\AssignmentRequest;
use Central\Api\Domain\Exception\ConflictingState;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v1/courses/{courseId}/enrollments
 *
 * Puts people on a course: a list of learners, or everyone in a department,
 * team or branch.
 *
 * Partial success is the normal outcome and is reported rather than hidden.
 * Assigning a 200-person branch where 30 are already enrolled should enrol the
 * other 170 and say so — the legacy version looped one query per learner and
 * could fail halfway, leaving the caller to guess what had landed.
 */
final class AssignEnrollmentsAction extends EnrollmentAction
{
    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::EnrollmentManage);

        $course = $this->requireCourse($request, $args['courseId'] ?? '');

        if (!$course->status->allowsExistingLearnersToContinue()) {
            // A draft course has nothing to enrol onto: its lessons are still
            // being written and its catalogue entry does not exist yet.
            throw new ConflictingState(
                'Publish the course before assigning it to anyone.',
                'course_not_published',
            );
        }

        $assignment = AssignmentRequest::fromInput($this->body($request));

        $outcome = $this->enrollments->assign(
            $this->tenantId($request),
            $course->id,
            $assignment,
            $this->userId($request),
        );

        $changed = count($outcome['enrolled']) + count($outcome['reinstated']);

        return Json::ok($response, [
            'courseId'  => $course->id,
            'source'    => $assignment->source->value,
            'enrolled'  => count($outcome['enrolled']),
            // Previously dropped learners come back with their progress
            // intact, which is a different event from a fresh enrolment.
            'reinstated'      => count($outcome['reinstated']),
            'alreadyEnrolled' => count($outcome['alreadyEnrolled']),
            // Ids the caller sent that are not users in this tenant. Reported
            // rather than silently dropped, so a stale list is visible.
            'notFound'        => $outcome['notFound'],
            'summary'         => $this->summarise($changed, $outcome),
        ]);
    }

    /**
     * @param array{enrolled: list<string>, reinstated: list<string>, alreadyEnrolled: list<string>, notFound: list<string>} $outcome
     */
    private function summarise(int $changed, array $outcome): string
    {
        if ($changed === 0 && $outcome['alreadyEnrolled'] !== []) {
            return 'Everyone named was already on this course.';
        }

        if ($changed === 0) {
            return 'Nobody was enrolled.';
        }

        $parts = [sprintf('%d %s enrolled', $changed, $changed === 1 ? 'person' : 'people')];

        if ($outcome['alreadyEnrolled'] !== []) {
            $parts[] = sprintf('%d already on it', count($outcome['alreadyEnrolled']));
        }

        if ($outcome['notFound'] !== []) {
            $parts[] = sprintf('%d not found', count($outcome['notFound']));
        }

        return implode(', ', $parts) . '.';
    }
}
