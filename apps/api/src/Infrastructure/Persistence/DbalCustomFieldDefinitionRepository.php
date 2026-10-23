<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\CustomField\CustomFieldDefinition;
use Central\Api\Domain\CustomField\CustomFieldDefinitionRepository;
use Central\Api\Domain\CustomField\CustomFieldType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class DbalCustomFieldDefinitionRepository implements CustomFieldDefinitionRepository
{
    private const COLUMNS = 'id, tenant_id, field_key, label, type, options,
                             required, sort_order, is_active, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function allForTenant(string $tenantId, bool $activeOnly = false): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s FROM tenant_custom_fields
                 WHERE tenant_id = :tenant %s
                 ORDER BY sort_order, lower(label), id',
                self::COLUMNS,
                $activeOnly ? 'AND is_active = TRUE' : '',
            ),
            ['tenant' => $tenantId],
        );

        return array_map(fn (array $row): CustomFieldDefinition => $this->hydrate($row), $rows);
    }

    public function find(string $tenantId, string $id): ?CustomFieldDefinition
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM tenant_custom_fields WHERE id = :id AND tenant_id = :tenant',
                self::COLUMNS,
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function create(CustomFieldDefinition $definition): CustomFieldDefinition
    {
        $this->connection->executeStatement(
            'INSERT INTO tenant_custom_fields
                (id, tenant_id, field_key, label, type, options, required, sort_order, is_active)
             VALUES (:id, :tenant, :key, :label, :type, :options::jsonb, :required, :sortOrder, :isActive)',
            $this->writable($definition),
            $this->types(),
        );

        return $this->find($definition->tenantId, $definition->id)
            ?? throw new \RuntimeException('The custom field vanished immediately after being created.');
    }

    public function update(CustomFieldDefinition $definition): CustomFieldDefinition
    {
        $this->connection->executeStatement(
            'UPDATE tenant_custom_fields SET
                label = :label, type = :type, options = :options::jsonb,
                required = :required, sort_order = :sortOrder, is_active = :isActive,
                updated_at = now()
             WHERE id = :id AND tenant_id = :tenant',
            $this->writable($definition),
            $this->types(),
        );

        return $this->find($definition->tenantId, $definition->id)
            ?? throw new \RuntimeException('The custom field vanished immediately after being updated.');
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->connection->executeStatement(
            'DELETE FROM tenant_custom_fields WHERE id = :id AND tenant_id = :tenant',
            ['id' => $id, 'tenant' => $tenantId],
        ) > 0;
    }

    public function keyExists(string $tenantId, string $key, ?string $exceptId = null): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tenant_custom_fields
             WHERE tenant_id = :tenant AND lower(field_key) = lower(:key)
               AND (:exceptId::uuid IS NULL OR id <> :exceptId::uuid)',
            ['tenant' => $tenantId, 'key' => $key, 'exceptId' => $exceptId],
        ) > 0;
    }

    // --- internals --------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function writable(CustomFieldDefinition $definition): array
    {
        return [
            'id'        => $definition->id,
            'tenant'    => $definition->tenantId,
            'key'       => $definition->key,
            'label'     => $definition->label,
            'type'      => $definition->type->value,
            'options'   => json_encode(array_values($definition->options), JSON_THROW_ON_ERROR),
            'required'  => $definition->required,
            'sortOrder' => $definition->sortOrder,
            'isActive'  => $definition->isActive,
        ];
    }

    /**
     * @return array<string, ParameterType>
     */
    private function types(): array
    {
        return [
            'required' => ParameterType::BOOLEAN,
            'isActive' => ParameterType::BOOLEAN,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): CustomFieldDefinition
    {
        $options = [];
        $raw = $row['options'] ?? null;

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                foreach ($decoded as $option) {
                    if (is_scalar($option)) {
                        $options[] = (string) $option;
                    }
                }
            }
        }

        return new CustomFieldDefinition(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            key: (string) $row['field_key'],
            label: (string) $row['label'],
            type: CustomFieldType::from((string) $row['type']),
            options: $options,
            required: (bool) $row['required'],
            sortOrder: (int) ($row['sort_order'] ?? 0),
            isActive: (bool) $row['is_active'],
            createdAt: $this->date($row['created_at'] ?? null),
            updatedAt: $this->date($row['updated_at'] ?? null),
        );
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
