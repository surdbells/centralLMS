<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Enrollment;

use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Application\Notification\Notifier;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Catalog\Course;
use Central\Api\Domain\Catalog\CourseRepository;
use Central\Api\Domain\Enrollment\Enrollment;
use Central\Api\Domain\Enrollment\EnrollmentRepository;
use Central\Api\Domain\Enrollment\EnrollmentStatus;
use Central\Api\Domain\Enrollment\LessonProgressRepository;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared plumbing for the enrolment endpoints.
 */
abstract class EnrollmentAction
{
    public function __construct(
        protected readonly EnrollmentRepository $enrollments,
        protected readonly LessonProgressRepository $progress,
        protected readonly CourseRepository $courses,
        protected readonly Notifier $notifier,
    ) {
    }

    final protected function caller(ServerRequestInterface $request): AuthenticatedUser
    {
        return AuthenticationMiddleware::require($request);
    }

    /**
     * @throws AccessDenied
     */
    final protected function tenantId(ServerRequestInterface $request): string
    {
        $user = $this->caller($request);

        if ($user->tenantId === null) {
            throw new AccessDenied(Permission::EnrollmentSelf->value);
        }

        return $user->tenantId;
    }

    final protected function userId(ServerRequestInterface $request): string
    {
        return $this->caller($request)->id;
    }

    /**
     * Guards a read of *other people's* progress — a course roster, its
     * figures, or one learner's history.
     *
     * Deliberately not `progress.view`: every role holds that, including
     * learners, because it is what lets someone see their own progress.
     * Gating a roster on it would let any learner list every colleague on a
     * course with their name, email, completion state and whether they are
     * overdue.
     *
     * Any of three permissions will do, because three different jobs need
     * this view: instructors run their own courses (`progress.manage`),
     * administrators assign them (`enrollment.manage`), and HR and line
     * managers report on them (`report.view`).
     *
     * @throws AccessDenied
     */
    final protected function mustSeeOthersProgress(ServerRequestInterface $request): void
    {
        $allowed = [
            Permission::ProgressManage,
            Permission::EnrollmentManage,
            Permission::ReportView,
        ];

        if (!$this->caller($request)->canAny($allowed)) {
            throw new AccessDenied(Permission::ProgressManage->value);
        }
    }

    /**
     * @throws EntityNotFound
     */
    final protected function requireCourse(ServerRequestInterface $request, string $courseId): Course
    {
        return $this->courses->find($this->tenantId($request), $courseId)
            ?? throw new EntityNotFound('Course', $courseId);
    }

    /**
     * The caller's own enrolment on a course.
     *
     * @throws EntityNotFound when they are not on it
     */
    final protected function requireOwnEnrollment(ServerRequestInterface $request, string $courseId): Enrollment
    {
        $enrollment = $this->enrollments->findForUserAndCourse(
            $this->tenantId($request),
            $this->userId($request),
            $courseId,
        );

        if ($enrollment === null) {
            throw new EntityNotFound('Enrolment', $courseId);
        }

        return $enrollment;
    }

    /**
     * @return array<string, mixed>
     */
    final protected function body(ServerRequestInterface $request): array
    {
        return (array) ($request->getParsedBody() ?? []);
    }

    /**
     * @return array{page: int, perPage: int, search: ?string, status: ?EnrollmentStatus}
     */
    final protected function listing(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();
        $status = isset($query['status']) && $query['status'] !== ''
            ? EnrollmentStatus::tryFrom((string) $query['status'])
            : null;

        return [
            'page'    => max(1, (int) ($query['page'] ?? 1)),
            'perPage' => max(1, min(200, (int) ($query['perPage'] ?? 20))),
            'search'  => isset($query['search']) ? (string) $query['search'] : null,
            'status'  => $status,
        ];
    }

    final protected function flag(ServerRequestInterface $request, string $name): bool
    {
        $value = $request->getQueryParams()[$name] ?? null;

        return $value !== null && filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /**
     * @param list<Enrollment> $enrollments
     *
     * @return list<array<string, mixed>>
     */
    final protected function serialise(array $enrollments): array
    {
        return array_map(static fn (Enrollment $e): array => $e->toArray(), $enrollments);
    }
}
