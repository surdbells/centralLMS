<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\LearningPath;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\LearningPath\LearningPathRepository;
use Central\Api\Domain\LearningPath\ModerationStatus;
use Central\Api\Domain\LearningPath\PathReview;
use Central\Api\Domain\LearningPath\PathReviewRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What learners thought of a learning path, and the queue for checking it.
 *
 * Reading published reviews needs only `course.view` — they are part of the
 * path's page. Writing one needs an enrolment on that path, which is checked
 * here rather than assumed: a rating from somebody who was never on it is an
 * opinion, and the average beside a path is meant to mean more than that.
 *
 * Moderating needs `course.publish`, because approving a review is publishing
 * words under the organisation's name.
 */
final class PathReviewAction
{
    public function __construct(
        private readonly PathReviewRepository $reviews,
        private readonly LearningPathRepository $paths,
    ) {
    }

    // --- reading --------------------------------------------------------------

    /**
     * @param array<string, string> $args
     */
    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::CourseView);

        $tenantId = $this->tenantId($request);
        $pathId = $this->requirePath($request, $args['id'] ?? '');
        [$page, $perPage] = $this->listing($request);

        // A learner sees what has been approved and published. A moderator may
        // ask for one state, or for all of them — which is the only way the
        // rejected ones are ever visible again.
        $wanted = (string) ($request->getQueryParams()['status'] ?? '');

        $result = $caller->can(Permission::CoursePublish)
            ? $this->reviews->forPath(
                $tenantId,
                $pathId,
                ModerationStatus::tryFrom($wanted),
                $page,
                $perPage,
                // "Flagged" is not a state — a reported review is sent back to
                // `pending`, so without this it would be the same list as
                // "waiting to be checked".
                $wanted === 'flagged',
            )
            : $this->reviews->published($tenantId, $pathId, $page, $perPage);

        return Json::page(
            $response,
            array_map(static fn (PathReview $r): array => $r->toArray(), $result['items']),
            $result['total'],
            $page,
            $perPage,
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function summary(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::CourseView);

        return Json::ok($response, $this->reviews->summaryFor(
            $this->tenantId($request),
            $this->requirePath($request, $args['id'] ?? ''),
        ));
    }

    /**
     * The caller's own review of this path, whatever state it is in.
     *
     * Separate from the published list because a review waiting to be checked
     * is on no page — and its author, who is the one person entitled to see
     * it, would otherwise be told they had never written one.
     *
     * @param array<string, string> $args
     */
    public function mine(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::EnrollmentSelf);

        $tenantId = $this->tenantId($request);
        $pathId = $this->requirePath($request, $args['id'] ?? '');

        $enrolment = $this->paths->enrolmentFor($tenantId, $pathId, $caller->id);

        if ($enrolment === null || $enrolment->id === null) {
            return Json::ok($response, null);
        }

        $review = $this->reviews->forEnrolment($tenantId, $enrolment->id);

        return Json::ok($response, $review?->toArray());
    }

    // --- writing --------------------------------------------------------------

    /**
     * Leaves a review, or replaces the one already left for this enrolment.
     *
     * @param array<string, string> $args
     */
    public function create(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::EnrollmentSelf);

        $tenantId = $this->tenantId($request);
        $pathId = $this->requirePath($request, $args['id'] ?? '');

        $enrolment = $this->paths->enrolmentFor($tenantId, $pathId, $caller->id);

        if ($enrolment === null || $enrolment->id === null) {
            // Not a 403: whether somebody is on a path is not the point. The
            // point is that a review is something only a learner can leave.
            throw new ConflictingState(
                'You can review a path once you are on it.',
                'not_enrolled',
            );
        }

        $enrolmentId = $enrolment->id;
        $existing = $this->reviews->forEnrolment($tenantId, $enrolmentId);

        $review = PathReview::fromInput(
            $existing === null ? $this->uuid() : $existing->id,
            $tenantId,
            $pathId,
            $caller->id,
            $enrolmentId,
            $this->body($request),
        );

        $saved = $this->reviews->save($review);

        return $existing === null
            ? Json::created($response, $saved->toArray())
            : Json::ok($response, $saved->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::EnrollmentSelf);

        $tenantId = $this->tenantId($request);
        $id = $args['reviewId'] ?? '';

        // A learner may withdraw their own; a moderator may take down
        // anybody's.
        $removed = $this->reviews->delete(
            $tenantId,
            $id,
            $caller->id,
            $caller->can(Permission::CoursePublish),
        );

        if (!$removed) {
            throw new EntityNotFound('Review', $id);
        }

        return Json::noContent($response);
    }

    // --- moderation -----------------------------------------------------------

    /** GET /v1/path-reviews/pending — everything waiting, oldest first. */
    public function pending(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::CoursePublish);

        [$page, $perPage] = $this->listing($request);
        $result = $this->reviews->pending($this->tenantId($request), $page, $perPage);

        return Json::page(
            $response,
            array_map(static fn (PathReview $r): array => $r->toArray(), $result['items']),
            $result['total'],
            $page,
            $perPage,
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function approve(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::CoursePublish);

        $approved = $this->reviews->approve(
            $this->tenantId($request),
            $args['reviewId'] ?? '',
            $caller->id,
        );

        if ($approved === null) {
            throw new EntityNotFound('Review', $args['reviewId'] ?? '');
        }

        return Json::ok($response, $approved->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function reject(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::CoursePublish);

        $reason = trim((string) ($this->body($request)['reason'] ?? ''));

        if ($reason === '') {
            // The database refuses it too. "Rejected, no reason given" is the
            // message the learner would receive.
            throw ValidationFailed::fromArray(['reason' => 'Say why it was rejected.']);
        }

        $rejected = $this->reviews->reject(
            $this->tenantId($request),
            $args['reviewId'] ?? '',
            $caller->id,
            $reason,
        );

        if ($rejected === null) {
            throw new EntityNotFound('Review', $args['reviewId'] ?? '');
        }

        return Json::ok($response, $rejected->toArray());
    }

    /**
     * The path's author answering a review, in public.
     *
     * @param array<string, string> $args
     */
    public function respond(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::CourseManage);

        $text = trim((string) ($this->body($request)['response'] ?? ''));

        if ($text === '') {
            throw ValidationFailed::fromArray(['response' => 'Write a reply.']);
        }

        $answered = $this->reviews->respond(
            $this->tenantId($request),
            $args['reviewId'] ?? '',
            $caller->id,
            $text,
        );

        if ($answered === null) {
            throw new EntityNotFound('Review', $args['reviewId'] ?? '');
        }

        return Json::ok($response, $answered->toArray());
    }

    // --- votes ----------------------------------------------------------------

    /**
     * @param array<string, string> $args
     */
    public function helpful(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::EnrollmentSelf);

        $id = $this->requireReview($request, $args['reviewId'] ?? '');

        return Json::ok($response, [
            'reviewId'     => $id,
            'helpfulCount' => $this->reviews->toggleHelpful($this->tenantId($request), $id, $caller->id),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function report(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::EnrollmentSelf);

        $id = $this->requireReview($request, $args['reviewId'] ?? '');

        $reason = is_string($this->body($request)['reason'] ?? null)
            ? trim((string) $this->body($request)['reason'])
            : null;

        return Json::ok($response, [
            'reviewId'      => $id,
            // Reporting takes it off the page until somebody looks — leaving
            // it up until a moderator happens to notice is what reporting is
            // meant to prevent.
            'reportedCount' => $this->reviews->report($this->tenantId($request), $id, $caller->id, $reason),
        ]);
    }

    // --- internals ------------------------------------------------------------

    private function requirePath(ServerRequestInterface $request, string $id): string
    {
        if ($this->paths->find($this->tenantId($request), $id) === null) {
            throw new EntityNotFound('Learning path', $id);
        }

        // The id we were given, now known to name a path in this tenant.
        return $id;
    }

    private function requireReview(ServerRequestInterface $request, string $id): string
    {
        $review = $this->reviews->find($this->tenantId($request), $id);

        if ($review === null) {
            throw new EntityNotFound('Review', $id);
        }

        return $review->id;
    }

    private function caller(ServerRequestInterface $request): AuthenticatedUser
    {
        return AuthenticationMiddleware::require($request);
    }

    private function tenantId(ServerRequestInterface $request): string
    {
        $user = $this->caller($request);

        if ($user->tenantId === null) {
            throw new AccessDenied(Permission::CourseView->value);
        }

        return $user->tenantId;
    }

    /**
     * @return array{int, int}
     */
    private function listing(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();

        return [
            max(1, (int) ($query['page'] ?? 1)),
            max(1, min(100, (int) ($query['perPage'] ?? 25))),
        ];
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
