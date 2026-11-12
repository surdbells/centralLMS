<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Report\SavedCustomReport;
use Central\Api\Domain\Report\SavedCustomReportRepository;
use Doctrine\DBAL\Connection;

final class DbalSavedCustomReportRepository implements SavedCustomReportRepository
{
    private const COLUMNS = 'r.id, r.tenant_id, r.user_id, r.name, r.description, r.source, r.config,
                             r.created_at, r.updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function forUser(string $tenantId, string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s FROM custom_reports r
                 WHERE r.tenant_id = :tenant AND r.user_id = :user AND r.deleted_at IS NULL
                 ORDER BY r.updated_at DESC, r.id
                 LIMIT 200',
                self::COLUMNS,
            ),
            ['tenant' => $tenantId, 'user' => $userId],
        );

        return array_map(fn (array $row): SavedCustomReport => $this->hydrate($row), $rows);
    }

    public function find(string $tenantId, string $id): ?SavedCustomReport
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM custom_reports r
                 WHERE r.id = :id AND r.tenant_id = :tenant AND r.deleted_at IS NULL',
                self::COLUMNS,
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function save(SavedCustomReport $report): SavedCustomReport
    {
        $params = [
            'tenant'      => $report->tenantId,
            'user'        => $report->userId,
            'name'        => $report->name,
            'description' => $report->description,
            'source'      => $report->source,
            'config'      => json_encode($report->config, JSON_THROW_ON_ERROR),
        ];

        if ($report->id === null) {
            $id = (string) $this->connection->fetchOne(
                'INSERT INTO custom_reports (tenant_id, user_id, name, description, source, config)
                 VALUES (:tenant, :user, :name, :description, :source, :config::jsonb)
                 RETURNING id',
                $params,
            );
        } else {
            $id = $report->id;

            $this->connection->executeStatement(
                'UPDATE custom_reports
                 SET name = :name, description = :description, source = :source,
                     config = :config::jsonb, updated_at = now()
                 WHERE id = :id AND tenant_id = :tenant AND user_id = :user AND deleted_at IS NULL',
                [...$params, 'id' => $id],
            );
        }

        return $this->find($report->tenantId, $id)
            ?? throw new \RuntimeException('The custom report vanished immediately after being written.');
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->connection->executeStatement(
            'UPDATE custom_reports SET deleted_at = now()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId],
        ) > 0;
    }

    public function hasName(string $tenantId, string $userId, string $name, ?string $exceptId = null): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM custom_reports
             WHERE tenant_id = :tenant AND user_id = :user
               AND lower(btrim(name)) = lower(btrim(:name))
               AND deleted_at IS NULL
               AND (:exceptId::uuid IS NULL OR id <> :exceptId::uuid)',
            ['tenant' => $tenantId, 'user' => $userId, 'name' => $name, 'exceptId' => $exceptId],
        ) > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): SavedCustomReport
    {
        $config = is_string($row['config'] ?? null) ? json_decode($row['config'], true) : null;

        return new SavedCustomReport(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            userId: (string) $row['user_id'],
            name: (string) $row['name'],
            description: is_string($row['description'] ?? null) && $row['description'] !== '' ? $row['description'] : null,
            source: (string) $row['source'],
            config: is_array($config) ? $config : [],
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
