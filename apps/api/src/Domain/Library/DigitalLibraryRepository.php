<?php

declare(strict_types=1);

namespace Central\Api\Domain\Library;

use Central\Api\Domain\Audience\AudienceTarget;

interface DigitalLibraryRepository
{
    public function create(DigitalLibraryItem $item): DigitalLibraryItem;

    public function find(string $tenantId, string $id): ?DigitalLibraryItem;

    /**
     * Every item, for the people who manage the library.
     *
     * @return array{items: list<DigitalLibraryItem>, total: int}
     */
    public function listAll(string $tenantId, ?string $search, int $page, int $perPage): array;

    /**
     * Only the items whose audience includes this user — the library as a
     * learner sees it. Resolved against the user now, not against a snapshot
     * taken when each item was shared.
     *
     * @return array{items: list<DigitalLibraryItem>, total: int}
     */
    public function listVisibleTo(string $tenantId, string $userId, ?string $search, int $page, int $perPage): array;

    /**
     * Whether this user is in the item's audience — the check the streaming
     * endpoint makes before serving a byte, so a manager's ticket for a file
     * cannot be replayed by, or handed to, someone it was never shared with.
     */
    public function isVisibleTo(string $tenantId, string $id, string $userId): bool;

    /**
     * Updates the parts an administrator edits: the title, the description and
     * the audience. The file itself is never changed — a new file is a new item.
     *
     * @param array<string, list<string>> $targetCriteria
     */
    public function updateDetails(
        string $tenantId,
        string $id,
        string $title,
        ?string $description,
        AudienceTarget $targetType,
        array $targetCriteria,
    ): ?DigitalLibraryItem;

    public function softDelete(string $tenantId, string $id): bool;

    /**
     * Records the outcome of a document conversion — the worker's write-back.
     * On success the PDF's key and its page count; on failure neither.
     */
    public function setConversion(
        string $tenantId,
        string $id,
        ConversionStatus $status,
        ?string $convertedPdfKey = null,
        ?int $pageCount = null,
    ): ?DigitalLibraryItem;

    public function findByChecksum(string $tenantId, string $sha256): ?DigitalLibraryItem;
}
