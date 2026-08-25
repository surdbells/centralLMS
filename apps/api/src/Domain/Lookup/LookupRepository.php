<?php

declare(strict_types=1);

namespace Central\Api\Domain\Lookup;

/**
 * Storage for tenant reference data.
 *
 * **Every method takes a tenant id, and there is no overload that omits it.**
 * That is the whole point: the legacy code could read and write these tables
 * without a tenant filter, and did — `SELECT * FROM branches` with no WHERE,
 * and `UPDATE branches … WHERE branch_id = ?`. Making the parameter mandatory
 * means the same mistake cannot be written here.
 */
interface LookupRepository
{
    /**
     * Paginated list for an administration screen, active and inactive.
     *
     * @return array{items: list<Lookup>, total: int}
     */
    public function paginate(
        string $tenantId,
        LookupKind $kind,
        int $page,
        int $perPage,
        ?string $search = null,
        string $sortBy = 'name',
        string $sortDirection = 'asc',
    ): array;

    /**
     * Active entries only, ordered for display. This is what every dropdown
     * in the application asks for.
     *
     * @return list<Lookup>
     */
    public function active(string $tenantId, LookupKind $kind): array;

    public function find(string $tenantId, LookupKind $kind, string $id): ?Lookup;

    /** Used to check uniqueness before insert, and to resolve a parent. */
    public function findByName(string $tenantId, LookupKind $kind, string $name): ?Lookup;

    public function create(Lookup $lookup): Lookup;

    public function update(Lookup $lookup): Lookup;

    /**
     * Activates or deactivates. Reference data is never hard-deleted — rows
     * elsewhere point at it, and a deleted department would orphan every user
     * assigned to it.
     */
    public function setActive(string $tenantId, LookupKind $kind, string $id, bool $isActive): bool;

    /**
     * Soft-deletes. Fails if anything still references the entry.
     */
    public function softDelete(string $tenantId, LookupKind $kind, string $id): bool;

    /**
     * Whether an entry with this name (or code) already exists for the tenant,
     * excluding one id — so an update can keep its own name.
     */
    public function existsWithName(string $tenantId, LookupKind $kind, string $name, ?string $exceptId = null): bool;

    public function existsWithCode(string $tenantId, LookupKind $kind, string $code, ?string $exceptId = null): bool;
}
