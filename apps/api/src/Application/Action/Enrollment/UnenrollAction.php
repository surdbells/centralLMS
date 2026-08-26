<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Enrollment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\ValidationFailed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /v1/courses/{courseId}/enrollments
 *
 * Body: `{"userIds": [...], "reason": "…"}`.
 *
 * Takes learners off a course without destroying what they did. Their progress
 * stays on the row, so reinstating them later does not start them at zero, and
 * the audit trail records who removed them and why.
 */
final class UnenrollAction extends EnrollmentAction
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

        $body = $this->body($request);
        $raw = $body['userIds'] ?? null;

        if (!is_array($raw) || $raw === []) {
            throw ValidationFailed::fromArray(['userIds' => 'Name who to remove.']);
        }

        $userIds = [];

        foreach ($raw as $id) {
            $id = is_scalar($id) ? trim((string) $id) : '';

            if ($id !== '') {
                $userIds[] = $id;
            }
        }

        $userIds = array_values(array_unique($userIds));

        if ($userIds === []) {
            throw ValidationFailed::fromArray(['userIds' => 'Name who to remove.']);
        }

        $reason = isset($body['reason']) && is_string($body['reason']) && trim($body['reason']) !== ''
            ? trim($body['reason'])
            : null;

        $removed = $this->enrollments->unenroll(
            $this->tenantId($request),
            $course->id,
            $userIds,
            $this->userId($request),
            $reason,
        );

        return Json::ok($response, [
            'courseId' => $course->id,
            'removed'  => count($removed),
            // Anyone named who was not on the course, or was already off it.
            'notEnrolled' => array_values(array_diff($userIds, $removed)),
        ]);
    }
}
