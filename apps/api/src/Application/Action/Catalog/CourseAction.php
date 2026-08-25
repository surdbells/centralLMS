<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Catalog\Course;
use Central\Api\Domain\Catalog\CourseRepository;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared plumbing for the course endpoints.
 */
abstract class CourseAction
{
    public function __construct(protected readonly CourseRepository $courses)
    {
    }

    final protected function caller(ServerRequestInterface $request): AuthenticatedUser
    {
        return AuthenticationMiddleware::require($request);
    }

    /**
     * The caller's tenant. A catalogue exists only inside one, so a caller
     * without a tenant is refused rather than shown an arbitrary one.
     *
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
     * @throws EntityNotFound when the id belongs to another tenant or does not exist
     */
    final protected function requireCourse(ServerRequestInterface $request, string $id): Course
    {
        return $this->courses->find($this->tenantId($request), $id)
            ?? throw new EntityNotFound('Course', $id);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function body(ServerRequestInterface $request): array
    {
        return (array) ($request->getParsedBody() ?? []);
    }

    /**
     * @return array{page: int, perPage: int, search: ?string, sortBy: string, sortDir: string}
     */
    final protected function paging(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();

        return [
            'page'    => max(1, (int) ($query['page'] ?? 1)),
            'perPage' => max(1, min(100, (int) ($query['perPage'] ?? 20))),
            'search'  => isset($query['search']) ? (string) $query['search'] : null,
            'sortBy'  => (string) ($query['sortBy'] ?? 'title'),
            'sortDir' => (string) ($query['sortDir'] ?? 'asc'),
        ];
    }

    /**
     * @param list<Course> $courses
     *
     * @return list<array<string, mixed>>
     */
    final protected function serialise(array $courses): array
    {
        return array_map(static fn (Course $c): array => $c->toArray(), $courses);
    }
}
