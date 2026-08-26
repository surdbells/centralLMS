<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Media\MediaFilters;
use Central\Api\Domain\Media\MediaItem;
use Central\Api\Domain\Media\MediaKind;
use Central\Api\Infrastructure\Persistence\DbalMediaRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * The media library against a real PostgreSQL.
 *
 * Two things need the database rather than a fake. The first is that
 * "is this the current version" is now a query over the chain instead of a
 * stored flag, and the tests below are what say the query agrees with what the
 * flag was meant to mean. The second is that a version number is allocated
 * under a lock.
 */
#[CoversClass(DbalMediaRepository::class)]
#[CoversClass(MediaItem::class)]
final class DbalMediaRepositoryTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalMediaRepository $media;

    private string $tenantA;
    private string $tenantB;
    private string $ada;
    private string $courseId;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable — start it with: pnpm up');
        }

        $this->media = new DbalMediaRepository(self::$connection);

        $this->tenantA  = $this->createTenant('med-a');
        $this->tenantB  = $this->createTenant('med-b');
        $this->ada      = $this->createUser($this->tenantA, 'Ada');
        $this->courseId = $this->createCourse($this->tenantA);
    }

    protected function tearDown(): void
    {
        if (self::$connection === null) {
            return;
        }

        foreach ([$this->tenantA ?? null, $this->tenantB ?? null] as $tenantId) {
            if ($tenantId === null) {
                continue;
            }

            self::$connection->executeStatement('DELETE FROM media WHERE tenant_id = :id', ['id' => $tenantId]);
            self::$connection->executeStatement('DELETE FROM courses WHERE tenant_id = :id', ['id' => $tenantId]);
            self::$connection->executeStatement('DELETE FROM users WHERE tenant_id = :id', ['id' => $tenantId]);
            self::$connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $tenantId]);
        }
    }

    // --- storing ----------------------------------------------------------

    #[Test]
    public function a_first_upload_is_its_own_root(): void
    {
        $item = $this->media->create($this->video('Induction'));

        self::assertSame($item->id, $item->rootMediaId);
        self::assertSame(1, $item->versionNumber);
        self::assertTrue($item->isOriginal());
        self::assertTrue($item->isLatest);
        self::assertSame(1, $item->versionCount);
    }

    #[Test]
    public function metadata_round_trips_as_an_object(): void
    {
        $item = $this->media->create($this->video('Induction', ['codec' => 'h264', 'bitrate' => 4200]));

        self::assertSame(
            ['codec' => 'h264', 'bitrate' => 4200],
            $this->media->find($this->tenantA, (string) $item->id)?->metadata,
        );
    }

    #[Test]
    public function a_file_is_invisible_to_another_tenant(): void
    {
        // `getMediaById()` selected on the id alone, and answered with a
        // redirect to a signed storage URL.
        $item = $this->media->create($this->video('Induction'));

        self::assertNotNull($this->media->find($this->tenantA, (string) $item->id));
        self::assertNull($this->media->find($this->tenantB, (string) $item->id));
    }

    // --- versions ---------------------------------------------------------

    #[Test]
    public function a_replacement_joins_the_chain_and_becomes_the_latest(): void
    {
        $first  = $this->media->create($this->video('Induction'));
        $second = $this->media->createVersion($this->video('Induction v2'), (string) $first->id);

        self::assertNotNull($second);
        self::assertSame($first->id, $second->rootMediaId);
        self::assertSame(2, $second->versionNumber);
        self::assertTrue($second->isLatest);
        self::assertFalse($second->isOriginal());

        // And the one it replaced is no longer current, without anything
        // having been written to say so.
        self::assertFalse($this->media->find($this->tenantA, (string) $first->id)?->isLatest);
    }

    #[Test]
    public function a_version_can_be_added_to_a_version(): void
    {
        // Somebody replacing the file they are looking at is replacing the
        // file, not starting a second chain from version two.
        $first  = $this->media->create($this->video('Induction'));
        $second = $this->media->createVersion($this->video('v2'), (string) $first->id);
        $third  = $this->media->createVersion($this->video('v3'), (string) $second?->id);

        self::assertSame($first->id, $third?->rootMediaId);
        self::assertSame(3, $third?->versionNumber);
        self::assertCount(3, $this->media->versionsOf($this->tenantA, (string) $first->id));
    }

    #[Test]
    public function every_row_in_a_chain_knows_how_many_versions_there_are(): void
    {
        $first = $this->media->create($this->video('Induction'));
        $this->media->createVersion($this->video('v2'), (string) $first->id);

        self::assertSame(2, $this->media->find($this->tenantA, (string) $first->id)?->versionCount);
    }

    #[Test]
    public function versions_come_back_newest_first(): void
    {
        $first = $this->media->create($this->video('Induction'));
        $this->media->createVersion($this->video('v2'), (string) $first->id);
        $this->media->createVersion($this->video('v3'), (string) $first->id);

        self::assertSame(
            [3, 2, 1],
            array_map(
                static fn (MediaItem $m): int => $m->versionNumber,
                $this->media->versionsOf($this->tenantA, (string) $first->id),
            ),
        );
    }

    #[Test]
    public function a_replacement_for_another_tenants_file_is_refused(): void
    {
        $item = $this->media->create($this->video('Induction'));

        $intruder = new MediaItem(
            id: null,
            tenantId: $this->tenantB,
            kind: MediaKind::Video,
            title: 'Mine now',
            originalFilename: 'mine.mp4',
            storageKey: $this->tenantB . '/video/mine.mp4',
            fileSize: 10,
            mimeType: 'video/mp4',
            extension: 'mp4',
        );

        self::assertNull($this->media->createVersion($intruder, (string) $item->id));
    }

    #[Test]
    public function the_same_version_number_cannot_be_written_twice(): void
    {
        // The index is what makes the version number safe to allocate: if two
        // uploads ever do compute the same one, the second is rejected rather
        // than quietly becoming a second "latest".
        $first = $this->media->create($this->video('Induction'));

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

        self::$connection?->executeStatement(
            "INSERT INTO media (root_media_id, tenant_id, kind, title, original_filename,
                                storage_key, file_size, mime_type, extension, version_number)
             VALUES (:root, :t, 'video', 'Duplicate', 'dup.mp4', :key, 1, 'video/mp4', 'mp4', 1)",
            ['root' => $first->rootMediaId, 't' => $this->tenantA, 'key' => 'dup.mp4'],
        );
    }

    // --- listing ----------------------------------------------------------

    #[Test]
    public function the_library_shows_one_card_per_file_not_one_per_revision(): void
    {
        $first = $this->media->create($this->video('Induction'));
        $this->media->createVersion($this->video('v2'), (string) $first->id);
        $this->media->create($this->video('Fire safety'));

        $page = $this->media->paginate($this->tenantA, MediaFilters::all(), 1, 25);

        self::assertSame(2, $page['total']);
        self::assertCount(2, $page['items']);
    }

    #[Test]
    public function the_old_versions_can_be_asked_for(): void
    {
        $first = $this->media->create($this->video('Induction'));
        $this->media->createVersion($this->video('v2'), (string) $first->id);

        $page = $this->media->paginate(
            $this->tenantA,
            MediaFilters::fromQuery(['includeOldVersions' => 'true']),
            1,
            25,
        );

        self::assertSame(2, $page['total']);
    }

    #[Test]
    public function the_search_covers_the_filename_as_well_as_the_title(): void
    {
        $this->media->create(new MediaItem(
            id: null,
            tenantId: $this->tenantA,
            kind: MediaKind::Pdf,
            title: 'Leave policy',
            originalFilename: 'HR-2019-annual-leave-FINAL.pdf',
            storageKey: $this->tenantA . '/pdf/' . Uuid::uuid4()->toString() . '.pdf',
            fileSize: 1000,
            mimeType: 'application/pdf',
            extension: 'pdf',
        ));

        $found = $this->media->paginate(
            $this->tenantA,
            MediaFilters::fromQuery(['search' => 'HR-2019']),
            1,
            25,
        );

        self::assertSame(1, $found['total']);
    }

    #[Test]
    public function a_kind_filter_narrows_the_library(): void
    {
        $this->media->create($this->video('Induction'));
        $this->media->create($this->pdf('Handbook'));

        $videos = $this->media->paginate(
            $this->tenantA,
            MediaFilters::fromQuery(['kind' => 'video']),
            1,
            25,
        );

        self::assertSame(1, $videos['total']);
        self::assertSame(MediaKind::Video, $videos['items'][0]->kind);
    }

    #[Test]
    public function another_tenants_files_are_not_in_the_library(): void
    {
        $this->media->create($this->video('Induction'));

        self::assertSame(0, $this->media->paginate($this->tenantB, MediaFilters::all(), 1, 25)['total']);
    }

    #[Test]
    public function a_listing_names_the_uploader_and_the_course(): void
    {
        $this->media->create($this->video('Induction'));

        $item = $this->media->paginate($this->tenantA, MediaFilters::all(), 1, 25)['items'][0];

        self::assertSame('Ada Tester', $item->uploaderName);
        self::assertSame('Fire Safety', $item->courseTitle);
    }

    // --- editing and deleting ---------------------------------------------

    #[Test]
    public function editing_changes_the_details_and_never_the_file(): void
    {
        $item = $this->media->create($this->video('Induction'));

        $updated = $this->media->update(new MediaItem(
            id: $item->id,
            tenantId: $item->tenantId,
            kind: $item->kind,
            title: 'Induction 2026',
            originalFilename: $item->originalFilename,
            storageKey: 'somewhere/else.mp4',
            fileSize: 999,
            mimeType: $item->mimeType,
            extension: $item->extension,
            description: 'Now with the new office.',
        ));

        self::assertNotNull($updated);
        self::assertSame('Induction 2026', $updated->title);
        self::assertSame('Now with the new office.', $updated->description);
        // The bytes are where they were.
        self::assertSame($item->storageKey, $updated->storageKey);
        self::assertSame($item->fileSize, $updated->fileSize);
    }

    #[Test]
    public function another_tenant_cannot_edit_a_file(): void
    {
        $item = $this->media->create($this->video('Induction'));

        $updated = $this->media->update(new MediaItem(
            id: $item->id,
            tenantId: $this->tenantB,
            kind: $item->kind,
            title: 'Mine now',
            originalFilename: $item->originalFilename,
            storageKey: $item->storageKey,
            fileSize: $item->fileSize,
            mimeType: $item->mimeType,
            extension: $item->extension,
        ));

        self::assertNull($updated);
        self::assertSame('Induction', $this->media->find($this->tenantA, (string) $item->id)?->title);
    }

    #[Test]
    public function deleting_one_version_reports_its_bytes_as_removable(): void
    {
        $item = $this->media->create($this->video('Induction'));

        self::assertSame([$item->storageKey], $this->media->softDelete($this->tenantA, (string) $item->id));
        self::assertNull($this->media->find($this->tenantA, (string) $item->id));
    }

    #[Test]
    public function deleting_a_version_leaves_the_rest_of_the_chain(): void
    {
        $first  = $this->media->create($this->video('Induction'));
        $second = $this->media->createVersion($this->video('v2'), (string) $first->id);

        $this->media->softDelete($this->tenantA, (string) $second?->id);

        // And the one before it is current again, because current is a
        // question about the chain rather than a flag somebody has to unset.
        self::assertTrue($this->media->find($this->tenantA, (string) $first->id)?->isLatest);
    }

    #[Test]
    public function deleting_the_whole_chain_takes_every_version(): void
    {
        $first  = $this->media->create($this->video('Induction'));
        $second = $this->media->createVersion($this->video('v2'), (string) $first->id);

        $removed = $this->media->softDelete($this->tenantA, (string) $second?->id, allVersions: true);

        self::assertCount(2, $removed);
        self::assertSame([], $this->media->versionsOf($this->tenantA, (string) $first->id));
    }

    #[Test]
    public function bytes_shared_with_a_surviving_file_are_not_reported_as_removable(): void
    {
        // Two rows can point at one stored file when an upload is matched to
        // something already held. Deleting one must not delete the bytes the
        // other is still serving.
        $key   = $this->tenantA . '/video/shared.mp4';
        $first = $this->media->create($this->video('Induction', [], $key));
        $this->media->create($this->video('Induction (copy)', [], $key));

        self::assertSame([], $this->media->softDelete($this->tenantA, (string) $first->id));
    }

    #[Test]
    public function deleting_someone_elses_file_removes_nothing(): void
    {
        $item = $this->media->create($this->video('Induction'));

        self::assertSame([], $this->media->softDelete($this->tenantB, (string) $item->id));
        self::assertNotNull($this->media->find($this->tenantA, (string) $item->id));
    }

    // --- checksums and totals ---------------------------------------------

    #[Test]
    public function an_identical_file_can_be_found_before_a_second_copy_is_kept(): void
    {
        $item = $this->media->create($this->video('Induction'));

        self::assertSame(
            $item->id,
            $this->media->findByChecksum($this->tenantA, (string) $item->sha256)?->id,
        );
        // Per tenant: one organisation must not learn what another holds by
        // uploading a file and seeing it recognised.
        self::assertNull($this->media->findByChecksum($this->tenantB, (string) $item->sha256));
    }

    #[Test]
    public function the_totals_count_every_version_towards_storage(): void
    {
        $first = $this->media->create($this->video('Induction'));
        $this->media->createVersion($this->video('v2'), (string) $first->id);
        $this->media->create($this->pdf('Handbook'));

        $statistics = $this->media->statistics($this->tenantA);

        // Three rows, because all three occupy the bucket — the library shows
        // two cards, and the storage bill is for three files.
        self::assertSame(3, $statistics['total']);
        self::assertSame(2, $statistics['byKind']['video']);
        self::assertSame(1, $statistics['byKind']['pdf']);
        self::assertSame(0, $statistics['byKind']['scorm']);
        self::assertSame(3 * 1000, $statistics['bytesUsed']);
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @param array<string, mixed> $metadata
     */
    private function video(string $title, array $metadata = [], ?string $storageKey = null): MediaItem
    {
        return new MediaItem(
            id: null,
            tenantId: $this->tenantA,
            kind: MediaKind::Video,
            title: $title,
            originalFilename: 'induction.mp4',
            storageKey: $storageKey ?? ($this->tenantA . '/video/' . Uuid::uuid4()->toString() . '.mp4'),
            fileSize: 1000,
            mimeType: 'video/mp4',
            extension: 'mp4',
            uploadedBy: $this->ada,
            durationSeconds: 91.5,
            metadata: $metadata,
            sha256: hash('sha256', $title),
            courseId: $this->courseId,
        );
    }

    private function pdf(string $title): MediaItem
    {
        return new MediaItem(
            id: null,
            tenantId: $this->tenantA,
            kind: MediaKind::Pdf,
            title: $title,
            originalFilename: 'handbook.pdf',
            storageKey: $this->tenantA . '/pdf/' . Uuid::uuid4()->toString() . '.pdf',
            fileSize: 1000,
            mimeType: 'application/pdf',
            extension: 'pdf',
            uploadedBy: $this->ada,
            pageCount: 12,
            sha256: hash('sha256', $title),
        );
    }

    private function createTenant(string $prefix): string
    {
        $id = Uuid::uuid4()->toString();

        self::$connection?->executeStatement(
            "INSERT INTO tenants (id, name, code, status) VALUES (:id, :name, :code, 'active')",
            ['id' => $id, 'name' => 'Test ' . $prefix, 'code' => $prefix . '-' . substr($id, 0, 8)],
        );

        return $id;
    }

    private function createUser(string $tenantId, string $first): string
    {
        $id = Uuid::uuid4()->toString();

        self::$connection?->executeStatement(
            "INSERT INTO users (id, tenant_id, email, password_hash, first_name, last_name, status)
             VALUES (:id, :t, :e, 'x', :f, 'Tester', 'active')",
            [
                'id' => $id,
                't'  => $tenantId,
                'e'  => strtolower($first) . '-' . substr($id, 0, 8) . '@med.test',
                'f'  => $first,
            ],
        );

        return $id;
    }

    private function createCourse(string $tenantId, string $title = 'Fire Safety'): string
    {
        $id = Uuid::uuid4()->toString();

        self::$connection?->executeStatement(
            "INSERT INTO courses (id, tenant_id, title, status) VALUES (:id, :t, :ti, 'published')",
            ['id' => $id, 't' => $tenantId, 'ti' => $title],
        );

        return $id;
    }

    private static function connect(): ?Connection
    {
        try {
            $connection = DriverManager::getConnection([
                'driver'   => 'pdo_pgsql',
                'host'     => $_ENV['DB_HOST'] ?? '127.0.0.1',
                'port'     => (int) ($_ENV['DB_PORT'] ?? 5442),
                'dbname'   => $_ENV['DB_NAME'] ?? 'central_lms',
                'user'     => $_ENV['DB_USER'] ?? 'central_lms',
                'password' => $_ENV['DB_PASSWORD'] ?? 'central_lms',
            ]);

            $connection->executeQuery('SELECT 1');

            return $connection;
        } catch (\Throwable) {
            return null;
        }
    }
}
