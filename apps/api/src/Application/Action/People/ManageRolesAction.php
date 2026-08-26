<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\People;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Authz\PermissionCatalogue;
use Central\Api\Domain\Authz\Role;
use Central\Api\Domain\Authz\RoleRepository;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;

/**
 * Roles, and what each of them may do.
 *
 * GET    /v1/roles
 * GET    /v1/roles/permissions
 * GET    /v1/roles/{id}
 * POST   /v1/roles
 * PUT    /v1/roles/{id}
 * DELETE /v1/roles/{id}
 *
 * Six routes for the legacy's eleven. Four of them go because assigning a role
 * to somebody is not a role operation: `assign-role` and `remove-role` were a
 * second way to write `user_roles`, disagreeing with the user form about
 * whether removing the last role was allowed. A person's roles are set by
 * `PUT /v1/users/{id}`, once, as a set.
 *
 * Two things this refuses that the legacy allowed:
 *
 *  - **Granting a permission the code does not define.** The legacy's
 *    `permissions` table held 61 rows; the checks read a handful of them.
 *    Everything here is validated against {@see Permission}, so a role cannot
 *    promise something nothing enforces.
 *  - **Deleting a role somebody still holds.** `role-delete` ran a bare
 *    `DELETE`, and the `ON DELETE CASCADE` on `user_roles` quietly took the
 *    role off everyone who had it. It is refused now, with the count.
 */
final class ManageRolesAction
{
    public function __construct(private readonly RoleRepository $roles)
    {
    }

    /**
     * Every role this organisation may assign: the built-ins plus its own.
     */
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantId = $this->readable($request);
        $counts   = $this->roles->holderCounts($tenantId);

