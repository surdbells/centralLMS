<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Catalog\Lesson;
use Central\Api\Domain\Catalog\LessonContentType;
use Central\Api\Domain\Catalog\LessonRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class DbalLessonRepository implements LessonRepository
{
    private const COLUMNS = 'id, tenant_id, course_id, module_id, title, description,
                             content_type, content_url, content_text, duration_minutes,
                             sort_order, is_mandatory, is_preview, is_active,
                             created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function forModule(string $tenantId, string $moduleId, bool $activeOnly = false): array
    {
        $sql = sprintf(
            'SELECT %s FROM lessons
             WHERE tenant_id = :tenant AND module_id = :module AND deleted_at IS NULL',
            self::COLUMNS,
        );

        if ($activeOnly) {
            $sql .= ' AND is_active';
        }

        $rows = $this->connection->fetchAllAssociative(
            $sql . ' ORDER BY sort_order ASC, title ASC',
            ['tenant' => $tenantId, 'module' => $moduleId],
        );

        return array_map(fn (array $row): Lesson => $this->hydrate($row), $rows);
    }

    public function forCourseByModule(string $tenantId, string $courseId, bool $activeOnly = false): array
    {
        $sql = sprintf(
            'SELECT %s FROM lessons
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

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(string) $row['module_id']][] = $this->hydrate($row);
        }

        return $grouped;
    }

    public function find(string $tenantId, string $moduleId, string $id): ?Lesson
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM lessons
                 WHERE id = :id AND module_id = :module AND tenant_id = :tenant AND deleted_at IS NULL',
                self::COLUMNS,
            ),
            ['id' => $id, 'module' => $moduleId, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function findInCourse(string $tenantId, string $courseId, string $id): ?Lesson
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM lessons
                 WHERE id = :id AND course_id = :course AND tenant_id = :tenant AND deleted_at IS NULL',
                self::COLUMNS,
            ),
            ['id' => $id, 'course' => $courseId, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function create(Lesson $lesson): Lesson
    {
        $id = $this->connection->fetchOne(
            'INSERT INTO lessons (tenant_id, course_id, module_id, title, description,
                                  content_type, content_url, content_text, duration_minutes,
                                  sort_order, is_mandatory, is_preview, is_active)
             VALUES (:tenant, :course, :module, :title, :description,
                     :contentType, :contentUrl, :contentText, :duration,
                     :sortOrder, :isMandatory, :isPreview, :isActive)
             RETURNING id',
            $this->writeParams($lesson),
        );

        return $this->find($lesson->tenantId, $lesson->moduleId, (string) $id)
            ?? throw new \RuntimeException('Newly created lesson could not be read back.');
    }

    public function update(Lesson $lesson): Lesson
    {
        $this->connection->executeStatement(
            'UPDATE lessons SET
                title = :title, description = :description, content_type = :contentType,
                content_url = :contentUrl, content_text = :contentText,
                duration_minutes = :duration, sort_order = :sortOrder,
                is_mandatory = :isMandatory, is_preview = :isPreview, is_active = :isActive,
                updated_at = NOW()
             WHERE id = :id AND module_id = :module AND tenant_id = :tenant AND deleted_at IS NULL',
            // course_id is never updated: moving a lesson between courses would
            // orphan every progress row pointing at it.
            array_diff_key($this->writeParams($lesson), ['course' => null]) + ['id' => $lesson->id],
        );

        return $this->find($lesson->tenantId, $lesson->moduleId, $lesson->id)
            ?? throw new \RuntimeException('Updated lesson could not be read back.');
    }

    public function setActive(string $tenantId, string $moduleId, string $id, bool $isActive): ?Lesson
    {
        $affected = $this->connection->executeStatement(
            'UPDATE lessons SET is_active = :isActive, updated_at = NOW()
             WHERE id = :id AND module_id = :module AND tenant_id = :tenant AND deleted_at IS NULL',
            [
                'isActive' => $isActive ? 'true' : 'false',
                'id'       => $id,
                'module'   => $moduleId,
                'tenant'   => $tenantId,
            ],
        );

        return $affected > 0 ? $this->find($tenantId, $moduleId, $id) : null;
    }

    public function softDelete(string $tenantId, string $moduleId, string $id): bool
    {
        return $this->connection->executeStatement(
            'UPDATE lessons SET deleted_at = NOW(), updated_at = NOW()
             WHERE id = :id AND module_id = :module AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $id, 'module' => $moduleId, 'tenant' => $tenantId],
        ) > 0;
    }

    public function reorder(string $tenantId, string $moduleId, array $orderedIds): int
    {
        if ($orderedIds === []) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            'UPDATE lessons AS l
             SET sort_order = new_order.position, updated_at = NOW()
             FROM (SELECT * FROM unnest(:ids::uuid[]) WITH ORDINALITY AS t(id, position)) AS new_order
             WHERE l.id = new_order.id
               AND l.module_id = :module
               AND l.tenant_id = :tenant
               AND l.deleted_at IS NULL',
            [
                'ids'    => '{' . implode(',', $orderedIds) . '}',
                'module' => $moduleId,
                'tenant' => $tenantId,
            ],
        );
    }

    public function existingIds(string $tenantId, string $moduleId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<string> $found */
        $found = $this->connection->fetchFirstColumn(
            'SELECT id FROM lessons
             WHERE tenant_id = :tenant AND module_id = :module AND deleted_at IS NULL AND id IN (:ids)',
            ['tenant' => $tenantId, 'module' => $moduleId, 'ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        return $found;
    }

    public function nextSortOrder(string $tenantId, string $moduleId): int
    {
        return 1 + (int) $this->connection->fetchOne(
            'SELECT COALESCE(MAX(sort_order), -1) FROM lessons
             WHERE tenant_id = :tenant AND module_id = :module AND deleted_at IS NULL',
            ['tenant' => $tenantId, 'module' => $moduleId],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function writeParams(Lesson $lesson): array
    {
        return [
            'tenant'      => $lesson->tenantId,
            'course'      => $lesson->courseId,
            'module'      => $lesson->moduleId,
            'title'       => $lesson->title,
            'description' => $lesson->description,
            'contentType' => $lesson->contentType->value,
            'contentUrl'  => $lesson->contentUrl,
            'contentText' => $lesson->contentText,
            'duration'    => $lesson->durationMinutes,
            'sortOrder'   => $lesson->sortOrder,
            'isMandatory' => $lesson->isMandatory ? 'true' : 'false',
            'isPreview'   => $lesson->isPreview ? 'true' : 'false',
            'isActive'    => $lesson->isActive ? 'true' : 'false',
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Lesson
    {
        $contentType = LessonContentType::tryFrom((string) $row['content_type']);

        if ($contentType === null) {
            // The check constraint makes this unreachable. An unknown content
            // type must not be silently rendered as text.
            throw new \RuntimeException(sprintf('Unknown lesson content type "%s".', $row['content_type']));
        }

        return new Lesson(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            courseId: (string) $row['course_id'],
            moduleId: (string) $row['module_id'],
            title: (string) $row['title'],
            contentType: $contentType,
            description: $this->text($row['description'] ?? null),
            contentUrl: $this->text($row['content_url'] ?? null),
            contentText: $this->text($row['content_text'] ?? null),
            durationMinutes: isset($row['duration_minutes']) ? (int) $row['duration_minutes'] : null,
            sortOrder: (int) $row['sort_order'],
            isMandatory: (bool) $row['is_mandatory'],
            isPreview: (bool) $row['is_preview'],
            isActive: (bool) $row['is_active'],
            createdAt: $this->date($row['created_at'] ?? null),
            updatedAt: $this->date($row['updated_at'] ?? null),
        );
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
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
