<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Feedback;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Feedback\BehaviourFeedbackRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A line manager's behaviour-change feedback on their reports' training (L3).
 *
 * GET /v1/me/team/feedback    the manager's reports × completed courses, with
 *                             any rating already left
 * PUT /v1/me/team/feedback    rate a report on a course {userId, courseId,
 *                             rating, comments?}
 *
 * No admin permission: a manager acts only on their own direct reports, which
 * the subject check enforces.
 */
final class TeamFeedbackAction
{
    public function __construct(private readonly BehaviourFeedbackRepository $feedback)
    {
    }

    public function pending(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller = AuthenticationMiddleware::require($request);

        return Json::ok($response, $this->feedback->pendingFor((string) $caller->tenantId, (string) $caller->id));
    }

    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller   = AuthenticationMiddleware::require($request);
        $body     = (array) ($request->getParsedBody() ?? []);
        $userId   = (string) ($body['userId'] ?? '');
        $courseId = (string) ($body['courseId'] ?? '');
        $rating   = (int) ($body['rating'] ?? 0);
        $comments = is_string($body['comments'] ?? null) ? trim((string) $body['comments']) : null;

        $violations = [];

        if ($rating < 1 || $rating > 5) {
            $violations['rating'] = 'Rate behaviour change from one to five.';
        }
        if ($courseId === '' || $userId === '') {
            $violations['courseId'] = 'Name the person and the course.';
        }
        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        // Only a direct report of the caller may be evaluated by them.
        if (!$this->feedback->isReport((string) $caller->tenantId, (string) $caller->id, $userId)) {
            throw new AccessDenied(Permission::UserManage->value);
        }

        $this->feedback->saveForCourse(
            (string) $caller->tenantId,
            (string) $caller->id,
            $userId,
            $courseId,
            $rating,
            $comments === '' ? null : $comments,
        );

        return Json::noContent($response);
    }
}
