<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Audience\AudienceTarget;
use Central\Api\Domain\Library\ConversionStatus;
use Central\Api\Domain\Library\DigitalLibraryItem;
use Central\Api\Domain\Library\DigitalLibraryRepository;
use Central\Api\Domain\Media\MediaKind;
use Doctrine\DBAL\Connection;

final class DbalDigitalLibraryRepository implements DigitalLibraryRepository
{
    private const COLUMNS = '
        i.id, i.tenant_id, i.uploaded_by, i.kind, i.title, i.description,
        i.original_filename, i.storage_key, i.file_size, i.mime_type, i.extension,
        i.page_count, i.duration_seconds, i.converted_pdf_key, i.conversion_status,
        i.target_type, i.target_criteria, i.sha256, i.metadata,
        i.created_at, i.updated_at,
        NULLIF(btrim(COALESCE(u.first_name, \'\') || \' \' || COALESCE(u.last_name, \'\')), \'\') AS uploader_name';

    private const FROM = '
        FROM digital_library_items i
        LEFT JOIN users u ON u.id = i.uploaded_by';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function create(DigitalLibraryItem $item): DigitalLibraryItem
    {
        $id = (string) $this->connection->fetchOne(
            'INSERT INTO digital_library_items
                 (tenant_id, uploaded_by, kind, title, description,
                  original_filename, storage_key, file_size, mime_type, extension,
                  page_count, duration_seconds, converted_pdf_key, conversion_status,
                  target_type, target_criteria, sha256, metadata)
             VALUES (:tenant, :uploadedBy, :kind, :title, :description,
                     :originalFilename, :storageKey, :fileSize, :mimeType, :extension,
                     :pageCount, :duration, :convertedPdfKey, :conversionStatus,
                     :targetType, :targetCriteria::jsonb, :sha256, :metadata::jsonb)
             RETURNING id',
            $this->writableColumns($item),
        );

        return $this->find($item->tenantId, $id)
            ?? throw new \RuntimeException('The library item vanished immediately after being created.');
    }

    public function find(string $tenantId, string $id): ?DigitalLibraryItem
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s %s WHERE i.id = :id AND i.tenant_id = :tenant AND i.deleted_at IS NULL',
                self::COLUMNS,
                self::FROM,
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function listAll(string $tenantId, ?string $search, int $page, int $perPage): array
    {
        [$searchWhere, $params] = $this->search($search);
        $params['tenant'] = $tenantId;
        $where = 'i.tenant_id = :tenant AND i.deleted_at IS NULL' . $searchWhere;

        return $this->page($where, $params, $page, $perPage);
    }

    public function listVisibleTo(string $tenantId, string $userId, ?string $search, int $page, int $perPage): array
    {
        [$searchWhere, $params] = $this->search($search);
        $params['tenant'] = $tenantId;
        $params['userId'] = $userId;
        $where = 'i.tenant_id = :tenant AND i.deleted_at IS NULL AND ' . self::VISIBILITY . $searchWhere;

        return $this->page($where, $params, $page, $perPage);
    }

    public function isVisibleTo(string $tenantId, string $id, string $userId): bool
    {
        $found = $this->connection->fetchOne(
            'SELECT 1 FROM digital_library_items i
             WHERE i.id = :id AND i.tenant_id = :tenant AND i.deleted_at IS NULL AND ' . self::VISIBILITY,
            ['id' => $id, 'tenant' => $tenantId, 'userId' => $userId],
        );

        return $found !== false;
    }

    public function updateDetails(
        string $tenantId,
        string $id,
        string $title,
        ?string $description,
        AudienceTarget $targetType,
        array $targetCriteria,
    ): ?DigitalLibraryItem {
        $changed = $this->connection->executeStatement(
            'UPDATE digital_library_items SET
                 title = :title, description = :description,
                 target_type = :targetType, target_criteria = :targetCriteria::jsonb,
                 updated_at = now()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            [
                'id'             => $id,
                'tenant'         => $tenantId,
                'title'          => $title,
                'description'    => $description,
                'targetType'     => $targetType->value,
                'targetCriteria' => json_encode((object) $targetCriteria, JSON_THROW_ON_ERROR),
            ],
        );

        return $changed === 0 ? null : $this->find($tenantId, $id);
    }

    public function softDelete(string $tenantId, string $id): bool
    {
        return $this->connection->executeStatement(
            'UPDATE digital_library_items SET deleted_at = now()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId],
        ) > 0;
    }

    public function setConversion(
        string $tenantId,
        string $id,
        ConversionStatus $status,
        ?string $convertedPdfKey = null,
        ?int $pageCount = null,
    ): ?DigitalLibraryItem {
        $changed = $this->connection->executeStatement(
            'UPDATE digital_library_items SET
                 conversion_status = :status,
                 converted_pdf_key = COALESCE(:key, converted_pdf_key),
                 page_count = COALESCE(:pageCount, page_count),
                 updated_at = now()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            [
                'id'        => $id,
                'tenant'    => $tenantId,
                'status'    => $status->value,
                'key'       => $convertedPdfKey,
                'pageCount' => $pageCount,
            ],
        );

        return $changed === 0 ? null : $this->find($tenantId, $id);
    }

    public function findByChecksum(string $tenantId, string $sha256): ?DigitalLibraryItem
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s %s WHERE i.tenant_id = :tenant AND i.sha256 = :sha AND i.deleted_at IS NULL
                 ORDER BY i.created_at DESC LIMIT 1',
                self::COLUMNS,
                self::FROM,
            ),
            ['tenant' => $tenantId, 'sha' => $sha256],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    // --- internals ------------------------------------------------------------

    /**
     * The audience test, item against user. `jsonb_exists(...)` is used rather
     * than the `?` operator, which PDO reads as a positional placeholder.
     * `department_id::text` is NULL for an unplaced user, and jsonb_exists of a
     * NULL needle is false, so an unplaced user matches only `all`.
     */
    private const VISIBILITY = "(
        i.target_type = 'all'
        OR EXISTS (
            SELECT 1 FROM users usr
            WHERE usr.id = :userId AND usr.tenant_id = i.tenant_id
              AND (
                  (i.target_type = 'department' AND jsonb_exists(i.target_criteria->'departmentIds', usr.department_id::text))
                  OR (i.target_type = 'branch' AND jsonb_exists(i.target_criteria->'branchIds', usr.branch_id::text))
                  OR (i.target_type = 'team' AND jsonb_exists(i.target_criteria->'teamIds', usr.team_id::text))
              )
        )
        OR (i.target_type = 'role' AND EXISTS (
            SELECT 1 FROM user_roles ur
            WHERE ur.user_id = :userId AND jsonb_exists(i.target_criteria->'roleIds', ur.role_id::text)
        ))
    )";

    /**
     * @param  array<string, mixed> $params
     * @return array{items: list<DigitalLibraryItem>, total: int}
     */
    private function page(string $where, array $params, int $page, int $perPage): array
    {
        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM digital_library_items i WHERE %s', $where),
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s %s WHERE %s ORDER BY i.created_at DESC, i.id DESC LIMIT :limit OFFSET :offset',
                self::COLUMNS,
                self::FROM,
                $where,
            ),
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return [
            'items' => array_map(fn (array $row): DigitalLibraryItem => $this->hydrate($row), $rows),
            'total' => $total,
        ];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function search(?string $search): array
    {
        if ($search === null || trim($search) === '') {
            return ['', []];
        }

        return [
            ' AND (i.title ILIKE :search OR i.description ILIKE :search OR i.original_filename ILIKE :search)',
            ['search' => '%' . trim($search) . '%'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): DigitalLibraryItem
    {
        return new DigitalLibraryItem(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            kind: MediaKind::from((string) $row['kind']),
            title: (string) $row['title'],
            originalFilename: (string) $row['original_filename'],
            storageKey: (string) $row['storage_key'],
            fileSize: (int) $row['file_size'],
            mimeType: (string) $row['mime_type'],
            extension: (string) $row['extension'],
            targetType: AudienceTarget::from((string) $row['target_type']),
            targetCriteria: $this->decodeCriteria($row['target_criteria'] ?? null),
            description: $row['description'] !== null ? (string) $row['description'] : null,
            pageCount: $row['page_count'] !== null ? (int) $row['page_count'] : null,
            durationSeconds: $row['duration_seconds'] !== null ? (float) $row['duration_seconds'] : null,
            convertedPdfKey: $row['converted_pdf_key'] !== null ? (string) $row['converted_pdf_key'] : null,
            conversionStatus: ConversionStatus::fromValue($row['conversion_status'] ?? null),
            sha256: $row['sha256'] !== null ? (string) $row['sha256'] : null,
            metadata: $this->decodeObject($row['metadata'] ?? null),
            uploadedBy: $row['uploaded_by'] !== null ? (string) $row['uploaded_by'] : null,
            uploaderName: $row['uploader_name'] !== null ? (string) $row['uploader_name'] : null,
            createdAt: $this->time($row['created_at'] ?? null),
            updatedAt: $this->time($row['updated_at'] ?? null),
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function decodeCriteria(mixed $value): array
    {
        $decoded = $this->decodeObject($value);
        $out = [];
        foreach ($decoded as $key => $list) {
            if (is_array($list)) {
                $out[(string) $key] = array_values(array_map(static fn ($v): string => (string) $v, $list));
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function time(mixed $value): ?\DateTimeImmutable
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

    /**
     * @return array<string, mixed>
     */
    private function writableColumns(DigitalLibraryItem $item): array
    {
        return [
            'tenant'           => $item->tenantId,
            'uploadedBy'       => $item->uploadedBy,
            'kind'             => $item->kind->value,
            'title'            => $item->title,
            'description'      => $item->description,
            'originalFilename' => $item->originalFilename,
            'storageKey'       => $item->storageKey,
            'fileSize'         => $item->fileSize,
            'mimeType'         => $item->mimeType,
            'extension'        => $item->extension,
            'pageCount'        => $item->pageCount,
            'duration'         => $item->durationSeconds,
            'convertedPdfKey'  => $item->convertedPdfKey,
            'conversionStatus' => $item->conversionStatus->value,
            'targetType'       => $item->targetType->value,
            'targetCriteria'   => json_encode((object) $item->targetCriteria, JSON_THROW_ON_ERROR),
            'sha256'           => $item->sha256,
            'metadata'         => json_encode((object) $item->metadata, JSON_THROW_ON_ERROR),
        ];
    }
}
