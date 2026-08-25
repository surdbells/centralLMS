<?php

declare(strict_types=1);

namespace Central\Api\Domain\Ilt;

/**
 * Storage for tenant conferencing connections.
 *
 * Declared in the domain and implemented in Infrastructure so the providers
 * depend on this contract rather than on Doctrine — which is what makes them
 * testable without a database, something the legacy providers never were.
 */
interface VideoIntegrationRepository
{
    public function find(string $tenantId, MeetingProviderId $platform): ?VideoIntegration;

    /**
     * Every connection a tenant has, enabled or not.
     *
     * @return list<VideoIntegration>
     */
    public function findAllForTenant(string $tenantId): array;

    /**
     * The platform to use when a session does not name one.
     */
    public function findDefault(string $tenantId): ?VideoIntegration;

    /**
     * Persist refreshed tokens.
     *
     * Called from inside the request that triggered the refresh, so it must be
     * cheap and must not depend on the surrounding transaction: if the request
     * later fails, the new tokens still need to survive — the old ones have
     * already been invalidated by the provider.
     */
    public function saveTokens(VideoIntegration $integration): void;

    public function save(VideoIntegration $integration): void;

    public function delete(string $tenantId, MeetingProviderId $platform): void;
}
