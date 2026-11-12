<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Library\LibraryCategory;
use Central\Api\Domain\Library\LibraryCategoryRepository;
use Doctrine\DBAL\Connection;

final class DbalLibraryCategoryRepository implements LibraryCategoryRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function list(string $tenantId, ?string $visibleToUserId): array
    {
        $params = ['tenant' => $tenantId];
        $visible = '';
        if ($visibleToUserId !== null) {
            // The same audience rule the viewer's listing uses, so a count
            // never promises an item the list will not show.
            $visible = ' AND ' . DbalDigitalLibraryRepository::VISIBILITY;
            $params['userId'] = $visibleToUserId;
        }

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT c.id, c.tenant_id, c.name, c.description, c.sort_order,
                        (SELECT COUNT(*) FROM digital_library_items i
                          WHERE i.tenant_id = c.tenant_id AND i.category_id = c.id
                            AND i.deleted_at IS NULL%s) AS item_count
                   FROM library_categories c
                  WHERE c.tenant_id = :tenant
                  ORDER BY c.sort_order, lower(c.name), c.id',
                $visible,
            ),
            $params,
        );

        $uncategorised = (int) $this->connection->fetchOne(
            sprintf(
                'SELECT COUNT(*) FROM digital_library_items i
                  WHERE i.tenant_id = :tenant AND i.category_id IS NULL AND i.deleted_at IS NULL%s',
                $visible,
            ),
            $params,
        );

        return [
            'categories'         => array_map(fn (array $row): LibraryCategory => $this->hydrate($row), $rows),
            'uncategorisedCount' => $uncategorised,
        ];
    }

    public function find(string $tenantId, string $id): ?LibraryCategory
    {
        if (!LibraryCategory::looksLikeId($id)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT c.id, c.tenant_id, c.name, c.description, c.sort_order,
                    (SELECT COUNT(*) FROM digital_library_items i
                      WHERE i.tenant_id = c.tenant_id AND i.category_id = c.id
                        AND i.deleted_at IS NULL) AS item_count
               FROM library_categories c
              WHERE c.id = :id AND c.tenant_id = :tenant',
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function create(LibraryCategory $category): LibraryCategory
    {
        $id = (string) $this->connection->fetchOne(
            'INSERT INTO library_categories (tenant_id, name, description, sort_order)
             VALUES (:tenant, :name, :description, :sortOrder)
             RETURNING id',
            [
                'tenant'      => $category->tenantId,
                'name'        => $category->name,
                'description' => $category->description,
                'sortOrder'   => $category->sortOrder,
            ],
        );

        return $this->find($category->tenantId, $id)
            ?? throw new \RuntimeException('The library category vanished immediately after being created.');
    }

    public function update(LibraryCategory $category): ?LibraryCategory
    {
        if ($category->id === null) {
            return null;
        }

        $changed = $this->connection->executeStatement(
            'UPDATE library_categories
                SET name = :name, description = :description, sort_order = :sortOrder, updated_at = now()
              WHERE id = :id AND tenant_id = :tenant',
            [
                'id'          => $category->id,
                'tenant'      => $category->tenantId,
                'name'        => $category->name,
                'description' => $category->description,
                'sortOrder'   => $category->sortOrder,
            ],
        );

        return $changed === 0 ? null : $this->find($category->tenantId, $category->id);
    }

    public function delete(string $tenantId, string $id): bool
    {
        if (!LibraryCategory::looksLikeId($id)) {
            return false;
        }

        // Items in it fall back to uncategorised through the FK's SET NULL.
        return $this->connection->executeStatement(
            'DELETE FROM library_categories WHERE id = :id AND tenant_id = :tenant',
            ['id' => $id, 'tenant' => $tenantId],
        ) > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): LibraryCategory
    {
        return new LibraryCategory(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            name: (string) $row['name'],
            description: $row['description'] !== null ? (string) $row['description'] : null,
            sortOrder: (int) $row['sort_order'],
            itemCount: (int) $row['item_count'],
        );
    }
}
