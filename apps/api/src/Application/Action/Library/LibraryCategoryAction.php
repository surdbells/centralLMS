<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Library;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Library\LibraryCategory;
use Central\Api\Domain\Library\LibraryCategoryRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The digital library's categories.
 *
 * Anyone who can open the library may list them, with counts scoped to what
 * they can see (a manager may ask for whole-shelf counts with `scope=all`); only `library.manage` may create, rename, reorder or delete
 * one. Deleting a category never deletes its files — they become
 * uncategorised.
 */
final class LibraryCategoryAction
{
    private const DUPLICATE_MESSAGE = 'A category with that name already exists.';

    public function __construct(private readonly LibraryCategoryRepository $categories)
    {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller = $this->caller($request);
        if (!$caller->canAny([Permission::LibraryView, Permission::LibraryManage])) {
            throw new AccessDenied(Permission::LibraryView->value);
        }

        // Counts follow what the caller sees on the shelf (the same rule as
        // GET /library). Only a manager asking explicitly for `scope=all` —
        // the manage page — counts the whole shelf.
        $scope = $request->getQueryParams()['scope'] ?? null;
        $countAll = $scope === 'all' && $caller->can(Permission::LibraryManage);

        $result = $this->categories->list(
            $this->tenantId($request),
            $countAll ? null : $caller->id,
        );

        return Json::ok(
            $response,
            array_map(static fn (LibraryCategory $c): array => $c->toArray(), $result['categories']),
            200,
            ['uncategorisedCount' => $result['uncategorisedCount']],
        );
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::LibraryManage);

        $category = LibraryCategory::fromInput($this->tenantId($request), $this->body($request));

        try {
            $saved = $this->categories->create($category);
        } catch (UniqueConstraintViolationException) {
            throw new ConflictingState(self::DUPLICATE_MESSAGE, 'duplicate_name');
        }

        return Json::created($response, $saved->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::LibraryManage);

        $tenantId = $this->tenantId($request);
        $id = $args['id'] ?? '';

        $existing = $this->categories->find($tenantId, $id);
        if ($existing === null) {
            throw new EntityNotFound('Library category', $id);
        }

        $category = LibraryCategory::fromInput($tenantId, $this->body($request), $existing);

        try {
            $saved = $this->categories->update($category);
        } catch (UniqueConstraintViolationException) {
            throw new ConflictingState(self::DUPLICATE_MESSAGE, 'duplicate_name');
        }

        if ($saved === null) {
            throw new EntityNotFound('Library category', $id);
        }

        return Json::ok($response, $saved->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::LibraryManage);

        $id = $args['id'] ?? '';
        if (!$this->categories->delete($this->tenantId($request), $id)) {
            throw new EntityNotFound('Library category', $id);
        }

        return Json::noContent($response);
    }

    private function caller(ServerRequestInterface $request): AuthenticatedUser
    {
        return AuthenticationMiddleware::require($request);
    }

    private function tenantId(ServerRequestInterface $request): string
    {
        $user = $this->caller($request);
        if ($user->tenantId === null) {
            throw new AccessDenied(Permission::LibraryView->value);
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
}