        return Json::ok(
            $response,
            array_map(
                static fn (Role $role): array => [
                    ...$role->toArray(),
                    // Counted, not stored. See RoleRepository::holderCounts().
                    'holders' => $counts[$role->id] ?? 0,
                ],
                $this->roles->findAllForTenant($tenantId),
            ),
        );
    }

    /**
     * The permissions a role may grant, grouped for the editor.
     *
     * Needs only `user.view`: whoever can see the role list has to be able to
     * read what its permissions mean, without being trusted to change them.
     */
    public function permissions(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->readable($request);

        return Json::ok($response, PermissionCatalogue::grouped());
    }

    /**
     * @param array<string, string> $args
     */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->readable($request);
        $role     = $this->requireRole($tenantId, $args['id']);

        return Json::ok($response, [
            ...$role->toArray(),
            'holders' => $this->roles->holderCounts($tenantId)[$role->id] ?? 0,
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller   = $this->writable($request);
        $tenantId = $this->tenantOf($caller);
        $body     = $this->body($request);

        $violations = [];
        $name       = $this->name($body, $violations);
        $slug       = $this->slug($body, $name, $tenantId, null, $violations);
        $permissions = $this->permissionSet($body, $caller, $violations);

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        $role = Role::custom(
            id: Uuid::uuid4()->toString(),
            tenantId: $tenantId,
            slug: $slug,
            name: $name,
            permissions: $permissions,
            description: $this->textOrNull($body['description'] ?? null),
        );

        $this->roles->save($role);

        return Json::created($response, [...$role->toArray(), 'holders' => 0], '/v1/roles/' . $role->id);
    }

    /**
     * @param array<string, string> $args
     */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller   = $this->writable($request);
        $tenantId = $this->tenantOf($caller);
        $existing = $this->requireRole($tenantId, $args['id']);

        // A tenant admin may not redefine what "Instructor" means; otherwise
        // one organisation's idea of it drifts from another's and support
        // becomes guesswork.
        if (!$existing->isEditable()) {
            throw new ConflictingState(sprintf(
                'The built-in role "%s" is defined in code and cannot be edited. Create a custom role instead.',
                $existing->name,
            ));
        }

        $body       = $this->body($request);
        $violations = [];

        $name = array_key_exists('name', $body) ? $this->name($body, $violations) : $existing->name;
        $slug = array_key_exists('slug', $body)
            ? $this->slug($body, $name, $tenantId, $existing->id, $violations)
            : $existing->slug;

        $permissions = array_key_exists('permissions', $body)
            ? $this->permissionSet($body, $caller, $violations)
            : $existing->permissions;

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        $role = Role::custom(
            id: $existing->id,
            tenantId: $tenantId,
            slug: $slug,
            name: $name,
            permissions: $permissions,
            description: array_key_exists('description', $body)
                ? $this->textOrNull($body['description'])
                : $existing->description,
        );

        $this->roles->save($role);

        return Json::ok($response, [
            ...$role->toArray(),
            'holders' => $this->roles->holderCounts($tenantId)[$role->id] ?? 0,
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->tenantOf($this->writable($request));
        $role     = $this->requireRole($tenantId, $args['id']);

        if (!$role->isEditable()) {
            throw new ConflictingState(sprintf('The built-in role "%s" cannot be deleted.', $role->name));
        }

        $holders = $this->roles->holderCounts($tenantId)[$role->id] ?? 0;

        // The `ON DELETE CASCADE` on `user_roles` would take this role off
        // everyone holding it, silently. Say so instead.
        if ($holders > 0) {
            throw new ConflictingState(sprintf(
                '%d %s still assigned the role "%s". Move them to another role first.',
                $holders,
                $holders === 1 ? 'person is' : 'people are',
                $role->name,
            ));
        }

        $this->roles->delete($tenantId, $role->id);

        return Json::noContent($response);
    }

    // --- reading the request ------------------------------------------------

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $violations
     */
    private function name(array $body, array &$violations): string
    {
        $name = trim((string) ($body['name'] ?? ''));

        if ($name === '') {
            $violations['name'] = 'Give the role a name.';
        } elseif (mb_strlen($name) > 100) {
            $violations['name'] = 'Keep the name to 100 characters or fewer.';
        }

        return $name;
    }

    /**
     * The slug, defaulted from the name and checked for collisions.
     *
     * A slug is what code would have to match on if it ever branched on a
     * role, so a custom role may not take a built-in's — `instructor` means
     * one thing everywhere.
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $violations
     */
    private function slug(
        array $body,
        string $name,
        string $tenantId,
        ?string $ownId,
        array &$violations,
    ): string {
        $given = trim((string) ($body['slug'] ?? ''));
        $slug  = $given === '' ? $this->slugify($name) : $this->slugify($given);

        if ($slug === '') {
            $violations['slug'] = 'The name must contain at least one letter or number.';

            return $slug;
        }

        $clash = $this->roles->find($tenantId, $slug);

        if ($clash !== null && $clash->id !== $ownId) {
            $violations['slug'] = $clash->isSystem
                ? sprintf('"%s" is a built-in role. Choose another name.', $slug)
                : sprintf('A role called "%s" already exists.', $slug);
        }

        return $slug;
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $violations
     *
     * @return list<Permission>
     */
    private function permissionSet(array $body, AuthenticatedUser $caller, array &$violations): array
    {
        $given = $body['permissions'] ?? [];

        if (!is_array($given)) {
            $violations['permissions'] = 'Send the permissions as a list.';

            return [];
        }

        $permissions = [];
        $unknown     = [];

        foreach ($given as $value) {
            $permission = is_string($value) ? Permission::tryFrom($value) : null;

            if ($permission === null) {
                $unknown[] = is_string($value) ? $value : '(not a string)';

                continue;
            }

            $permissions[$permission->value] = $permission;
        }

        if ($unknown !== []) {
            // Named, not swallowed: a role that silently drops half of what
            // was ticked is worse than one that refuses to save.
            $violations['permissions'] = sprintf(
                'These are not permissions this system defines: %s.',
                implode(', ', $unknown),
            );

            return array_values($permissions);
        }

        // Nobody may hand out more than they hold. Today the only role with
        // `role.manage` holds everything, so this refuses nothing — it is here
        // so that giving `role.manage` to a narrower role later cannot quietly
        // become a way to escalate.
        $beyond = array_filter($permissions, static fn (Permission $p): bool => !$caller->can($p));

        if ($beyond !== []) {
            $violations['permissions'] = sprintf(
                'You cannot grant a permission you do not hold yourself: %s.',
                implode(', ', array_map(static fn (Permission $p): string => $p->value, $beyond)),
            );
        }

        return array_values($permissions);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '_', $slug);

        return trim($slug, '_');
    }

    private function textOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function requireRole(string $tenantId, string $id): Role
    {
        return $this->roles->find($tenantId, $id) ?? throw new EntityNotFound('Role', $id);
    }

    // --- who is asking ------------------------------------------------------

    private function readable(ServerRequestInterface $request): string
    {
        $caller = AuthenticationMiddleware::require($request);
        $caller->mustHave(Permission::UserView);

        return $this->tenantOf($caller);
    }

    /**
     * Returns the caller rather than only their tenant: what a role may grant
     * depends on what the person defining it holds.
     */
    private function writable(ServerRequestInterface $request): AuthenticatedUser
    {
        $caller = AuthenticationMiddleware::require($request);
        $caller->mustHave(Permission::RoleManage);

        return $caller;
    }

    private function tenantOf(AuthenticatedUser $caller): string
    {
        return $caller->tenantId ?? throw new AccessDenied(Permission::RoleManage->value);
    }
}
