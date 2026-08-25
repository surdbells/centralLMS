<?php

declare(strict_types=1);

namespace Central\Api\Domain\Authz;

/**
 * Resolves role identifiers to roles.
 *
 * Access tokens carry role *ids*, never permission lists. Permissions are
 * resolved through this repository on each request, so revoking a permission
 * from a role takes effect immediately rather than whenever the holder's token
 * next refreshes. Implementations are expected to cache aggressively — this is
 * on the path of every authenticated request.
 */
interface RoleRepository
{
    /**
     * Roles for the given identifiers, scoped to a tenant.
     *
     * Built-in roles resolve for any tenant. A custom role resolves only for
     * the tenant that owns it: an id from another tenant must be silently
     * dropped, not returned, so a tampered or stale token cannot borrow
     * another organisation's role.
     *
     * Unknown ids are dropped rather than raising — a role deleted while a
     * token was live should cost the holder that role, not lock them out.
     *
     * @param list<string> $identifiers role ids, or slugs for built-ins
     *
     * @return list<Role>
     */
    public function findForTenant(?string $tenantId, array $identifiers): array;

    /**
     * Every role a tenant may assign: the five built-ins plus its own.
     *
     * @return list<Role>
     */
    public function findAllForTenant(string $tenantId): array;

    public function find(?string $tenantId, string $identifier): ?Role;

    /**
     * @throws \RuntimeException if a built-in role would be modified
     */
    public function save(Role $role): void;

    /**
     * @throws \RuntimeException if a built-in role would be deleted
     */
    public function delete(string $tenantId, string $roleId): void;
}
