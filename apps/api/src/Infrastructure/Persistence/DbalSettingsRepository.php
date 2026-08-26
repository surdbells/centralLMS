<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Settings\SettingCatalogue;
use Central\Api\Domain\Settings\SettingCategory;
use Central\Api\Domain\Settings\SettingsRepository;
use Central\Api\Domain\Settings\TenantSettings;
use Doctrine\DBAL\Connection;

final class DbalSettingsRepository implements SettingsRepository
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SettingCatalogue $catalogue,
    ) {
    }

    public function forTenant(string $tenantId): TenantSettings
    {
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT setting_key, value FROM tenant_settings WHERE tenant_id = :tenant',
            ['tenant' => $tenantId],
        );

        /** @var array<string, string> $rows */
        return TenantSettings::resolve($tenantId, $rows, $this->catalogue);
    }

    public function save(string $tenantId, array $values, ?string $actorId): TenantSettings
    {
        if ($values === []) {
            return $this->forTenant($tenantId);
        }

        $this->connection->transactional(function (Connection $tx) use ($tenantId, $values, $actorId): void {
            foreach ($values as $key => $value) {
                $definition = $this->catalogue->findByName($key);

                if ($definition === null) {
                    // Unreachable through the action, which validates first.
                    // Here so a future caller cannot write a key the catalogue
                    // does not declare — the thing the legacy allowed.
                    throw new \LogicException(sprintf('Unknown setting "%s".', $key));
                }

                $tx->executeStatement(
                    'INSERT INTO tenant_settings (tenant_id, category, setting_key, value, updated_by, updated_at)
                     VALUES (:tenant, :category, :key, :value, :actor, now())
                     ON CONFLICT (tenant_id, setting_key) DO UPDATE
                     SET value = EXCLUDED.value,
                         category = EXCLUDED.category,
                         updated_by = EXCLUDED.updated_by,
                         updated_at = now()',
                    [
                        'tenant'   => $tenantId,
                        'category' => $definition->category()->value,
                        'key'      => $key,
                        'value'    => $value,
                        'actor'    => $actorId,
                    ],
                );
            }
        });

        return $this->forTenant($tenantId);
    }

    public function resetCategory(string $tenantId, SettingCategory $category): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM tenant_settings WHERE tenant_id = :tenant AND category = :category',
            ['tenant' => $tenantId, 'category' => $category->value],
        );
    }

    public function history(string $tenantId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT s.setting_key, s.category, s.value, s.updated_at,
                    NULLIF(btrim(COALESCE(u.first_name, '') || ' ' || COALESCE(u.last_name, '')), '') AS changed_by
             FROM tenant_settings s
             LEFT JOIN users u ON u.id = s.updated_by
             WHERE s.tenant_id = :tenant
             ORDER BY s.updated_at DESC, s.setting_key",
            ['tenant' => $tenantId],
        );

        return array_map(
            function (array $row): array {
                $definition = $this->catalogue->findByName((string) $row['setting_key']);

                return [
                    'key'       => (string) $row['setting_key'],
                    'label'     => $definition?->label,
                    'category'  => (string) $row['category'],
                    // Parsed, so a boolean reads as a boolean rather than as
                    // the string "false", which is true.
                    'value'     => $definition?->parse((string) $row['value']) ?? (string) $row['value'],
                    'changedBy' => is_string($row['changed_by'] ?? null) ? $row['changed_by'] : null,
                    'changedAt' => $this->instant($row['updated_at'] ?? null),
                ];
            },
            $rows,
        );
    }

    private function instant(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM);
        } catch (\Exception) {
            return null;
        }
    }
}
