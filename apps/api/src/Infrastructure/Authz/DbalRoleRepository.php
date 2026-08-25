<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Authz;

use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Authz\Role;
use Central\Api\Domain\Authz\RoleRepository;
use Central\Api\Domain\Authz\SystemRole;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Roles from the database, with built-in definitions still coming from code.
 *
 * The split matters:
 *
 *  - **Built-in roles** exist as rows (so `user_roles` can reference them) but
 *    their *permissions* come from {@see SystemRole::effectivePermissions()}.
 *    A tenant cannot redefine what "Instructor" means, and adding a permission
 *    to the enum reaches every tenant without a data migration.
 *  - **Custom roles** are entirely data: their permissions are read from
 *    `role_permissions`, filtered through the {@see Permission} enum so a row
 *    naming a permission the code does not define grants nothing.
 *
 * Resolution runs on every authenticated request, so results are cached and
 * invalidated when a role changes.
 */
final class DbalRoleRepository implements RoleRepository
{
    /** Short enough that a missed invalidation self-heals quickly. */
    private const CACHE_TTL = 300;

    public function __construct(
        private readonly Connection $connection,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function findForTenant(?string $tenantId, array $identifiers): array
    {
        if ($identifiers === []) {
            return [];
        }

        $roles = [];

        foreach ($identifiers as $identifier) {
            $role = $this->find($tenantId, $identifier);

            // Unknown or foreign ids are dropped, never raised: a role deleted
            // while a token was live costs the holder that role rather than
            // locking them out.
            if ($role !== null) {
                $roles[$role->id] = $role;
            }
        }

        return array_values($roles);
    }

    public function find(?string $tenantId, string $identifier): ?Role
    {
        $item = $this->cache->getItem($this->cacheKey($tenantId, $identifier));

        if ($item->isHit()) {
            $cached = $item->get();

            return $cached instanceof Role ? $cached : null;
        }

        $role = $this->load($tenantId, $identifier);

        $item->set($role);
        $item->expiresAfter(self::CACHE_TTL);
        $this->cache->save($item);

        return $role;
    }

    public function findAllForTenant(string $tenantId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, tenant_id, key, name, description, is_system
             FROM roles
             WHERE tenant_id IS NULL OR tenant_id = :tenant
             ORDER BY is_system DESC, name',
            ['tenant' => $tenantId],
        );

        return array_map(fn (array $row): Role => $this->hydrate($row), $rows);
    }

    public function save(Role $role): void
    {
        if ($role->isSystem) {
            throw new \RuntimeException(
                'Built-in roles are defined in code and cannot be modified. Create a custom role instead.',
            );
        }

        $this->connection->transactional(function (Connection $c) use ($role): void {
            $c->executeStatement(
                'INSERT INTO roles (id, tenant_id, key, name, description, is_system)
                 VALUES (:id, :tenant, :key, :name, :description, FALSE)
                 ON CONFLICT (id) DO UPDATE SET
                    key = EXCLUDED.key,
                    name = EXCLUDED.name,
                    description = EXCLUDED.description,
                    updated_at = NOW()',
                [
                    'id'          => $role->id,
                    'tenant'      => $role->tenantId,
                    'key'         => $role->slug,
                    'name'        => $role->name,
                    'description' => $role->description,
                ],
            );

            // Replace wholesale: computing a diff would let a concurrent edit
            // leave a permission behind.
            $c->executeStatement('DELETE FROM role_permissions WHERE role_id = :id', ['id' => $role->id]);

            foreach ($role->permissions as $permission) {
                $c->executeStatement(
                    'INSERT INTO role_permissions (role_id, permission) VALUES (:id, :permission)',
                    ['id' => $role->id, 'permission' => $permission->value],
                );
            }
        });

        $this->forget($role->tenantId, $role->id);
        $this->forget($role->tenantId, $role->slug);
    }

    public function delete(string $tenantId, string $roleId): void
    {
        $isSystem = $this->connection->fetchOne(
            'SELECT is_system FROM roles WHERE id = :id',
            ['id' => $roleId],
        );

        if ($isSystem === true || $isSystem === 't' || $isSystem === 1) {
            throw new \RuntimeException('Built-in roles cannot be deleted.');
        }

        // Scoped by tenant so one organisation cannot delete another's role.
        $this->connection->executeStatement(
            'DELETE FROM roles WHERE id = :id AND tenant_id = :tenant AND NOT is_system',
            ['id' => $roleId, 'tenant' => $tenantId],
        );

        $this->forget($tenantId, $roleId);
    }

    private function load(?string $tenantId, string $identifier): ?Role
    {
        // Identifiers are either a uuid or a role key ('instructor'), so both
        // are matched — tokens carry whichever the issuer had to hand.
        $isUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $identifier) === 1;

        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT id, tenant_id, key, name, description, is_system
                 FROM roles
                 WHERE %s AND (tenant_id IS NULL OR tenant_id = :tenant)
                 LIMIT 1',
                $isUuid ? 'id = :identifier' : 'key = :identifier',
            ),
            ['identifier' => $identifier, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Role
    {
        $isSystem = (bool) $row['is_system'];
        $key = (string) $row['key'];

        if ($isSystem) {
            $systemRole = SystemRole::tryFrom($key);

            if ($systemRole === null) {
                throw new \RuntimeException(sprintf('Role "%s" is flagged system but is not a SystemRole.', $key));
            }

            // Row supplies identity; the enum supplies capability.
            return new Role(
                id: (string) $row['id'],
                tenantId: null,
                slug: $key,
                name: (string) $row['name'],
                permissions: $systemRole->effectivePermissions(),
                isSystem: true,
                description: $this->nullableString($row['description'] ?? null),
            );
        }

        return new Role(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            slug: $key,
            name: (string) $row['name'],
            permissions: $this->permissionsFor((string) $row['id']),
            isSystem: false,
            description: $this->nullableString($row['description'] ?? null),
        );
    }

    /**
     * @return list<Permission>
     */
    private function permissionsFor(string $roleId): array
    {
        $values = $this->connection->fetchFirstColumn(
            'SELECT permission FROM role_permissions WHERE role_id = :id',
            ['id' => $roleId],
        );

        $permissions = [];

        foreach ($values as $value) {
            // A stored permission the enum no longer defines grants nothing.
            $permission = Permission::tryFrom((string) $value);

            if ($permission !== null) {
                $permissions[$permission->value] = $permission;
            }
        }

        return array_values($permissions);
    }

    private function cacheKey(?string $tenantId, string $identifier): string
    {
        return 'authz.role.' . sha1(($tenantId ?? 'global') . '|' . $identifier);
    }

    private function forget(?string $tenantId, string $identifier): void
    {
        $this->cache->deleteItem($this->cacheKey($tenantId, $identifier));
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
