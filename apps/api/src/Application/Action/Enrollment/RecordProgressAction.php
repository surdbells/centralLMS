<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Enrollment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Catalog\LessonRepository;
use Central\Api\Domain\Catalog\CourseRepository;
use Central\Api\Domain\Enrollment\EnrollmentRepository;
use Central\Api\Domain\Enrollment\LessonProgress;
use Central\Api\Domain\Enrollment\LessonProgressRepository;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /v1/me/enrollments/{courseId}/lessons/{lessonId}/progress
 *
 * The endpoint the player calls as a learner works through a lesson. Replaces
 * the legacy `lessons/complete.php`, which only knew about finishing.
 *
 * Body: `{"progressPercentage": 0-100}` or `{"completed": true}`, optionally
 * with `secondsSpent` (an increment, not a total), `positionSeconds` for
 * video resume, and `score`.
 *
 * Writing the lesson row and recalculating the enrolment happen in one
 * transaction, so the course percentage can never be seen disagreeing with the
 * lessons underneath it.
 */
final class RecordProgressAction extends EnrollmentAction
{
    public function __construct(
        EnrollmentRepository $enrollments,
        LessonProgressRepository $progress,
        CourseRepository $courses,
        private readonly LessonRepository $lessons,
    ) {
        parent::__construct($enrollments, $progress, $courses);
    }

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
        $courseId = $args['courseId'] ?? '';
        $lessonId = $args['lessonId'] ?? '';

        $enrollment = $this->requireOwnEnrollment($request, $courseId);

        if (!$enrollment->status->grantsAccess()) {
            throw new ConflictingState(
                'You have been taken off this course.',
                'enrollment_dropped',
            );
        }

        $lesson = $this->lessons->findInCourse($tenantId, $courseId, $lessonId);

        if ($lesson === null || !$lesson->isActive) {
            // Covers a lesson that does not exist, one from another course and
            // one that has been deactivated. All three are "not something you
            // can record progress against", and distinguishing them would let
            // a learner map another tenant's catalogue.
            throw new EntityNotFound('Lesson', $lessonId);
        }

        $report = LessonProgress::reportFromInput($this->body($request));

        $result = $this->progress->record($tenantId, $enrollment, $lesson->id, $report);

        return Json::ok($response, [
            'lesson'     => $result['progress']->toArray(),
            // Returned so the player can update the course header without a
            // second request — it changes on almost every call.
            'enrollment' => $result['enrollment']->toArray(),
        ]);
    }
}
