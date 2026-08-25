<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Lookup;

use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Lookup\LookupKind;
use Central\Api\Domain\Lookup\LookupRepository;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared plumbing for the reference-data endpoints.
 *
 * Six actions extend this and together replace 36 legacy PHP files. The thing
 * they all need — and that the legacy versions each got wrong in their own way
 * — is resolving the caller's tenant and refusing to proceed without it.
 */
abstract class LookupAction
{
    public function __construct(protected readonly LookupRepository $lookups)
    {
    }

    /**
     * The kind named in the path.
     *
     * @param array<string, string> $args
     *
     * @throws EntityNotFound for an unknown kind, so `/v1/lookups/nonsense`
     *                        is a 404 rather than a 500
     */
    final protected function kind(array $args): LookupKind
    {
        $slug = $args['kind'] ?? '';
        $kind = LookupKind::fromSlug($slug);

        if ($kind === null) {
            throw new EntityNotFound('Reference data type', $slug);
        }

        return $kind;
    }

    /**
     * The caller's tenant.
     *
     * Reference data is meaningless outside a tenant, so a caller without one
     * — a super admin acting globally — is refused rather than being shown or
     * allowed to edit an arbitrary organisation's data.
     *
     * @throws AccessDenied
     */
    final protected function tenantId(ServerRequestInterface $request): string
    {
        $user = $this->caller($request);

        if ($user->tenantId === null) {
            throw new AccessDenied(Permission::LookupView->value);
        }

        return $user->tenantId;
    }

    final protected function caller(ServerRequestInterface $request): AuthenticatedUser
    {
        return AuthenticationMiddleware::require($request);
    }

    /**
     * @throws AccessDenied
     */
    final protected function requireManage(ServerRequestInterface $request): void
    {
        $this->caller($request)->mustHave(Permission::LookupManage);
    }

    /**
     * @throws AccessDenied
     */
    final protected function requireView(ServerRequestInterface $request): void
    {
        $this->caller($request)->mustHave(Permission::LookupView);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function body(ServerRequestInterface $request): array
    {
        return (array) ($request->getParsedBody() ?? []);
    }
}
