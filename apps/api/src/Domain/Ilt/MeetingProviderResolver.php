<?php

declare(strict_types=1);

namespace Central\Api\Domain\Ilt;

/**
 * Finds the meeting provider a tenant has connected.
 *
 * A domain-side interface over the infrastructure factory, so
 * {@see SessionScheduler} — which is domain logic about when a session needs a
 * meeting — does not have to reach into `Infrastructure\Ilt` to get one.
 */
interface MeetingProviderResolver
{
    /**
     * @throws \RuntimeException when the tenant has not connected that platform
     */
    public function forTenant(string $tenantId, MeetingProviderId $platform): MeetingProvider;

    /**
     * The platform marked default for the tenant.
     *
     * @throws \RuntimeException when the tenant has connected nothing
     */
    public function defaultForTenant(string $tenantId): MeetingProvider;
}
