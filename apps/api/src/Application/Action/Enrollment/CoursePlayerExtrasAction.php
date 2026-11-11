<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Enrollment;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Catalog\CourseDiscussionRepository;
use Central\Api\Domain\Enrollment\EnrollmentRepository;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The course player's "Learners" and "Discuss" tabs.
 *
 * Both hang off a course the caller is on: who else is taking it, and the
 * forum attached to it. Being enrolled is the gate for taking part; moderating
 * (pin, lock) needs `course.publish` instead, so a course's author can keep its
 * discussion tidy without being enrolled on it.
 */
final class CoursePlayerExtrasAction
{
    public function __construct(
        private readonly EnrollmentRepository $enrollments,
        private readonly CourseDiscussionRepository $discussion,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Who else is on this course, and how far each has got.
     *
     * @param array<string, string> $args
     */
    public function classmates(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->requireEnrolled($request, $args['courseId'] ?? '');
        $courseId = $args['courseId'] ?? '';

        $rows = $this->connection->fetchAllAssociative(
            "SELECT u.id,
                    TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS name,
                    u.avatar_url AS avatar,
                    e.progress_percentage AS progress,
                    e.status
             FROM enrollments e
             JOIN users u ON u.id = e.user_id
             WHERE e.course_id = :course AND e.tenant_id = :tenant
               AND e.deleted_at IS NULL AND u.deleted_at IS NULL AND u.status = 'active'
             ORDER BY e.progress_percentage DESC, u.first_name
             LIMIT 100",
            ['course' => $courseId, 'tenant' => $tenantId],
        );

        return Json::ok($response, array_map(
            static fn (array $r): array => [
                'userId'   => (string) $r['id'],
                'name'     => trim((string) $r['name']) ?: 'Learner',
                'avatarUrl' => is_string($r['avatar'] ?? null) && $r['avatar'] !== '' ? $r['avatar'] : null,
                'progress' => (int) round((float) $r['progress']),
                'status'   => (string) $r['status'],
            ],
            $rows,
        ));
    }

    /**
     * @param array<string, string> $args
     */
    public function discussion(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->requireEnrolled($request, $args['courseId'] ?? '');

        return Json::ok($response, $this->discussion->thread($tenantId, $args['courseId'] ?? '', $this->caller($request)->id));
    }

    /**
     * @param array<string, string> $args
     */
    public function post(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller   = $this->caller($request);
        $tenantId = $this->requireEnrolled($request, $args['courseId'] ?? '');
        $body     = $this->body($request);

        $text     = trim((string) ($body['body'] ?? ''));
        $parentId = $this->uuidOrNull($body['parentId'] ?? null);

        if ($text === '') {
            throw ValidationFailed::fromArray(['body' => 'Write something first.']);
        }

        // A locked thread takes no new replies; a lock on the question still
        // lets the question itself stand.
        if ($parentId !== null && $this->discussion->isLocked($tenantId, $parentId)) {
            throw ValidationFailed::fromArray(['thread' => 'This thread is locked.']);
        }

        $post = $this->discussion->post(
            $tenantId,
            $args['courseId'] ?? '',
            $caller->id,
            $text,
            $parentId,
            $this->uuidOrNull($body['lessonId'] ?? null),
        );

        return Json::created($response, $post);
    }

    /**
     * @param array<string, string> $args
     */
    public function edit(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller   = $this->caller($request);
        $tenantId = $this->requireEnrolled($request, $args['courseId'] ?? '');
        $text     = trim((string) ($this->body($request)['body'] ?? ''));

        if ($text === '') {
            throw ValidationFailed::fromArray(['body' => 'Write something first.']);
        }

        $post = $this->discussion->edit(
            $tenantId,
            $args['postId'] ?? '',
            $caller->id,
            $text,
            $caller->can(Permission::CoursePublish),
        );

        if ($post === null) {
            throw new EntityNotFound('Post', $args['postId'] ?? '');
        }

        return Json::ok($response, $post);
    }

    /**
     * @param array<string, string> $args
     */
    public function deletePost(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller   = $this->caller($request);
        $tenantId = $this->requireEnrolled($request, $args['courseId'] ?? '');
        $id       = $args['postId'] ?? '';

        $removed = $this->discussion->delete(
            $tenantId,
            $id,
            $caller->id,
            $caller->can(Permission::CoursePublish),
        );

        if (!$removed) {
            throw new EntityNotFound('Post', $id);
        }

        return Json::noContent($response);
    }

    /**
     * Toggles one of the caller's reactions on a post (body: {kind}).
     *
     * @param array<string, string> $args
     */
    public function react(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller   = $this->caller($request);
        $tenantId = $this->requireEnrolled($request, $args['courseId'] ?? '');
        $kind     = (string) ($this->body($request)['kind'] ?? '');

        if (!in_array($kind, CourseDiscussionRepository::REACTIONS, true)) {
            throw ValidationFailed::fromArray(['kind' => 'Unknown reaction.']);
        }

        $reacted = $this->discussion->react($tenantId, $args['postId'] ?? '', $caller->id, $kind);

        return Json::ok($response, ['kind' => $kind, 'reacted' => $reacted]);
    }

    /**
     * Marks a reply as the thread's accepted answer, or clears it.
     *
     * @param array<string, string> $args
     */
    public function answer(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller   = $this->caller($request);
        $tenantId = $this->requireEnrolled($request, $args['courseId'] ?? '');

        $ok = $this->discussion->setAnswer(
            $tenantId,
            $args['postId'] ?? '',
            $caller->id,
            $caller->can(Permission::CoursePublish),
        );

        if (!$ok) {
            throw new AccessDenied(Permission::EnrollmentSelf->value);
        }

        return Json::noContent($response);
    }

    /**
     * Pins/unpins a top-level post (moderator). Body: {value: bool}.
     *
     * @param array<string, string> $args
     */
    public function pin(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->requireModerator($request);
        $value    = filter_var($this->body($request)['value'] ?? true, FILTER_VALIDATE_BOOL);

        $this->discussion->setPinned($tenantId, $args['postId'] ?? '', $value);

        return Json::noContent($response);
    }

    /**
     * Locks/unlocks a thread against new replies (moderator). Body: {value: bool}.
     *
     * @param array<string, string> $args
     */
    public function lock(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->requireModerator($request);
        $value    = filter_var($this->body($request)['value'] ?? true, FILTER_VALIDATE_BOOL);

        $this->discussion->setLocked($tenantId, $args['postId'] ?? '', $value);

        return Json::noContent($response);
    }

    // --- internals ------------------------------------------------------------

    /**
     * Confirms the caller is on this course, and returns the tenant.
     *
     * A 404, not a 403: whether a course exists and whether you are on it are
     * the same answer to somebody who is not, so probing ids teaches nothing.
     */
    private function requireEnrolled(ServerRequestInterface $request, string $courseId): string
    {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::EnrollmentSelf);

        if ($caller->tenantId === null) {
            throw new AccessDenied(Permission::EnrollmentSelf->value);
        }

        $enrolment = $this->enrollments->findForUserAndCourse($caller->tenantId, $caller->id, $courseId);

        if ($enrolment === null) {
            throw new EntityNotFound('Course', $courseId);
        }

        return $caller->tenantId;
    }

    /** Moderating does not require enrolment — a course's author keeps its forum tidy. */
    private function requireModerator(ServerRequestInterface $request): string
    {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::CoursePublish);

        if ($caller->tenantId === null) {
            throw new AccessDenied(Permission::CoursePublish->value);
        }

        return $caller->tenantId;
    }

    private function caller(ServerRequestInterface $request): AuthenticatedUser
    {
        return AuthenticationMiddleware::require($request);
    }

    private function uuidOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }
}
