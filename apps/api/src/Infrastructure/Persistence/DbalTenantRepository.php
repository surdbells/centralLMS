<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Tenant\Tenant;
use Central\Api\Domain\Tenant\TenantRepository;
use Doctrine\DBAL\Connection;

final class DbalTenantRepository implements TenantRepository
{
    private const COLUMNS = 'id, name, code, status, contact_email, contact_phone, address,
                             domain, logo_url, industry, company_size, users_limit,
                             storage_limit_mb, subscription_expires_at, created_at';

    /** What an organisation may change about itself. Nothing else is settable. */
    private const EDITABLE = [
        'name'         => 'name',
        'contactEmail' => 'contact_email',
        'contactPhone' => 'contact_phone',
        'address'      => 'address',
        'domain'       => 'domain',
        'logoUrl'      => 'logo_url',
        'industry'     => 'industry',
        'companySize'  => 'company_size',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function find(string $tenantId): ?Tenant
    {
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT %s FROM tenants WHERE id = :id AND deleted_at IS NULL', self::COLUMNS),
            ['id' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function nameOf(string $tenantId): ?string
    {
        $name = $this->connection->fetchOne(
            'SELECT name FROM tenants WHERE id = :id AND deleted_at IS NULL',
            ['id' => $tenantId],
        );

        return is_string($name) && trim($name) !== '' ? $name : null;
    }

    public function updateProfile(string $tenantId, array $changes): ?Tenant
    {
        $sets   = [];
        $params = ['id' => $tenantId];

        foreach (self::EDITABLE as $field => $column) {
            // Absent means "leave it alone"; present and null means "clear it".
            // A form that sends three fields must not blank the other five.
            if (array_key_exists($field, $changes)) {
                $sets[]          = sprintf('%s = :%s', $column, $field);
                $params[$field]  = $changes[$field];
            }
        }

        if ($sets === []) {
            return $this->find($tenantId);
        }

        $this->connection->executeStatement(
            sprintf(
                'UPDATE tenants SET %s, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL',
                implode(', ', $sets),
            ),
            $params,
        );

        return $this->find($tenantId);
    }

    public function usage(string $tenantId): array
    {
        // One pass. Three separate counts is three round trips for a panel
        // that shows them side by side.
        $row = $this->connection->fetchAssociative(
            "SELECT
                 (SELECT COUNT(*) FROM users
                   WHERE tenant_id = :id AND deleted_at IS NULL) AS people,
                 (SELECT COUNT(*) FROM courses
                   WHERE tenant_id = :id AND deleted_at IS NULL) AS courses,
                 (SELECT COALESCE(SUM(file_size), 0) FROM media
                   WHERE tenant_id = :id AND deleted_at IS NULL) AS bytes",
            ['id' => $tenantId],
        );

        $row = $row === false ? [] : $row;

        return [
            'users'     => (int) ($row['people'] ?? 0),
            'storageMb' => round(((int) ($row['bytes'] ?? 0)) / 1048576, 2),
            'courses'   => (int) ($row['courses'] ?? 0),
        ];
    }

    // --- internals --------------------------------------------------------

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Tenant
    {
        return new Tenant(
            id: (string) $row['id'],
            name: (string) $row['name'],
            code: (string) $row['code'],
            status: (string) $row['status'],
            contactEmail: $this->text($row['contact_email'] ?? null),
            contactPhone: $this->text($row['contact_phone'] ?? null),
            address: $this->text($row['address'] ?? null),
            domain: $this->text($row['domain'] ?? null),
            logoUrl: $this->text($row['logo_url'] ?? null),
            industry: $this->text($row['industry'] ?? null),
            companySize: $this->text($row['company_size'] ?? null),
            usersLimit: isset($row['users_limit']) ? (int) $row['users_limit'] : null,
            storageLimitMb: isset($row['storage_limit_mb']) ? (int) $row['storage_limit_mb'] : null,
            subscriptionExpiresAt: $this->text($row['subscription_expires_at'] ?? null),
            createdAt: $this->text($row['created_at'] ?? null),
        );
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
