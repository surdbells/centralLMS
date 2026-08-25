<?php

declare(strict_types=1);

namespace Central\Api\Domain\Authz;

/**
 * A named bundle of permissions.
 *
 * Roles are **data, not code**: tenants define their own alongside the five
 * built-ins ({@see SystemRole}). That is why this is a class rather than an
 * enum, and why nothing anywhere should branch on a role's name.
 *
 * Authorisation is always a permission check. The single exception is
 * {@see SystemRole::SuperAdmin}, whose cross-tenant reach is a structural rule
 * rather than a grantable capability — see
 * {@see AuthenticatedUser::canAccessTenant()}.
 */
final readonly class Role
{
    /**
     * @param string|null      $tenantId    null for a built-in role, which exists in every tenant
     * @param list<Permission> $permissions the role's full permission set, inheritance already resolved
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $slug,
        public string $name,
        public array $permissions,
        public bool $isSystem = false,
        public ?string $description = null,
    ) {
    }

    /**
     * Builds a tenant-defined role, rejecting anything that would let a tenant
     * grant a capability the code does not define.
     *
     * @param list<Permission|string> $permissions
     */
    public static function custom(
        string $id,
        string $tenantId,
        string $slug,
        string $name,
        array $permissions,
        ?string $description = null,
    ): self {
        $resolved = [];

        foreach ($permissions as $permission) {
            $value = $permission instanceof Permission
                ? $permission
                : Permission::tryFrom($permission);

            if ($value === null) {
                throw new \ValueError(sprintf(
                    'Unknown permission "%s". Roles may only grant permissions defined in Domain\Authz\Permission.',
                    is_string($permission) ? $permission : 'non-string',
                ));
            }

            // Deduplicate by value so the same permission listed twice does
            // not survive into the resolved set.
            $resolved[$value->value] = $value;
        }

        return new self(
            id: $id,
            tenantId: $tenantId,
            slug: $slug,
            name: $name,
            permissions: array_values($resolved),
            isSystem: false,
            description: $description,
        );
    }

    public function has(Permission $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /**
     * Whether this role is usable by the given tenant. Built-ins are available
     * everywhere; a custom role belongs to exactly one tenant.
     */
    public function isAvailableTo(?string $tenantId): bool
    {
        return $this->isSystem || ($tenantId !== null && $tenantId === $this->tenantId);
    }

    public function systemRole(): ?SystemRole
    {
        return $this->isSystem ? SystemRole::tryFrom($this->slug) : null;
    }

    /**
     * A tenant admin may not edit or delete a built-in role — only create new
     * ones. Otherwise one tenant's idea of "Instructor" drifts from another's
     * and support becomes guesswork.
     */
    public function isEditable(): bool
    {
        return !$this->isSystem;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'slug'        => $this->slug,
            'name'        => $this->name,
            'description' => $this->description,
            'isSystem'    => $this->isSystem,
            'editable'    => $this->isEditable(),
            'permissions' => array_map(static fn (Permission $p): string => $p->value, $this->permissions),
        ];
    }
}
