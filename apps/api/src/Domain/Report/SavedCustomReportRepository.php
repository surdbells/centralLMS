<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

/**
 * Storage for the custom reports people save from the builder.
 */
interface SavedCustomReportRepository
{
    /**
     * One person's saved custom reports, newest first.
     *
     * @return list<SavedCustomReport>
     */
    public function forUser(string $tenantId, string $userId): array;

    public function find(string $tenantId, string $id): ?SavedCustomReport;

    public function save(SavedCustomReport $report): SavedCustomReport;

    public function delete(string $tenantId, string $id): bool;

    /**
     * Whether this person already keeps one by that name (case-insensitive).
     */
    public function hasName(string $tenantId, string $userId, string $name, ?string $exceptId = null): bool;
}
