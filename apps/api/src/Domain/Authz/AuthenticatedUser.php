<?php

declare(strict_types=1);

namespace Central\Api\Domain\Authz;

use Central\Api\Domain\Exception\AccessDenied;

/**
 * The caller, as proven by a verified access token.
 *
 * Immutable and free of persistence concerns. The token carries role *ids*;
 * the roles themselves — and therefore the permissions — are resolved through
 * {@see RoleRepository} when the request is authenticated. That indirection is
 * what lets a tenant edit a custom role and have it take effect on the next
 * request rather than whenever every holder's token happens to refresh.
 */
final readonly class AuthenticatedUser
{
    /** @var list<Permission> */
    public array $permissions;

    /**
     * @param list<Role> $roles already resolved and tenant-checked
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $email,
        public array $roles,
        public string $tokenId = '',
    ) {
        $permissions = [];

        foreach ($roles as $role) {
            foreach ($role->permissions as $permission) {
                $permissions[$permission->value] = $permission;
            }
        }

        $this->permissions = array_values($permissions);
    }

    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /**
     * @param list<Permission> $permissions
     */
    public function canAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Guard-clause form: throws instead of returning false, so actions read as
     * a straight line rather than a pyramid of ifs.
     *
     * @throws AccessDenied
     */
    public function mustHave(Permission $permission): void
    {
        if (!$this->can($permission)) {
            throw new AccessDenied($permission->value);
        }
    }

    /**
     * Built-in role check.
     *
     * Deliberately the only role-name test available, and it accepts only
     * {@see SystemRole} — application code must never branch on a
     * tenant-defined role name.
     */
    public function hasSystemRole(SystemRole $role): bool
    {
        foreach ($this->roles as $held) {
            if ($held->systemRole() === $role) {
                return true;
            }
        }

        return false;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasSystemRole(SystemRole::SuperAdmin);
    }

    /**
     * Tenant isolation. Every query touching tenant-scoped data must be
     * filtered by this, and a super admin is the only caller allowed to reach
     * across tenants.
     */
    public function canAccessTenant(?string $tenantId): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $tenantId !== null && $tenantId === $this->tenantId;
    }

    /**
     * @throws AccessDenied
     */
    public function mustAccessTenant(?string $tenantId): void
    {
        if (!$this->canAccessTenant($tenantId)) {
            throw new AccessDenied();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'tenantId'    => $this->tenantId,
            'email'       => $this->email,
            'roles'       => array_map(static fn (Role $r): array => [
                'id'   => $r->id,
                'slug' => $r->slug,
                'name' => $r->name,
            ], $this->roles),
            'permissions' => array_map(static fn (Permission $p): string => $p->value, $this->permissions),
        ];
    }
}
