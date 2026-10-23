<?php

declare(strict_types=1);

namespace Central\Api\Domain\CustomField;

/**
 * Storage for a tenant's custom field definitions.
 *
 * Every method takes a tenant id and there is no overload that omits it —
 * the same rule the reference-data repository keeps, and for the same reason:
 * a definition read or written without a tenant filter is one tenant's fields
 * leaking into another's.
 */
interface CustomFieldDefinitionRepository
{
    /**
     * Every definition for the tenant, in display order.
     *
     * @return list<CustomFieldDefinition>
     */
    public function allForTenant(string $tenantId, bool $activeOnly = false): array;

    public function find(string $tenantId, string $id): ?CustomFieldDefinition;

    public function create(CustomFieldDefinition $definition): CustomFieldDefinition;

    public function update(CustomFieldDefinition $definition): CustomFieldDefinition;

    /** Removes a definition outright. Values left in users.custom_fields are harmless. */
    public function delete(string $tenantId, string $id): bool;

    /**
     * Whether a field with this key already exists for the tenant, excluding
     * one id — so nothing stops two tenants sharing a key, or an update from
     * keeping its own.
     */
    public function keyExists(string $tenantId, string $key, ?string $exceptId = null): bool;
}
