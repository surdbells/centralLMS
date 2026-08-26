<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Authz;

use Central\Api\Domain\Authz\Role;
use Central\Api\Domain\Authz\RoleRepository;
use Central\Api\Domain\Authz\SystemRole;

/**
 * Serves the five built-in roles from code, with no database.
 *
 * This is the whole role repository until the `roles` table lands with the
 * users slice, at which point a Doctrine-backed implementation takes over and
 * composes this one for the built-ins. Keeping the interface satisfied now
 * means authentication is wired and testable before tenants exist, rather than
 * being stubbed and rewritten.
 *
 * Custom roles are rejected here rather than silently ignored — a tenant admin
 * pressing "create role" must get a clear error, not a role that vanishes.
 */
final class SystemRoleRepository implements RoleRepository
{
    /** @var array<string, Role>|null */
    private ?array $roles = null;

    public function findForTenant(?string $tenantId, array $identifiers): array
    {
        $roles = [];

        foreach ($identifiers as $identifier) {
            $role = $this->all()[$identifier] ?? null;

            // Unknown ids are dropped: a role removed while a token was live
            // costs the holder that role, it does not lock them out.
            if ($role !== null && $role->isAvailableTo($tenantId)) {
                $roles[$role->id] = $role;
            }
        }

        return array_values($roles);
    }

    public function findAllForTenant(string $tenantId): array
    {
        return array_values($this->all());
    }

    public function find(?string $tenantId, string $identifier): ?Role
    {
        $role = $this->all()[$identifier] ?? null;

        return $role !== null && $role->isAvailableTo($tenantId) ? $role : null;
    }

    /**
     * Nobody holds anything here: this implementation has no users table to
     * count against.
     */
    public function holderCounts(string $tenantId): array
    {
        return [];
    }

    public function save(Role $role): void
    {
        throw new \RuntimeException(
            'Custom roles need the `roles` table, which arrives with the tenants/users migration. '
            . 'Built-in roles cannot be modified.',
        );
    }

    public function delete(string $tenantId, string $roleId): void
    {
        throw new \RuntimeException('Built-in roles cannot be deleted.');
    }

    /**
     * @return array<string, Role>
     */
    private function all(): array
    {
        if ($this->roles !== null) {
            return $this->roles;
        }

        $roles = [];

        foreach (SystemRole::cases() as $case) {
            $roles[$case->value] = $case->toRole();
        }

        return $this->roles = $roles;
    }
}
