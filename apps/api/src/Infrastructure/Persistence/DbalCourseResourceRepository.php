<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Catalog\CourseResource;
use Central\Api\Domain\Catalog\CourseResourceRepository;
use Doctrine\DBAL\Connection;

final readonly class DbalCourseResourceRepository implements CourseResourceRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function forCourse(string $tenantId, string $courseId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM course_resources
             WHERE tenant_id = :tenant AND course_id = :course AND deleted_at IS NULL
             ORDER BY position, created_at',
            ['tenant' => $tenantId, 'course' => $courseId],
        );

        return array_map(fn (array $r): CourseResource => $this->hydrate($r), $rows);
    }

    public function find(string $tenantId, string $id): ?CourseResource
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM course_resources
             WHERE tenant_id = :tenant AND id = :id AND deleted_at IS NULL',
            ['tenant' => $tenantId, 'id' => $id],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function add(CourseResource $resource): CourseResource
    {
        // Append to the end: one more than the current highest position.
        $nextPosition = (int) $this->connection->fetchOne(
            'SELECT COALESCE(MAX(position), -1) + 1 FROM course_resources
             WHERE tenant_id = :tenant AND course_id = :course AND deleted_at IS NULL',
            ['tenant' => $resource->tenantId, 'course' => $resource->courseId],
        );

        $id = $this->connection->fetchOne(
            'INSERT INTO course_resources
                (tenant_id, course_id, title, original_filename, storage_key,
                 mime_type, size_bytes, position, created_by)
             VALUES (:tenant, :course, :title, :filename, :key, :mime, :size, :position, :creator)
             RETURNING id',
            [
                'tenant'   => $resource->tenantId,
                'course'   => $resource->courseId,
                'title'    => $resource->title,
                'filename' => $resource->originalFilename,
                'key'      => $resource->storageKey,
                'mime'     => $resource->mimeType,
                'size'     => $resource->sizeBytes,
                'position' => $nextPosition,
                'creator'  => $resource->createdBy,
            ],
        );

        return $this->find($resource->tenantId, (string) $id)
            ?? throw new \RuntimeException('The resource could not be read back after saving.');
    }

    public function remove(string $tenantId, string $id): ?string
    {
        $key = $this->connection->fetchOne(
            'SELECT storage_key FROM course_resources
             WHERE tenant_id = :tenant AND id = :id AND deleted_at IS NULL',
            ['tenant' => $tenantId, 'id' => $id],
        );

        if ($key === false) {
            return null;
        }

        $this->connection->executeStatement(
            'UPDATE course_resources SET deleted_at = NOW()
             WHERE tenant_id = :tenant AND id = :id',
            ['tenant' => $tenantId, 'id' => $id],
        );

        return (string) $key;
    }

    /**
     * @param array<string, mixed> $r
     */
    private function hydrate(array $r): CourseResource
    {
        return new CourseResource(
            id: (string) $r['id'],
            tenantId: (string) $r['tenant_id'],
            courseId: (string) $r['course_id'],
            title: (string) $r['title'],
            originalFilename: (string) $r['original_filename'],
            storageKey: (string) $r['storage_key'],
            mimeType: (string) $r['mime_type'],
            sizeBytes: (int) $r['size_bytes'],
            position: (int) $r['position'],
            createdBy: $r['created_by'] === null ? null : (string) $r['created_by'],
            createdAt: isset($r['created_at']) ? new \DateTimeImmutable((string) $r['created_at']) : null,
        );
    }
}
