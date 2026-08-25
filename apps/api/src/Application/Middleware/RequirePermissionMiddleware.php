<?php

declare(strict_types=1);

namespace Central\Api\Application\Middleware;

use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Declarative route guard: `->add(RequirePermission::of(Permission::CourseManage))`.
 *
 * Keeping authorisation on the route means the permission a route needs is
 * visible in routes.php, next to the path — one file you can read top to
 * bottom to audit who can reach what.
 */
final class RequirePermissionMiddleware implements MiddlewareInterface
{
    /** @var list<Permission> */
    private array $permissions;

    /**
     * @param list<Permission> $permissions
     */
    private function __construct(array $permissions, private readonly bool $requireAll)
    {
        $this->permissions = array_values($permissions);
    }

    /**
     * Caller must hold every listed permission.
     */
    public static function all(Permission ...$permissions): self
    {
        return new self(array_values($permissions), true);
    }

    /**
     * Caller must hold at least one of the listed permissions.
     */
    public static function any(Permission ...$permissions): self
    {
        return new self(array_values($permissions), false);
    }

    public static function of(Permission $permission): self
    {
        return new self([$permission], true);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = AuthenticationMiddleware::require($request);

        if ($this->requireAll) {
            foreach ($this->permissions as $permission) {
                $user->mustHave($permission);
            }
        } elseif (!$user->canAny($this->permissions)) {
            throw new AccessDenied(implode(' | ', array_map(
                static fn (Permission $p): string => $p->value,
                $this->permissions,
            )));
        }

        return $handler->handle($request);
    }
}
