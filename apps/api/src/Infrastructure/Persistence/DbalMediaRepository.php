<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Media\MediaFilters;
use Central\Api\Domain\Media\MediaItem;
use Central\Api\Domain\Media\MediaKind;
use Central\Api\Domain\Media\MediaRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class DbalMediaRepository implements MediaRepository
{
    private const COLUMNS = 'm.id, m.tenant_id, m.uploaded_by, m.kind, m.title, m.description,
                             m.original_filename, m.storage_key, m.file_size, m.mime_type, m.extension,
                             m.duration_seconds, m.width, m.height, m.page_count,
                             m.scorm_version, m.launch_url, m.extracted_path,
                             m.metadata, m.sha256,
                             m.root_media_id, m.version_number, m.version_label, m.version_notes,
                             m.course_id, m.created_at, m.updated_at';

    /**
     * What `is_latest_version` used to be a column for.
     *
     * One pass over the chain answers both questions the library asks of a
     * file: whether this row is the current version, and how many versions
     * there are. Stored, the first of those needed a second UPDATE on every
     * upload, and a failure between the two left a file with two current
     * versions or none.
     */
    private const VERSIONS = 'vers.latest_version, vers.version_count';

    private const VERSION_JOIN = 'LEFT JOIN LATERAL (
             SELECT MAX(v.version_number) AS latest_version, COUNT(*) AS version_count
             FROM media v
             WHERE v.root_media_id = m.root_media_id AND v.deleted_at IS NULL
         ) vers ON TRUE';

    private const NAMES = "NULLIF(btrim(COALESCE(u.first_name, '') || ' ' || COALESCE(u.last_name, '')), '')
                               AS uploader_name,
                           c.title AS course_title";

    private const NAME_JOINS = 'LEFT JOIN users u ON u.id = m.uploaded_by
                                LEFT JOIN courses c ON c.id = m.course_id';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function paginate(string $tenantId, MediaFilters $filters, int $page, int $perPage): array
    {
        [$where, $params] = $this->conditions($tenantId, $filters);

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM media m %s WHERE %s', self::VERSION_JOIN, $where),
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s, %s, %s FROM media m %s %s WHERE %s
                 ORDER BY m.created_at DESC, m.id DESC
                 LIMIT :limit OFFSET :offset',
                self::COLUMNS,
                self::VERSIONS,
                self::NAMES,
                self::VERSION_JOIN,
                self::NAME_JOINS,
                $where,
            ),
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return [
            'items' => array_map(fn (array $row): MediaItem => $this->hydrate($row), $rows),
            'total' => $total,
        ];
    }

    public function find(string $tenantId, string $id): ?MediaItem
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, %s, %s FROM media m %s %s
                 WHERE m.id = :id AND m.tenant_id = :tenant AND m.deleted_at IS NULL',
                self::COLUMNS,
                self::VERSIONS,
                self::NAMES,
                self::VERSION_JOIN,
                self::NAME_JOINS,
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function versionsOf(string $tenantId, string $rootMediaId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s, %s, %s FROM media m %s %s
                 WHERE m.root_media_id = :root AND m.tenant_id = :tenant AND m.deleted_at IS NULL
                 ORDER BY m.version_number DESC',
                self::COLUMNS,
                self::VERSIONS,
                self::NAMES,
                self::VERSION_JOIN,
                self::NAME_JOINS,
            ),
            ['root' => $rootMediaId, 'tenant' => $tenantId],
        );

        return array_map(fn (array $row): MediaItem => $this->hydrate($row), $rows);
    }

    public function create(MediaItem $item): MediaItem
    {
        // The first upload is its own root, which needs the generated id in
        // two columns of the same INSERT.
        $id = (string) $this->connection->fetchOne(
            'WITH fresh AS (SELECT gen_random_uuid() AS id)
             INSERT INTO media
                 (id, root_media_id, tenant_id, uploaded_by, kind, title, description,
                  original_filename, storage_key, file_size, mime_type, extension,
                  duration_seconds, width, height, page_count,
                  scorm_version, launch_url, extracted_path, metadata, sha256,
                  version_number, version_label, version_notes, course_id)
             SELECT fresh.id, fresh.id, :tenant, :uploadedBy, :kind, :title, :description,
                    :originalFilename, :storageKey, :fileSize, :mimeType, :extension,
                    :duration, :width, :height, :pageCount,
                    :scormVersion, :launchUrl, :extractedPath, :metadata::jsonb, :sha256,
                    1, :versionLabel, :versionNotes, :courseId
             FROM fresh
             RETURNING id',
            $this->writableColumns($item),
        );

        return $this->find($item->tenantId, $id)
            ?? throw new \RuntimeException('The file vanished immediately after being uploaded.');
    }

    public function createVersion(MediaItem $item, string $replacesId): ?MediaItem
    {
        $id = $this->connection->transactional(
            function (Connection $tx) use ($item, $replacesId): ?string {
                $root = $tx->fetchOne(
                    'SELECT m.root_media_id FROM media m
                     WHERE m.id = :id AND m.tenant_id = :tenant AND m.deleted_at IS NULL',
                    ['id' => $replacesId, 'tenant' => $item->tenantId],
                );

                if (!is_string($root) || $root === '') {
                    return null;
                }

                // The first version of a file is the whole chain's lock. Two
                // people uploading a replacement at the same moment would
                // otherwise both read two and both write three, and the unique
                // index would reject one of them after its file was stored.
                $tx->fetchOne('SELECT id FROM media WHERE id = :root FOR UPDATE', ['root' => $root]);

                $next = 1 + (int) $tx->fetchOne(
                    'SELECT COALESCE(MAX(version_number), 0) FROM media
                     WHERE root_media_id = :root AND deleted_at IS NULL',
                    ['root' => $root],
                );

                return (string) $tx->fetchOne(
                    'INSERT INTO media
                         (root_media_id, tenant_id, uploaded_by, kind, title, description,
                          original_filename, storage_key, file_size, mime_type, extension,
                          duration_seconds, width, height, page_count,
                          scorm_version, launch_url, extracted_path, metadata, sha256,
                          version_number, version_label, version_notes, course_id)
                     VALUES (:root, :tenant, :uploadedBy, :kind, :title, :description,
                             :originalFilename, :storageKey, :fileSize, :mimeType, :extension,
                             :duration, :width, :height, :pageCount,
                             :scormVersion, :launchUrl, :extractedPath, :metadata::jsonb, :sha256,
                             :versionNumber, :versionLabel, :versionNotes, :courseId)
                     RETURNING id',
                    [...$this->writableColumns($item), 'root' => $root, 'versionNumber' => $next],
                );
            },
        );

        return $id === null ? null : $this->find($item->tenantId, $id);
    }

    public function update(MediaItem $item): ?MediaItem
    {
        $changed = $this->connection->executeStatement(
            'UPDATE media SET
                 title = :title, description = :description, course_id = :courseId,
                 version_label = :versionLabel, version_notes = :versionNotes,
                 metadata = :metadata::jsonb, updated_at = now()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            [
                'id'           => (string) $item->id,
                'tenant'       => $item->tenantId,
                'title'        => $item->title,
                'description'  => $item->description,
                'courseId'     => $item->courseId,
                'versionLabel' => $item->versionLabel,
                'versionNotes' => $item->versionNotes,
                'metadata'     => $this->encode($item->metadata),
            ],
        );

        return $changed === 0 ? null : $this->find($item->tenantId, (string) $item->id);
    }

    public function markExtracted(
        string $tenantId,
        string $id,
        string $extractedPath,
        string $launchUrl,
        string $scormVersion,
    ): void {
        $this->connection->executeStatement(
            'UPDATE media SET
                 extracted_path = :path, launch_url = :launch, scorm_version = :version,
                 updated_at = now()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            [
                'id'      => $id,
                'tenant'  => $tenantId,
                'path'    => $extractedPath,
                'launch'  => $launchUrl,
                'version' => $scormVersion,
            ],
        );
    }

    public function pendingScormExtractions(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s, %s FROM media m %s
                 WHERE m.kind = :scorm AND m.extracted_path IS NULL AND m.deleted_at IS NULL
                 ORDER BY m.created_at ASC',
                self::COLUMNS,
                self::VERSIONS,
                self::VERSION_JOIN,
            ),
            ['scorm' => MediaKind::Scorm->value],
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function softDelete(string $tenantId, string $id, bool $allVersions = false): array
    {
        return $this->connection->transactional(
            function (Connection $tx) use ($tenantId, $id, $allVersions): array {
                $removed = $tx->fetchFirstColumn(
                    $allVersions
                        ? 'UPDATE media SET deleted_at = now(), updated_at = now()
                           WHERE root_media_id = (
                                     SELECT root_media_id FROM media
                                     WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL
                                 )
                             AND tenant_id = :tenant AND deleted_at IS NULL
                           RETURNING storage_key'
                        : 'UPDATE media SET deleted_at = now(), updated_at = now()
                           WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL
                           RETURNING storage_key',
                    ['id' => $id, 'tenant' => $tenantId],
                );

                if ($removed === []) {
                    return [];
                }

                $keys = array_values(array_unique(array_map(strval(...), $removed)));

                // Only the keys nothing else points at. An upload matched to
                // an existing file by checksum shares its key, and deleting
                // one of the two must not take the file out from under the
                // other.
                $stillUsed = $tx->fetchFirstColumn(
                    'SELECT DISTINCT storage_key FROM media
                     WHERE storage_key IN (:keys) AND deleted_at IS NULL',
                    ['keys' => $keys],
                    ['keys' => ArrayParameterType::STRING],
                );

                return array_values(array_diff($keys, array_map(strval(...), $stillUsed)));
            },
        );
    }

    public function findByChecksum(string $tenantId, string $sha256): ?MediaItem
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, %s, %s FROM media m %s %s
                 WHERE m.tenant_id = :tenant AND m.sha256 = :sha AND m.deleted_at IS NULL
                 ORDER BY m.created_at ASC, m.id ASC
                 LIMIT 1',
                self::COLUMNS,
                self::VERSIONS,
                self::NAMES,
                self::VERSION_JOIN,
                self::NAME_JOINS,
            ),
            ['tenant' => $tenantId, 'sha' => $sha256],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function statistics(string $tenantId): array
    {
        $byKind = [];

        foreach (MediaKind::values() as $kind) {
            $byKind[$kind] = 0;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT m.kind, COUNT(*) AS files, COALESCE(SUM(m.file_size), 0) AS bytes
             FROM media m
             WHERE m.tenant_id = :tenant AND m.deleted_at IS NULL
             GROUP BY m.kind',
            ['tenant' => $tenantId],
        );

        $total = 0;
        $bytes = 0;

        foreach ($rows as $row) {
            $byKind[(string) $row['kind']] = (int) $row['files'];

            $total += (int) $row['files'];
            // Every version counts towards the bytes: old ones still occupy
            // the bucket, which is what the person paying for it is asking
            // about.
            $bytes += (int) $row['bytes'];
        }

        return ['total' => $total, 'byKind' => $byKind, 'bytesUsed' => $bytes];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function conditions(string $tenantId, MediaFilters $filters): array
    {
        $where  = ['m.tenant_id = :tenant', 'm.deleted_at IS NULL'];
        $params = ['tenant' => $tenantId];

        if (!$filters->includeOldVersions) {
            $where[] = 'm.version_number = vers.latest_version';
        }

        if ($filters->kind !== null) {
            $where[]        = 'm.kind = :kind';
            $params['kind'] = $filters->kind->value;
        }

        if ($filters->courseId !== null) {
            $where[]            = 'm.course_id = :courseId';
            $params['courseId'] = $filters->courseId;
        }

        if ($filters->uploadedBy !== null) {
            $where[]              = 'm.uploaded_by = :uploadedBy';
            $params['uploadedBy'] = $filters->uploadedBy;
        }

        if ($filters->search !== null) {
            // The filename too: somebody looking for a file they uploaded last
            // year remembers what it was called, not what it was titled.
            $where[]          = '(m.title ILIKE :search OR m.description ILIKE :search
                                  OR m.original_filename ILIKE :search)';
            $params['search'] = '%' . $filters->search . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @return array<string, mixed>
     */
    private function writableColumns(MediaItem $item): array
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
            'duration'         => $item->durationSeconds,
            'width'            => $item->width,
            'height'           => $item->height,
            'pageCount'        => $item->pageCount,
            'scormVersion'     => $item->scormVersion,
            'launchUrl'        => $item->launchUrl,
            'extractedPath'    => $item->extractedPath,
            'metadata'         => $this->encode($item->metadata),
            'sha256'           => $item->sha256,
            'versionLabel'     => $item->versionLabel,
            'versionNotes'     => $item->versionNotes,
            'courseId'         => $item->courseId,
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function encode(array $metadata): string
    {
        // An empty PHP array encodes as `[]`, which the column's
        // `jsonb_typeof(metadata) = 'object'` check rejects.
        return (string) json_encode($metadata === [] ? new \stdClass() : $metadata);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): MediaItem
    {
        $version = (int) ($row['version_number'] ?? 1);

        return new MediaItem(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            kind: MediaKind::from((string) $row['kind']),
            title: (string) $row['title'],
            originalFilename: (string) $row['original_filename'],
            storageKey: (string) $row['storage_key'],
            fileSize: (int) ($row['file_size'] ?? 0),
            mimeType: (string) $row['mime_type'],
            extension: (string) $row['extension'],
            description: $this->text($row['description'] ?? null),
            uploadedBy: $this->text($row['uploaded_by'] ?? null),
            durationSeconds: ($row['duration_seconds'] ?? null) === null
                ? null
                : (float) $row['duration_seconds'],
            width: $this->int($row['width'] ?? null),
            height: $this->int($row['height'] ?? null),
            pageCount: $this->int($row['page_count'] ?? null),
            scormVersion: $this->text($row['scorm_version'] ?? null),
            launchUrl: $this->text($row['launch_url'] ?? null),
            extractedPath: $this->text($row['extracted_path'] ?? null),
            metadata: $this->json($row['metadata'] ?? null),
            sha256: $this->text($row['sha256'] ?? null),
            rootMediaId: $this->text($row['root_media_id'] ?? null),
            versionNumber: $version,
            versionLabel: $this->text($row['version_label'] ?? null),
            versionNotes: $this->text($row['version_notes'] ?? null),
            courseId: $this->text($row['course_id'] ?? null),
            createdAt: $this->date($row['created_at'] ?? null),
            updatedAt: $this->date($row['updated_at'] ?? null),
            isLatest: $version === (int) ($row['latest_version'] ?? $version),
            versionCount: max(1, (int) ($row['version_count'] ?? 1)),
            uploaderName: $this->text($row['uploader_name'] ?? null),
            courseTitle: $this->text($row['course_title'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function json(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function int(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
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
