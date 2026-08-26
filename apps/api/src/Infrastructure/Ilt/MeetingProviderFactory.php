<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Ilt;

use Central\Api\Domain\Ilt\Exception\MeetingProviderError;
use Central\Api\Domain\Ilt\MeetingProvider;
use Central\Api\Domain\Ilt\MeetingProviderId;
use Central\Api\Domain\Ilt\MeetingProviderResolver;
use Central\Api\Domain\Ilt\VideoIntegration;
use Central\Api\Domain\Ilt\VideoIntegrationRepository;
use Central\Api\Domain\Ilt\VideoOAuthFlow;
use Central\Api\Support\Clock;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds a conferencing adapter for a given tenant and platform.
 *
 * Replaces the legacy `VideoProviderFactory` + `VideoIntegrationManager` pair.
 * Application code asks for a provider by tenant and never names a concrete
 * adapter class, so "which platform?" stays a per-tenant runtime choice with no
 * branching anywhere else in the codebase.
 */
final class MeetingProviderFactory implements MeetingProviderResolver
{
    /**
     * @param array<string, mixed> $appConfig the `ilt` section of settings.php —
     *                                        our own OAuth app credentials per platform
     */
    public function __construct(
        private readonly array $appConfig,
        private readonly VideoIntegrationRepository $integrations,
        private readonly ClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly Clock $clock,
    ) {
    }

    /**
     * The provider for a tenant's connection to a specific platform.
     *
     * @throws MeetingProviderError when the tenant has not connected it
     */
    public function forTenant(string $tenantId, MeetingProviderId $platform): MeetingProvider
    {
        $integration = $this->integrations->find($tenantId, $platform);

        if ($integration === null || !$integration->isUsable()) {
            throw MeetingProviderError::notConnected($platform);
        }

        return $this->build($integration);
    }

    /**
     * The provider a session should use when it names no platform.
     *
     * @throws MeetingProviderError when the tenant has connected nothing
     */
    public function defaultForTenant(string $tenantId): MeetingProvider
    {
        $integration = $this->integrations->findDefault($tenantId);

        if ($integration === null || !$integration->isUsable()) {
            throw new MeetingProviderError(
                MeetingProviderId::Zoom,
                'This organisation has no video conferencing platform connected. '
                . 'Connect one in Settings before scheduling virtual sessions.',
            );
        }

        return $this->build($integration);
    }

    public function build(VideoIntegration $integration): MeetingProvider&VideoOAuthFlow
    {
        /** @var array<string, mixed> $platformConfig */
        $platformConfig = (array) ($this->appConfig[$integration->platform->value] ?? []);

        return match ($integration->platform) {
            MeetingProviderId::Zoom => new ZoomMeetingProvider(
                $integration,
                $platformConfig,
                $this->integrations,
                $this->http,
                $this->logger,
                $this->clock,
            ),
            MeetingProviderId::Teams => new TeamsMeetingProvider(
                $integration,
                $platformConfig,
                $this->integrations,
                $this->http,
                $this->logger,
                $this->clock,
            ),
            MeetingProviderId::GoogleMeet => new GoogleMeetProvider(
                $integration,
                $platformConfig,
                $this->integrations,
                $this->http,
                $this->logger,
                $this->clock,
            ),
        };
    }

    /**
     * An adapter with no tokens, used to start the OAuth handshake before a
     * connection exists.
     */
    public function connector(string $tenantId, MeetingProviderId $platform): VideoOAuthFlow
    {
        $existing = $this->integrations->find($tenantId, $platform);

        return $this->build($existing ?? new VideoIntegration(
            id: '',
            tenantId: $tenantId,
            platform: $platform,
            enabled: true,
            isDefault: false,
            accessToken: null,
            refreshToken: null,
            tokenExpiresAt: null,
        ));
    }

    /**
     * Whether this deployment has OAuth app credentials for a platform at all.
     *
     * A platform with no client id cannot be offered to any tenant, however
     * much they might want it.
     */
    public function isPlatformAvailable(MeetingProviderId $platform): bool
    {
        $config = (array) ($this->appConfig[$platform->value] ?? []);

        return ($config['enabled'] ?? false)
            && ($config['client_id'] ?? '') !== ''
            && ($config['client_secret'] ?? '') !== '';
    }

    /**
     * What the tenant-settings screen and the session form need to render:
     * every platform this deployment supports, and whether this tenant has
     * connected it.
     *
     * @return list<array<string, mixed>>
     */
    public function describeForTenant(string $tenantId): array
    {
        $connected = [];

        foreach ($this->integrations->findAllForTenant($tenantId) as $integration) {
            $connected[$integration->platform->value] = $integration;
        }

        $described = [];

        foreach (MeetingProviderId::cases() as $platform) {
            if (!$this->isPlatformAvailable($platform)) {
                continue;
            }

            $integration = $connected[$platform->value] ?? null;

            $described[] = [
                'id'                       => $platform->value,
                'label'                    => $platform->label(),
                'connected'                => $integration?->isUsable() ?? false,
                'isDefault'                => $integration !== null && $integration->isDefault,
                'expiresAt'                => $integration?->tokenExpiresAt?->format(\DateTimeInterface::ATOM),
                'supportsCloudRecording'   => $platform->supportsCloudRecording(),
                'supportsWaitingRoom'      => $platform->supportsWaitingRoom(),
                'supportsAttendanceReport' => $platform->supportsAttendanceReport(),
            ];
        }

        return $described;
    }
}
