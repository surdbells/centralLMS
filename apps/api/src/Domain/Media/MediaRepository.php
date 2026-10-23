<?php

declare(strict_types=1);

namespace Central\Api\Domain\Media;

/**
 * Storage for the media library.
 *
 * Every method takes a tenant id. `api/media.php` did not: `getMediaById()`
 * selected on the id alone, so a guessed id served another tenant's file, and
 * served it as a redirect to a signed URL that then worked for anybody the
 * link was passed on to.
 */
interface MediaRepository
{
    /**
     * @return array{items: list<MediaItem>, total: int}
     */
    public function paginate(string $tenantId, MediaFilters $filters, int $page, int $perPage): array;

    public function find(string $tenantId, string $id): ?MediaItem;

    /**
     * Every version of a file, newest first.
     *
     * @return list<MediaItem>
     */
    public function versionsOf(string $tenantId, string $rootMediaId): array;

    public function create(MediaItem $item): MediaItem;

    /**
     * Stores a replacement for an existing file.
     *
     * The new row joins that file's chain and becomes its latest version. The
     * version number is worked out inside the transaction, not by the caller:
     * two people uploading a replacement at the same moment would both compute
     * three, and one of the two uploads would be lost.
     *
     * Null when `$replacesId` is not a file in this tenant.
     */
    public function createVersion(MediaItem $item, string $replacesId): ?MediaItem;

    /**
     * Editable fields only: title, description, course, version label.
     *
     * The file itself is never edited in place. Replacing what a storage key
     * points at leaves everybody who has already been shown the old file with
     * a cached copy that no longer matches its title.
     */
    public function update(MediaItem $item): ?MediaItem;

    /**
     * Records where a SCORM package was unpacked and how it launches.
     *
     * Written by the extraction worker once a package has been unzipped: the
     * folder its files live under in storage, the entry file named by the
     * manifest, and the SCORM version the manifest declares. Until this is set,
     * `extracted_path` is null and the package is not yet playable.
     */
    public function markExtracted(
        string $tenantId,
        string $id,
        string $extractedPath,
        string $launchUrl,
        string $scormVersion,
    ): void;

    /**
     * Records where a SCORM extraction has got to — 'processing' while the
     * worker runs, 'failed' if it could not finish. ('ready' is written by
     * {@see markExtracted}, which also sets the extracted path.) Lets the upload
     * screen show progress rather than guess.
     */
    public function setScormStatus(string $tenantId, string $id, string $status): void;

    /**
     * SCORM packages that have been uploaded but not yet unpacked.
     *
     * For the backfill command, which re-dispatches extraction for packages
     * that predate the worker (or whose extraction failed and left the row
     * with no `extracted_path`). Across all tenants.
     *
     * @return list<MediaItem>
     */
    public function pendingScormExtractions(): array;

    /**
     * Soft delete. Returns the storage keys that are now unreferenced.
     *
     * @param  bool $allVersions whether to remove the whole chain
     * @return list<string>
     */
    public function softDelete(string $tenantId, string $id, bool $allVersions = false): array;

    /**
     * An existing file with the same content, if one is already held.
     *
     * Lets an upload of something already stored be offered as a link rather
     * than kept as a second copy.
     */
    public function findByChecksum(string $tenantId, string $sha256): ?MediaItem;

    /**
     * Totals for the library header.
     *
     * @return array{total: int, byKind: array<string, int>, bytesUsed: int}
     */
    public function statistics(string $tenantId): array;
}
