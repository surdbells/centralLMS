<?php

declare(strict_types=1);

namespace Central\Api\Domain\Settings;

/**
 * Storage for tenant setting overrides.
 *
 * Every method takes a tenant id, and no caller may choose one. The legacy's
 * `user/tenant-settings.php` read `tenant_id` from the request body and handed
 * it to `setTenant()`, checking only `is_admin($userId)` — which asks whether
 * the caller administers *something*, not whether they administer the
 * organisation they just named. That is a write: any tenant administrator could
 * repaint another organisation's branding, rename it, or import a whole
 * settings file over the top.
 */
interface SettingsRepository
{
    public function forTenant(string $tenantId): TenantSettings;

    /**
     * Applies a set of changes at once.
     *
     * One transaction: a settings form saves a whole tab, and half a saved tab
     * is worse than a rejected one.
     *
     * @param array<string, string> $values key => value, already validated
     */
    public function save(string $tenantId, array $values, ?string $actorId): TenantSettings;

    /**
     * Removes the tenant's overrides for a category, so the defaults apply.
     *
     * Deleting rather than writing the defaults back: a stored default is
     * indistinguishable from a deliberate choice, and the next time the default
     * changes the tenant keeps the old one without knowing.
     *
     * @return int how many were removed
     */
    public function resetCategory(string $tenantId, SettingCategory $category): int;

    /**
     * Who last changed each setting, and when.
     *
     * @return list<array<string, mixed>>
     */
    public function history(string $tenantId): array;
}
