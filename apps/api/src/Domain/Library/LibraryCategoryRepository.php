<?php

declare(strict_types=1);

namespace Central\Api\Domain\Library;

interface LibraryCategoryRepository
{
    /**
     * Every category of the tenant, ordered by position then name, each with
     * the number of live items filed under it, plus the number filed under
     * none. With a `$visibleToUserId` both counts are of the items that user
     * can see; without one they are of every item (the manager's view).
     *
     * @return array{categories: list<LibraryCategory>, uncategorisedCount: int}
     */
    public function list(string $tenantId, ?string $visibleToUserId): array;

    /** One category, with its unscoped item count. */
    public function find(string $tenantId, string $id): ?LibraryCategory;

    /**
     * @throws \Doctrine\DBAL\Exception\UniqueConstraintViolationException on a duplicate name
     */
    public function create(LibraryCategory $category): LibraryCategory;

    /**
     * Saves name, description and position. Null when it is not in the tenant.
     *
     * @throws \Doctrine\DBAL\Exception\UniqueConstraintViolationException on a duplicate name
     */
    public function update(LibraryCategory $category): ?LibraryCategory;

    /** Deletes it; its items stay in the library, uncategorised. */
    public function delete(string $tenantId, string $id): bool;
}
