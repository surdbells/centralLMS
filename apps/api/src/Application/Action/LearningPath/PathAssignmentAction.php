<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\LearningPath;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Application\Notification\Notifier;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\LearningPath\AssignmentStatus;
use Central\Api\Domain\LearningPath\LearningPathRepository;
use Central\Api\Domain\LearningPath\PathAssignment;
use Central\Api\Domain\LearningPath\PathAssignmentRepository;
use Central\Api\Domain\Notification\NotificationType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Standing rules that put people on a learning path.
 *
 * A rule is not the same as enrolling everybody in Operations this afternoon —
 * the enrolment endpoints already do that. A rule keeps going: somebody who
 * joins Operations next month is enrolled by it too. Creating one applies it
 * at once, so an administrator sees it work rather than being told it will.
 *
 * All of it needs `enrollment.manage`. Deciding what other people must study
 * is the same authority as enrolling them.
 */
final class PathAssignmentAction
{
    public function __construct(
        private readonly PathAssignmentRepository $assignments,
        private readonly LearningPathRepository $paths,
        private readonly Notifier $notifier,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::EnrollmentManage);

        $tenantId = $this->tenantId($request);
        $pathId = $this->requirePath($request, $args['id'] ?? '');

        return Json::ok($response, array_map(
            static fn (PathAssignment $a): array => $a->toArray(),
            $this->assignments->forPath($tenantId, $pathId),
        ));
    }

    /**
     * Creates a rule and runs it once.
     *
     * Applying immediately is the point: a rule that only takes effect at the
     * next sweep looks broken to whoever just made it, and the answer to "did
     * that work" should be on the screen, not tomorrow morning.
     *
     * @param array<string, string> $args
     */
    public function create(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::EnrollmentManage);

        $tenantId = $this->tenantId($request);
        $pathId = $this->requirePath($request, $args['id'] ?? '');

        $rule = $this->assignments->save(PathAssignment::fromInput(
            $this->uuid(),
            $tenantId,
            $pathId,
            $this->body($request),
            $caller->id,
        ));

        $enrolled = $this->assignments->apply($tenantId, $rule->id);
        $saved = $this->assignments->find($tenantId, $rule->id) ?? $rule;

        return Json::created($response, [
            ...$saved->toArray(),
            'enrollmentsCreated' => $enrolled,
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::EnrollmentManage);

        $tenantId = $this->tenantId($request);
        $existing = $this->requireRule($request, $args['assignmentId'] ?? '');

        $saved = $this->assignments->save(PathAssignment::fromInput(
            $existing->id,
            $tenantId,
            $existing->pathId,
            $this->body($request),
            $existing->assignedBy ?? $caller->id,
        ));

        return Json::ok($response, $saved->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::EnrollmentManage);

        $id = $args['assignmentId'] ?? '';

        if (!$this->assignments->delete($this->tenantId($request), $id)) {
            throw new EntityNotFound('Assignment rule', $id);
        }

        return Json::noContent($response);
    }

    // --- starting and stopping ------------------------------------------------

    /**
     * @param array<string, string> $args
     */
    public function pause(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->moveTo($request, $response, $args, AssignmentStatus::Paused);
    }

    /**
     * @param array<string, string> $args
     */
    public function resume(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->moveTo($request, $response, $args, AssignmentStatus::Active);
    }

    /**
     * @param array<string, string> $args
     */
    public function cancel(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->moveTo($request, $response, $args, AssignmentStatus::Cancelled);
    }

    /**
     * Runs the rule now, without waiting for the sweep.
     *
     * @param array<string, string> $args
     */
    public function apply(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::EnrollmentManage);

        $tenantId = $this->tenantId($request);
        $rule = $this->requireRule($request, $args['assignmentId'] ?? '');

        $enrolled = $this->assignments->apply($tenantId, $rule->id);

        return Json::ok($response, [
            'assignmentId'       => $rule->id,
            'enrollmentsCreated' => $enrolled,
        ]);
    }

    /**
     * Who the rule would cover, before anybody commits to it.
     *
     * @param array<string, string> $args
     */
    public function preview(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::EnrollmentManage);

        $rule = $this->requireRule($request, $args['assignmentId'] ?? '');

        return Json::ok($response, $this->assignments->preview($this->tenantId($request), $rule->id));
    }

    // --- what it has done -----------------------------------------------------

    /**
     * @param array<string, string> $args
     */
    public function statistics(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::EnrollmentManage);

        $tenantId = $this->tenantId($request);
        $rule = $this->requireRule($request, $args['assignmentId'] ?? '');

        return Json::ok($response, [
            ...$this->assignments->statistics($tenantId, $rule->id),
            'pending' => $this->assignments->pendingLearners($tenantId, $rule->id),
        ]);
    }

    /**
     * Reminds everybody this rule enrolled who has not finished.
     *
     * The screen has always had the button and it has never sent anything.
     * People who have finished are left out, because a reminder to somebody
     * who is done teaches them to ignore the next one.
     *
     * @param array<string, string> $args
     */
    public function remind(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::EnrollmentManage);

        $tenantId = $this->tenantId($request);
        $rule = $this->requireRule($request, $args['assignmentId'] ?? '');

        $pending = $this->assignments->pendingLearners($tenantId, $rule->id);
        $userIds = array_values(array_map(
            static fn (array $row): string => (string) $row['userId'],
            $pending,
        ));

        $sent = $this->notifier->notify(
            $tenantId,
            $userIds,
            NotificationType::EnrollmentDueSoon,
            [
                'subjectTitle' => $rule->pathTitle ?? 'a learning path',
                // The rule's own deadline where it has one. An offset gives
                // each person a different date, so it is left off rather than
                // stated wrongly for everybody.
                'dueDate' => $rule->dueDate ?? '',
            ],
            $rule->pathId,
        );

        return Json::ok($response, ['sent' => $sent, 'pending' => count($pending)]);
    }

    // --- internals ------------------------------------------------------------

    /**
     * @param array<string, string> $args
     */
    private function moveTo(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
        AssignmentStatus $status,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::EnrollmentManage);

        $id = $args['assignmentId'] ?? '';
        $moved = $this->assignments->setStatus($this->tenantId($request), $id, $status);

        if ($moved === null) {
            throw new EntityNotFound('Assignment rule', $id);
        }

        return Json::ok($response, $moved->toArray());
    }

    private function requireRule(ServerRequestInterface $request, string $id): PathAssignment
    {
        $rule = $this->assignments->find($this->tenantId($request), $id);

        if ($rule === null) {
            throw new EntityNotFound('Assignment rule', $id);
        }

        return $rule;
    }

    private function requirePath(ServerRequestInterface $request, string $id): string
    {
        if ($this->paths->find($this->tenantId($request), $id) === null) {
            throw new EntityNotFound('Learning path', $id);
        }

        return $id;
    }

    private function caller(ServerRequestInterface $request): AuthenticatedUser
    {
        return AuthenticationMiddleware::require($request);
    }

    private function tenantId(ServerRequestInterface $request): string
    {
        $user = $this->caller($request);

        if ($user->tenantId === null) {
            throw new AccessDenied(Permission::EnrollmentManage->value);
        }

        return $user->tenantId;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff),
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
        );
    }
}
