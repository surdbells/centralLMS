<?php

declare(strict_types=1);

namespace Central\Api\Tests\Doubles;

use Central\Api\Domain\Authz\Role;
use Central\Api\Domain\Authz\RoleRepository;
use Central\Api\Domain\Authz\SystemRole;

/** @internal Built-in roles only, resolved from the enum. */
final class FakeRoleRepository implements RoleRepository
{
    public function findForTenant(?string $tenantId, array $identifiers): array
    {
        $roles = [];

        foreach ($identifiers as $identifier) {
            $role = $this->find($tenantId, (string) $identifier);

            if ($role !== null) {
                $roles[$role->id] = $role;
            }
        }

        return array_values($roles);
    }

    public function findAllForTenant(string $tenantId): array
    {
        return array_map(static fn (SystemRole $r): Role => $r->toRole(), SystemRole::cases());
    }

    public function find(?string $tenantId, string $identifier): ?Role
    {
        return SystemRole::tryFrom($identifier)?->toRole();
    }

    public function holderCounts(string $tenantId): array
    {
        return [];
    }

    public function save(Role $role): void
    {
    }

    public function delete(string $tenantId, string $roleId): void
    {
    }
}
