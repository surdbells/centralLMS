<?php

declare(strict_types=1);

namespace Central\Api\Domain\Audit;

/**
 * Storage for the audit trail.
 *
 * There is no update and no delete-by-id, and that is deliberate: a record of
 * what happened that can be edited is not a record of what happened. The only
 * removal is {@see deleteBefore()}, for a retention policy.
 */
interface AuditRepository
{
    /**
     * Records one event.
     *
     * Must never throw into the request it is describing — see
     * DbalAuditRepository::record(). An audit write that fails should cost the
     * trail one row, not the user their action.
     */
    public function record(AuditEvent $event): void;

    /**
     * @return array{items: list<AuditEvent>, total: int}
     */
    public function paginate(string $tenantId, AuditFilters $filters, int $page, int $perPage): array;

    /**
     * The actions that actually appear in this tenant's trail, for a filter
     * that offers only what is there.
     *
     * @return list<string>
     */
    public function actionsUsed(string $tenantId): array;

    /**
     * Retention. Returns the number of rows removed.
     */
    public function deleteBefore(\DateTimeImmutable $cutoff): int;
}
