<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Catalog\Course;
use Central\Api\Domain\Catalog\CourseModule;
use Central\Api\Domain\Catalog\CourseRepository;
use Central\Api\Domain\Catalog\Lesson;
use Central\Api\Domain\Catalog\LessonRepository;
use Central\Api\Domain\Catalog\ModuleRepository;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared plumbing for the module and lesson endpoints.
 *
 * Everything here walks the chain — tenant, then course, then module, then
 * lesson — and refuses at the first link that does not hold. A module id from
 * another course is as much a 404 as one from another tenant, so guessing an
 * id tells the caller nothing.
 */
abstract class StructureAction
{
    public function __construct(
        protected readonly CourseRepository $courses,
        protected readonly ModuleRepository $modules,
        protected readonly LessonRepository $lessons,
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
            throw new AccessDenied(Permission::CourseView->value);
        }

        return $user->tenantId;
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
     * @throws EntityNotFound
     */
    final protected function requireModule(
        ServerRequestInterface $request,
        string $courseId,
        string $moduleId,
    ): CourseModule {
        // The course is resolved first so a module id cannot be probed against
        // a course the caller cannot see.
        $course = $this->requireCourse($request, $courseId);

        return $this->modules->find($this->tenantId($request), $course->id, $moduleId)
            ?? throw new EntityNotFound('Module', $moduleId);
    }

    /**
     * @throws EntityNotFound
     */
    final protected function requireLesson(
        ServerRequestInterface $request,
        string $courseId,
        string $moduleId,
        string $lessonId,
    ): Lesson {
        $module = $this->requireModule($request, $courseId, $moduleId);

        return $this->lessons->find($this->tenantId($request), $module->id, $lessonId)
            ?? throw new EntityNotFound('Lesson', $lessonId);
    }

    /**
     * Structure may only change while the course itself can be edited.
     *
     * Without this, archiving a course would freeze its details but leave its
     * lessons editable, which is the more surprising half.
     *
     * @throws ConflictingState
     */
    final protected function mustBeEditable(Course $course): void
    {
        if (!$course->status->isEditable()) {
            throw new ConflictingState(
                'An archived course cannot be edited. Move it back to draft first.',
                'course_archived',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    final protected function body(ServerRequestInterface $request): array
    {
        return (array) ($request->getParsedBody() ?? []);
    }

    /**
     * Whether the caller asked for inactive rows too.
     *
     * Defaults to showing everything for an author, because a deactivated
     * lesson they cannot see is a lesson they cannot reactivate.
     */
    final protected function activeOnly(ServerRequestInterface $request): bool
    {
        $value = $request->getQueryParams()['activeOnly'] ?? null;

        return $value !== null && filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /**
     * Reads the `order` array from a reorder request.
     *
     * The list must name every row in the parent and nothing else. A partial
     * list would silently leave the rest at their old positions, which is how
     * two rows end up sharing one.
     *
     * @param list<string> $existing ids currently in the parent
     *
     * @return list<string>
     *
     * @throws ValidationFailed
     */
    final protected function requestedOrder(ServerRequestInterface $request, array $existing): array
    {
        $raw = $this->body($request)['order'] ?? null;

        if (!is_array($raw) || $raw === []) {
            throw ValidationFailed::fromArray([
                'order' => 'Send the ids in their new order.',
            ]);
        }

        $order = array_values(array_map(static fn (mixed $id): string => (string) $id, $raw));

        if (count(array_unique($order)) !== count($order)) {
            throw ValidationFailed::fromArray(['order' => 'The same id appears more than once.']);
        }

        $missing = array_diff($existing, $order);
        $unknown = array_diff($order, $existing);

        if ($unknown !== []) {
            throw ValidationFailed::fromArray([
                'order' => 'The list names something that is not in this course: '
                    . implode(', ', array_values($unknown)),
            ]);
        }

        if ($missing !== []) {
            throw ValidationFailed::fromArray([
                'order' => 'The list must name every item. Missing: '
                    . implode(', ', array_values($missing)),
            ]);
        }

        return $order;
    }
}
