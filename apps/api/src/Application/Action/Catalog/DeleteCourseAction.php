<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Catalog\CourseStatus;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /v1/courses/{id}
 *
 * Soft, and refused while anybody is enrolled.
 *
 * A course is not a document. Enrolments, attempts and certificates all point
 * at it, and a certificate is a record that a named person completed a named
 * course — deleting the course would leave that record pointing at nothing.
 * The legacy's `delete` ran `DELETE FROM courses WHERE course_id = ?` and the
 * rows that referenced it simply stopped resolving.
 *
 * So: if somebody is enrolled, this refuses and says how many. Archiving is
 * the answer, and the message says so. Archived courses leave the catalogue
 * and let the people already on them finish.
 */
final class DeleteCourseAction extends CourseAction
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
        $course   = $this->requireCourse($request, $args['id'] ?? '');

        $enrolled = $this->courses->enrollmentCount($tenantId, $course->id);

        if ($enrolled > 0) {
            throw new ConflictingState(
                sprintf(
                    '%d %s enrolled on "%s". Archive it instead — it leaves the catalogue and the people '
                    . 'on it can still finish.',
                    $enrolled,
                    $enrolled === 1 ? 'person is' : 'people are',
                    $course->title,
                ),
                'course_in_use',
            );
        }

        if ($course->status === CourseStatus::Published) {
            // Nobody is enrolled yet, but it is on the catalogue and somebody
            // could enrol between this check and the write. Unpublishing first
            // is one deliberate act rather than a race.
            throw new ConflictingState(
                'Unpublish or archive this course before deleting it — it is still in the catalogue.',
                'course_published',
            );
        }

        if (!$this->courses->softDelete($tenantId, $course->id)) {
            throw new EntityNotFound('Course', $course->id);
        }

        return Json::noContent($response);
    }
}
