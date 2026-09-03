<?php

declare(strict_types=1);

namespace Central\Api\Domain\Saas;

/**
 * The operator's view across every organisation.
 *
 * Distinct from {@see \Central\Api\Domain\Tenant\TenantRepository}, which is an
 * organisation acting on itself: this reaches across tenants and is only ever
 * called for a super admin. The rows are plain arrays — a management list, not
 * domain objects an organisation edits.
 */
interface PlatformRepository
{
    /**
     * A page of organisations with their plan and headline usage.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function tenants(?string $search, ?string $status, int $page, int $perPage): array;

    /**
     * One organisation in full: profile, plan, limits and current usage.
     *
     * @return array<string, mixed>|null
     */
    public function findTenant(string $id): ?array;

    /**
     * Platform-wide figures for the operator dashboard.
     *
     * @return array<string, mixed>
     */
    public function metrics(): array;

    /** Moves an organisation between trial / active / suspended / cancelled. */
    public function updateStatus(string $tenantId, string $status): bool;

    /** Sets a bespoke allowance; null on a limit means unlimited. */
    public function updateLimits(string $tenantId, ?int $usersLimit, ?int $storageLimitMb): bool;

    /**
     * Puts an organisation on a plan (or none), copying the plan's allowances
     * onto the tenant as its new limits. A bespoke allowance set afterwards
     * still overrides.
     */
    public function assignPlan(string $tenantId, ?string $planId): bool;
}
