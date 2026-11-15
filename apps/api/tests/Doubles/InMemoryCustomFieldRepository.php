<?php

declare(strict_types=1);

namespace Central\Api\Tests\Doubles;

use Central\Api\Domain\CustomField\CustomFieldDefinition;
use Central\Api\Domain\CustomField\CustomFieldDefinitionRepository;

/** @internal Custom profile field definitions in memory. */
final class InMemoryCustomFieldRepository implements CustomFieldDefinitionRepository
{
    /** @var array<string, CustomFieldDefinition> by id */
    public array $definitions = [];

    public function byKey(string $key): ?CustomFieldDefinition
    {
        foreach ($this->definitions as $d) {
            if ($d->key === $key) {
                return $d;
            }
        }

        return null;
    }

    public function allForTenant(string $tenantId, bool $activeOnly = false): array
    {
        return array_values($this->definitions);
    }

    public function find(string $tenantId, string $id): ?CustomFieldDefinition
    {
        return $this->definitions[$id] ?? null;
    }

    public function create(CustomFieldDefinition $definition): CustomFieldDefinition
    {
        return $this->definitions[$definition->id] = $definition;
    }

    public function update(CustomFieldDefinition $definition): CustomFieldDefinition
    {
        return $this->definitions[$definition->id] = $definition;
    }

    public function delete(string $tenantId, string $id): bool
    {
        unset($this->definitions[$id]);

        return true;
    }

    public function keyExists(string $tenantId, string $key, ?string $exceptId = null): bool
    {
        return $this->byKey($key) !== null;
    }
}
