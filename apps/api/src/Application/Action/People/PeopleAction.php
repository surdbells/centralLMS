<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\People;

use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\People\Person;
use Central\Api\Domain\People\PersonRepository;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared plumbing for the people endpoints.
 */
abstract class PeopleAction
{
    public function __construct(
        protected readonly PersonRepository $people,
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
            throw new AccessDenied(Permission::UserView->value);
        }

        return $user->tenantId;
    }

    final protected function userId(ServerRequestInterface $request): string
    {
        return $this->caller($request)->id;
    }

    /**
     * @throws EntityNotFound
     */
    final protected function require(ServerRequestInterface $request, string $id): Person
    {
        return $this->people->find($this->tenantId($request), $id)
            ?? throw new EntityNotFound('User', $id);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function body(ServerRequestInterface $request): array
    {
        return (array) ($request->getParsedBody() ?? []);
    }

    /**
     * @return array{page: int, perPage: int}
     */
    final protected function listing(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();

        return [
            'page'    => max(1, (int) ($query['page'] ?? 1)),
            'perPage' => max(1, min(100, (int) ($query['perPage'] ?? 25))),
        ];
    }
}
