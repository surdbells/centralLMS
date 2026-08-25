<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Catalog\CourseModule;
use Central\Api\Domain\Catalog\ModuleRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class DbalModuleRepository implements ModuleRepository
{
    private const COLUMNS = 'id, tenant_id, course_id, title, description, sort_order, is_active,
                             created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function forCourse(string $tenantId, string $courseId, bool $activeOnly = false): array
    {
        $sql = sprintf(
            'SELECT %s FROM course_modules
             WHERE tenant_id = :tenant AND course_id = :course AND deleted_at IS NULL',
            self::COLUMNS,
        );

        if ($activeOnly) {
            $sql .= ' AND is_active';
        }

        $rows = $this->connection->fetchAllAssociative(
            $sql . ' ORDER BY sort_order ASC, title ASC',
            ['tenant' => $tenantId, 'course' => $courseId],
        );

        return array_map(fn (array $row): CourseModule => $this->hydrate($row), $rows);
    }

    public function find(string $tenantId, string $courseId, string $id): ?CourseModule
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM course_modules
                 WHERE id = :id AND course_id = :course AND tenant_id = :tenant AND deleted_at IS NULL',
                self::COLUMNS,
            ),
            ['id' => $id, 'course' => $courseId, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function create(CourseModule $module): CourseModule
    {
        $id = $this->connection->fetchOne(
            'INSERT INTO course_modules (tenant_id, course_id, title, description, sort_order, is_active)
             VALUES (:tenant, :course, :title, :description, :sortOrder, :isActive)
             RETURNING id',
            $this->writeParams($module),
        );

        return $this->find($module->tenantId, $module->courseId, (string) $id)
            ?? throw new \RuntimeException('Newly created module could not be read back.');
    }

    public function update(CourseModule $module): CourseModule
    {
        $this->connection->executeStatement(
            'UPDATE course_modules SET
                title = :title, description = :description, sort_order = :sortOrder,
                is_active = :isActive, updated_at = NOW()
             WHERE id = :id AND course_id = :course AND tenant_id = :tenant AND deleted_at IS NULL',
            $this->writeParams($module) + ['id' => $module->id],
        );

        return $this->find($module->tenantId, $module->courseId, $module->id)
            ?? throw new \RuntimeException('Updated module could not be read back.');
    }

    public function setActive(string $tenantId, string $courseId, string $id, bool $isActive): ?CourseModule
    {
        $affected = $this->connection->executeStatement(
            'UPDATE course_modules SET is_active = :isActive, updated_at = NOW()
             WHERE id = :id AND course_id = :course AND tenant_id = :tenant AND deleted_at IS NULL',
            [
                'isActive' => $isActive ? 'true' : 'false',
                'id'       => $id,
                'course'   => $courseId,
                'tenant'   => $tenantId,
            ],
        );

        return $affected > 0 ? $this->find($tenantId, $courseId, $id) : null;
    }

    public function softDelete(string $tenantId, string $courseId, string $id): bool
    {
        return (bool) $this->connection->transactional(
            function (Connection $tx) use ($tenantId, $courseId, $id): bool {
                $affected = $tx->executeStatement(
                    'UPDATE course_modules SET deleted_at = NOW(), updated_at = NOW()
                     WHERE id = :id AND course_id = :course AND tenant_id = :tenant AND deleted_at IS NULL',
                    ['id' => $id, 'course' => $courseId, 'tenant' => $tenantId],
                );

                if ($affected === 0) {
                    return false;
                }

                // The lessons go with it, in the same transaction. A lesson
                // whose module is gone is unreachable but would still be
                // counted by anything reading lessons directly.
                $tx->executeStatement(
                    'UPDATE lessons SET deleted_at = NOW(), updated_at = NOW()
                     WHERE module_id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                    ['id' => $id, 'tenant' => $tenantId],
                );

                return true;
            },
        );
    }

    public function reorder(string $tenantId, string $courseId, array $orderedIds): int
    {
        if ($orderedIds === []) {
            return 0;
        }

        // One statement rather than one per module: a partially applied order
        // is worse than none, and this way it either all lands or none does.
        return (int) $this->connection->executeStatement(
            'UPDATE course_modules AS m
             SET sort_order = new_order.position, updated_at = NOW()
             FROM (SELECT * FROM unnest(:ids::uuid[]) WITH ORDINALITY AS t(id, position)) AS new_order
             WHERE m.id = new_order.id
               AND m.course_id = :course
               AND m.tenant_id = :tenant
               AND m.deleted_at IS NULL',
            [
                'ids'    => '{' . implode(',', $orderedIds) . '}',
                'course' => $courseId,
                'tenant' => $tenantId,
            ],
        );
    }

    public function existingIds(string $tenantId, string $courseId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<string> $found */
        $found = $this->connection->fetchFirstColumn(
            'SELECT id FROM course_modules
             WHERE tenant_id = :tenant AND course_id = :course AND deleted_at IS NULL AND id IN (:ids)',
            ['tenant' => $tenantId, 'course' => $courseId, 'ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        return $found;
    }

    public function nextSortOrder(string $tenantId, string $courseId): int
    {
        return 1 + (int) $this->connection->fetchOne(
            'SELECT COALESCE(MAX(sort_order), -1) FROM course_modules
             WHERE tenant_id = :tenant AND course_id = :course AND deleted_at IS NULL',
            ['tenant' => $tenantId, 'course' => $courseId],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function writeParams(CourseModule $module): array
    {
        return [
            'tenant'      => $module->tenantId,
            'course'      => $module->courseId,
            'title'       => $module->title,
            'description' => $module->description,
            'sortOrder'   => $module->sortOrder,
            'isActive'    => $module->isActive ? 'true' : 'false',
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): CourseModule
    {
        return new CourseModule(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            courseId: (string) $row['course_id'],
            title: (string) $row['title'],
            description: is_string($row['description'] ?? null) && $row['description'] !== ''
                ? $row['description']
                : null,
            sortOrder: (int) $row['sort_order'],
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
