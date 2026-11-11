<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Enrollment;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Application\Notification\Notifier;
use Central\Api\Domain\Notification\NotificationType;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Catalog\CourseDiscussionRepository;
use Central\Api\Domain\Enrollment\EnrollmentRepository;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Support\HtmlSanitizer;
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
        private readonly HtmlSanitizer $html,
        private readonly Notifier $notifier,
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
        $caller   = $this->caller($request);
        $tenantId = $this->requireEnrolled($request, $args['courseId'] ?? '');

        return Json::ok($response, $this->discussion->thread(
            $tenantId,
            $args['courseId'] ?? '',
            $caller->id,
            $caller->can(Permission::CoursePublish),
        ));
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

        $text     = $this->cleanBody((string) ($body['body'] ?? ''));
        $parentId = $this->uuidOrNull($body['parentId'] ?? null);

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

        // Reply and @mention notifications. Never let a notification failure
        // lose the post that was just written.
        try {
            $this->notifyAboutPost(
                $tenantId,
                $args['courseId'] ?? '',
                $caller->id,
                is_string($post['authorName'] ?? null) ? $post['authorName'] : 'Someone',
                $parentId,
                $text,
            );
        } catch (\Throwable) {
            // Swallowed on purpose: the post stands regardless.
        }

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
        $text     = $this->cleanBody((string) ($this->body($request)['body'] ?? ''));

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

    /**
     * Reports a post for a moderator to look at (any reader). Body: {reason}.
     *
     * @param array<string, string> $args
     */
    public function report(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller   = $this->caller($request);
        $tenantId = $this->requireEnrolled($request, $args['courseId'] ?? '');
        $reason   = trim((string) ($this->body($request)['reason'] ?? ''));
        $reason   = mb_substr($reason, 0, 500);

        if (!$this->discussion->report($tenantId, $args['postId'] ?? '', $caller->id, $reason)) {
            throw new EntityNotFound('Post', $args['postId'] ?? '');
        }

        return Json::noContent($response);
    }

    /**
     * Hides or unhides a post (moderator). Body: {value: bool}.
     *
     * @param array<string, string> $args
     */
    public function hide(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->requireModerator($request);
        $value    = filter_var($this->body($request)['value'] ?? true, FILTER_VALIDATE_BOOL);

        $this->discussion->setHidden($tenantId, $args['postId'] ?? '', $value);

        return Json::noContent($response);
    }

    /**
     * Dismisses the open reports on a post without hiding it (moderator).
     *
     * @param array<string, string> $args
     */
    public function dismissReports(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->requireModerator($request);

        $this->discussion->dismissReports($tenantId, $args['postId'] ?? '');

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

    /**
     * Sanitizes a post body to the forum's allowlist and refuses an empty one.
     *
     * "Empty" is judged on the text that survives, not the markup: a body of
     * only whitespace or stray tags carries no message.
     */
    private function cleanBody(string $raw): string
    {
        $clean = $this->html->clean($raw);

        if (trim(html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8')) === '') {
            throw ValidationFailed::fromArray(['body' => 'Write something first.']);
        }

        return $clean;
    }

    /**
     * Raises reply and @mention notifications for a newly written post.
     *
     * The author of the post being answered hears about the reply; anyone
     * named with @ hears about the mention, except the author themselves and
     * the reply's target (who already got the louder reply notice).
     */
    private function notifyAboutPost(
        string $tenantId,
        string $courseId,
        string $actorId,
        string $actorName,
        ?string $parentId,
        string $html,
    ): void {
        if ($courseId === '') {
            return;
        }

        $courseTitle = $this->connection->fetchOne(
            'SELECT title FROM courses WHERE id = :id AND tenant_id = :tenant',
            ['id' => $courseId, 'tenant' => $tenantId],
        );

        $data = [
            'subjectTitle' => is_string($courseTitle) && $courseTitle !== '' ? $courseTitle : 'the course',
            'actorName'    => $actorName,
            'snippet'      => $this->snippet($html),
            'courseId'     => $courseId,
        ];

        $replyTarget = null;
        if ($parentId !== null) {
            $found = $this->connection->fetchOne(
                'SELECT user_id FROM course_discussion_posts
                 WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                ['id' => $parentId, 'tenant' => $tenantId],
            );
            $replyTarget = is_string($found) ? $found : null;
        }

        if ($replyTarget !== null && $replyTarget !== $actorId) {
            $this->notifier->notify($tenantId, [$replyTarget], NotificationType::ForumReply, $data, $courseId);
        }

        $mentioned = array_values(array_filter(
            $this->resolveMentions($tenantId, $courseId, $html),
            static fn (string $id): bool => $id !== $actorId && $id !== $replyTarget,
        ));

        if ($mentioned !== []) {
            $this->notifier->notify($tenantId, $mentioned, NotificationType::ForumMention, $data, $courseId);
        }
    }

    /** A one-line plain-text quote of a post body, for a notification. */
    private function snippet(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if (mb_strlen($text) > 120) {
            $text = rtrim(mb_substr($text, 0, 117)) . '…';
        }

        return $text;
    }

    /**
     * Resolves @mentions in a body to user ids by matching the people on the
     * course by name. A full "@First Last" is preferred; "@First" is a
     * fallback, so a shared first name can notify more than one person — a
     * deliberate err-wide-rather-than-miss for a v1 heuristic.
     *
     * @return list<string>
     */
    private function resolveMentions(string $tenantId, string $courseId, string $html): array
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (!str_contains($text, '@')) {
            return [];
        }

        $people = $this->connection->fetchAllAssociative(
            "SELECT DISTINCT u.id,
                    TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS full_name,
                    u.first_name
             FROM users u
             WHERE u.tenant_id = :tenant AND u.deleted_at IS NULL AND u.status = 'active'
               AND (
                   u.id IN (SELECT user_id FROM enrollments
                            WHERE course_id = :course AND tenant_id = :tenant AND deleted_at IS NULL)
                   OR u.id IN (SELECT instructor_id FROM courses WHERE id = :course AND tenant_id = :tenant)
                   OR u.id IN (SELECT created_by FROM courses WHERE id = :course AND tenant_id = :tenant)
               )",
            ['course' => $courseId, 'tenant' => $tenantId],
        );

        $haystack = ' ' . mb_strtolower($text) . ' ';
        $matched  = [];

        foreach ($people as $p) {
            $id = (string) $p['id'];
            foreach ([(string) $p['full_name'], (string) $p['first_name']] as $name) {
                $name = trim($name);
                if ($name !== '' && str_contains($haystack, '@' . mb_strtolower($name))) {
                    $matched[$id] = true;
                    break;
                }
            }
        }

        return array_keys($matched);
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
