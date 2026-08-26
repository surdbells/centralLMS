<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Media;

use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Media\MediaItem;
use Central\Api\Domain\Media\MediaRepository;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared plumbing for the media endpoints.
 */
abstract class MediaAction
{
    public function __construct(
        protected readonly MediaRepository $media,
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
            throw new AccessDenied(Permission::MediaView->value);
        }

        return $user->tenantId;
    }

    /**
     * @throws EntityNotFound
     */
    final protected function require(ServerRequestInterface $request, string $id): MediaItem
    {
        return $this->media->find($this->tenantId($request), $id)
            ?? throw new EntityNotFound('Media', $id);
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
