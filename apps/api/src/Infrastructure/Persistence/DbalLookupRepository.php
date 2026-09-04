<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Lookup\Lookup;
use Central\Api\Domain\Lookup\LookupKind;
use Central\Api\Domain\Lookup\LookupRepository;
use Doctrine\DBAL\Connection;

/**
 * One repository for all six kinds of reference data.
 *
 * Replaces `Branches`, `Departments`, `JobTitles` and their siblings — six
 * classes whose SQL differed only in a table name and a column prefix.
 *
 * Every query is filtered by `tenant_id` and `kind` together. Neither is
 * optional anywhere in this class.
 */
final class DbalLookupRepository implements LookupRepository
{
    private const COLUMNS = 'id, tenant_id, kind, code, name, description, parent_id,
                             sort_order, is_active, attributes, created_at, updated_at';

    /**
     * Sortable columns, allowlisted because the value is interpolated into
     * ORDER BY, which cannot be parameterised.
     */
    private const SORTABLE = ['name', 'code', 'sort_order', 'created_at', 'updated_at', 'is_active'];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function paginate(
        string $tenantId,
        LookupKind $kind,
        int $page,
        int $perPage,
        ?string $search = null,
        string $sortBy = 'name',
        string $sortDirection = 'asc',
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $sortBy = in_array($sortBy, self::SORTABLE, true) ? $sortBy : 'name';
        $direction = strtolower($sortDirection) === 'desc' ? 'DESC' : 'ASC';

        $where = 'tenant_id = :tenant AND kind = :kind AND deleted_at IS NULL';
        $params = ['tenant' => $tenantId, 'kind' => $kind->value];

        if ($search !== null && trim($search) !== '') {
            $where .= ' AND (name ILIKE :search OR code ILIKE :search OR description ILIKE :search)';
            $params['search'] = '%' . trim($search) . '%';
        }

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM lookups WHERE %s', $where),
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s FROM lookups WHERE %s ORDER BY %s %s, name ASC LIMIT :limit OFFSET :offset',
                self::COLUMNS,
                $where,
                $sortBy,
                $direction,
            ),
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return [
            'items' => array_map(fn (array $row): Lookup => $this->hydrate($row), $rows),
            'total' => $total,
        ];
    }

    public function active(string $tenantId, LookupKind $kind): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s FROM lookups
                 WHERE tenant_id = :tenant AND kind = :kind AND is_active AND deleted_at IS NULL
                 ORDER BY sort_order ASC, name ASC',
                self::COLUMNS,
            ),
            ['tenant' => $tenantId, 'kind' => $kind->value],
        );

        return array_map(fn (array $row): Lookup => $this->hydrate($row), $rows);
    }

    public function find(string $tenantId, LookupKind $kind, string $id): ?Lookup
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM lookups
                 WHERE id = :id AND tenant_id = :tenant AND kind = :kind AND deleted_at IS NULL',
                self::COLUMNS,
            ),
            ['id' => $id, 'tenant' => $tenantId, 'kind' => $kind->value],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByName(string $tenantId, LookupKind $kind, string $name): ?Lookup
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM lookups
                 WHERE tenant_id = :tenant AND kind = :kind AND lower(name) = lower(:name)
                   AND deleted_at IS NULL',
                self::COLUMNS,
            ),
            ['tenant' => $tenantId, 'kind' => $kind->value, 'name' => $name],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function create(Lookup $lookup): Lookup
    {
        $id = $this->connection->fetchOne(
            'INSERT INTO lookups (tenant_id, kind, code, name, description, parent_id,
                                  sort_order, is_active, attributes)
             VALUES (:tenant, :kind, :code, :name, :description, :parent,
                     :sortOrder, :isActive, CAST(:attributes AS JSONB))
             RETURNING id',
            $this->writeParams($lookup),
        );

        return $this->find($lookup->tenantId, $lookup->kind, (string) $id)
            ?? throw new \RuntimeException('Newly created lookup could not be read back.');
    }

    public function update(Lookup $lookup): Lookup
    {
        return $this->connection->transactional(function (Connection $tx) use ($lookup): Lookup {
            // A cycle is named on each competency_assessments row rather than
            // pointed at by id, so renaming one must carry those rows with it —
            // otherwise they would drop out of every cycle-scoped view. This
            // runs before the name changes, reading the old name off the lookup.
            // The `cycle <> :new` clause makes it touch rows only on an actual
            // rename: a save that changes only the description matches nothing.
            if ($lookup->kind === LookupKind::Cycle) {
                $tx->executeStatement(
                    'UPDATE competency_assessments SET cycle = :new, updated_at = NOW()
                     WHERE tenant_id = :tenant AND deleted_at IS NULL
                       AND cycle <> :new
                       AND cycle = (
                           SELECT name FROM lookups
                           WHERE id = :id AND tenant_id = :tenant AND kind = :kind
                       )',
                    ['new' => $lookup->name, 'tenant' => $lookup->tenantId, 'id' => $lookup->id, 'kind' => $lookup->kind->value],
                );
            }

            $tx->executeStatement(
                'UPDATE lookups
                 SET code = :code, name = :name, description = :description, parent_id = :parent,
                     sort_order = :sortOrder, is_active = :isActive,
                     attributes = CAST(:attributes AS JSONB), updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tenant AND kind = :kind AND deleted_at IS NULL',
                [...$this->writeParams($lookup), 'id' => $lookup->id],
            );

            return $this->find($lookup->tenantId, $lookup->kind, $lookup->id)
                ?? throw new \RuntimeException('Updated lookup could not be read back.');
        });
    }

    public function setActive(string $tenantId, LookupKind $kind, string $id, bool $isActive): bool
    {
        $affected = $this->connection->executeStatement(
            'UPDATE lookups SET is_active = :active, updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND kind = :kind AND deleted_at IS NULL',
            [
                'active' => $isActive ? 'true' : 'false',
                'id'     => $id,
                'tenant' => $tenantId,
                'kind'   => $kind->value,
            ],
        );

        return $affected > 0;
    }

    public function softDelete(string $tenantId, LookupKind $kind, string $id): bool
    {
        $affected = $this->connection->executeStatement(
            'UPDATE lookups SET deleted_at = NOW(), is_active = FALSE
             WHERE id = :id AND tenant_id = :tenant AND kind = :kind AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId, 'kind' => $kind->value],
        );

        return $affected > 0;
    }

    public function existsWithName(string $tenantId, LookupKind $kind, string $name, ?string $exceptId = null): bool
    {
        return $this->exists($tenantId, $kind, 'lower(name) = lower(:value)', $name, $exceptId);
    }

    public function existsWithCode(string $tenantId, LookupKind $kind, string $code, ?string $exceptId = null): bool
    {
        return $this->exists($tenantId, $kind, 'lower(code) = lower(:value)', $code, $exceptId);
    }

    private function exists(
        string $tenantId,
        LookupKind $kind,
        string $condition,
        string $value,
        ?string $exceptId,
    ): bool {
        $sql = sprintf(
            'SELECT 1 FROM lookups
             WHERE tenant_id = :tenant AND kind = :kind AND %s AND deleted_at IS NULL',
            $condition,
        );

        $params = ['tenant' => $tenantId, 'kind' => $kind->value, 'value' => $value];

        // So an update can keep its own name without colliding with itself.
        if ($exceptId !== null) {
            $sql .= ' AND id <> :exceptId';
            $params['exceptId'] = $exceptId;
        }

        return $this->connection->fetchOne($sql . ' LIMIT 1', $params) !== false;
    }

    /**
     * @return array<string, mixed>
     */
    private function writeParams(Lookup $lookup): array
    {
        return [
            'tenant'      => $lookup->tenantId,
            'kind'        => $lookup->kind->value,
            'code'        => $lookup->code,
            'name'        => $lookup->name,
            'description' => $lookup->description,
            'parent'      => $lookup->parentId,
            'sortOrder'   => $lookup->sortOrder,
            'isActive'    => $lookup->isActive ? 'true' : 'false',
            'attributes'  => json_encode($lookup->attributes, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Lookup
    {
        $kind = LookupKind::tryFrom((string) $row['kind']);

        if ($kind === null) {
            // Unreachable while the check constraint holds; an unknown kind
            // must never be silently treated as one of the others.
            throw new \RuntimeException(sprintf('Unknown lookup kind "%s".', $row['kind']));
        }

        $attributes = [];

        if (is_string($row['attributes']) && $row['attributes'] !== '') {
            $decoded = json_decode($row['attributes'], true);
            $attributes = is_array($decoded) ? $decoded : [];
        }

        return new Lookup(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            kind: $kind,
            name: (string) $row['name'],
            code: $this->nullableString($row['code'] ?? null),
            description: $this->nullableString($row['description'] ?? null),
            parentId: $this->nullableString($row['parent_id'] ?? null),
            sortOrder: (int) $row['sort_order'],
            isActive: (bool) $row['is_active'],
            attributes: $attributes,
            createdAt: $this->asDate($row['created_at'] ?? null),
            updatedAt: $this->asDate($row['updated_at'] ?? null),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function asDate(mixed $value): ?\DateTimeImmutable
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
