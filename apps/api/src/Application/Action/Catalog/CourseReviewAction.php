<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Catalog\CourseReview;
use Central\Api\Domain\Catalog\CourseReviewRepository;
use Central\Api\Domain\Enrollment\EnrollmentRepository;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Learner evaluations of a course — the 5-star rating.
 *
 * GET    /v1/courses/{courseId}/reviews        the published reviews + summary
 * GET    /v1/courses/{courseId}/review/mine    the caller's own, if any
 * PUT    /v1/courses/{courseId}/review         leave or change the caller's own
 * DELETE /v1/courses/{courseId}/review         remove the caller's own
 * PATCH  /v1/courses/reviews/{id}/visibility   moderator hides/republishes one
 *
 * A review names the enrolment it came from, so only somebody who was enrolled
 * can leave one, and only one per enrolment. The average is recomputed onto the
 * course from its published reviews.
 */
final class CourseReviewAction
{
    public function __construct(
        private readonly CourseReviewRepository $reviews,
        private readonly EnrollmentRepository $enrollments,
    ) {
    }

    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller   = AuthenticationMiddleware::require($request);
        $courseId = (string) ($args['courseId'] ?? '');
        $query    = $request->getQueryParams();

        $page    = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['perPage'] ?? 20);

        $result = $this->reviews->published($caller->tenantId ?? '', $courseId, $page, $perPage);

        return Json::ok($response, [
            'summary' => $this->reviews->summaryFor($caller->tenantId ?? '', $courseId),
            'items'   => array_map(static fn (CourseReview $r): array => $r->toArray(), $result['items']),
            'total'   => $result['total'],
            'page'    => $page,
        ]);
    }

    /**
     * Every course the caller has rated — so a learner's own screens can show
     * which courses they have already evaluated.
     */
    public function mineAll(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $caller  = AuthenticationMiddleware::require($request);
        $reviews = $this->reviews->forUser((string) $caller->tenantId, (string) $caller->id);

        return Json::ok($response, array_map(static fn (CourseReview $r): array => $r->toArray(), $reviews));
    }

    /**
     * The caller's own review for the course, plus whether they may leave one.
     */
    public function mine(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller     = AuthenticationMiddleware::require($request);
        $courseId   = (string) ($args['courseId'] ?? '');
        $enrollment = $this->callerEnrollment($request, $courseId, false);

        $review = $enrollment === null
            ? null
            : $this->reviews->forEnrolment((string) $caller->tenantId, $enrollment->id);

        return Json::ok($response, [
            'canReview' => $enrollment !== null,
            'review'    => $review?->toArray(),
        ]);
    }

    public function submit(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller     = AuthenticationMiddleware::require($request);
        $courseId   = (string) ($args['courseId'] ?? '');
        $enrollment = $this->callerEnrollment($request, $courseId, true);
        $body       = (array) ($request->getParsedBody() ?? []);

        // Keep the id of an existing review so a re-submit updates it; the
        // unique index on enrolment would otherwise refuse a second row.
        $existing = $this->reviews->forEnrolment((string) $caller->tenantId, $enrollment->id);

        $review = CourseReview::fromInput(
            id: $existing->id ?? '',
            tenantId: (string) $caller->tenantId,
            courseId: $courseId,
            userId: (string) $caller->id,
            enrollmentId: $enrollment->id,
            input: $body,
        );

        $saved = $this->reviews->save($review);

        return Json::ok($response, [
            'review'  => $saved->toArray(),
            'summary' => $this->reviews->summaryFor((string) $caller->tenantId, $courseId),
        ]);
    }

    public function remove(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller     = AuthenticationMiddleware::require($request);
        $courseId   = (string) ($args['courseId'] ?? '');
        $enrollment = $this->callerEnrollment($request, $courseId, false);

        $review = $enrollment === null
            ? null
            : $this->reviews->forEnrolment((string) $caller->tenantId, $enrollment->id);

        if ($review !== null) {
            $this->reviews->delete((string) $caller->tenantId, $review->id, (string) $caller->id, false);
        }

        return Json::noContent($response);
    }

    /**
     * Moderator hides or republishes a review (body: {published: bool}).
     */
    public function setVisibility(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = AuthenticationMiddleware::require($request);
        $caller->mustHave(Permission::CourseManage);

        $id        = (string) ($args['id'] ?? '');
        $body      = (array) ($request->getParsedBody() ?? []);
        $published = filter_var($body['published'] ?? true, FILTER_VALIDATE_BOOL);

        $review = $this->reviews->setPublished((string) $caller->tenantId, $id, $published, (string) $caller->id);

        if ($review === null) {
            throw new EntityNotFound('Course review', $id);
        }

        return Json::ok($response, $review->toArray());
    }

    // --- internals ------------------------------------------------------------

    /**
     * The caller's enrolment on the course. When $required and there is none
     * (or it was dropped), refuses — only somebody who took the course evaluates
     * it.
     */
    private function callerEnrollment(
        ServerRequestInterface $request,
        string $courseId,
        bool $required,
    ): ?\Central\Api\Domain\Enrollment\Enrollment {
        $caller     = AuthenticationMiddleware::require($request);
        $enrollment = $this->enrollments->findForUserAndCourse((string) $caller->tenantId, (string) $caller->id, $courseId);

        $usable = $enrollment !== null && $enrollment->status->value !== 'dropped';

        if (!$usable && $required) {
            // Only somebody who took the course evaluates it.
            throw new AccessDenied(Permission::CourseView->value);
        }

        return $usable ? $enrollment : null;
    }
}
