<?php

declare(strict_types=1);

namespace Central\Api\Domain\Ilt;

/**
 * One tenant's connection to one conferencing platform.
 *
 * Ported from the legacy `tenant_video_integrations` table. The important
 * property — and the thing the original env-based design got wrong — is that
 * credentials belong to a *tenant*, not to the deployment: each customer
 * connects their own Zoom or Microsoft account through an OAuth consent
 * screen, and we hold their refresh token.
 *
 * `clientId`/`clientSecret` are optional per row. When absent, the platform's
 * app-level credentials from configuration are used, which is the normal SaaS
 * arrangement (one OAuth app of ours, many tenants consenting to it). A tenant
 * that insists on its own OAuth app can still set them here.
 */
final readonly class VideoIntegration
{
    /**
     * @param array<string, mixed> $settings free-form platform options (the `settings` jsonb column)
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public MeetingProviderId $platform,
        public bool $enabled,
        public bool $isDefault,
        public ?string $accessToken,
        public ?string $refreshToken,
        public ?\DateTimeImmutable $tokenExpiresAt,
        public ?string $clientId = null,
        public ?string $clientSecret = null,
        public array $settings = [],
    ) {
    }

    /**
     * True when the access token is missing, or close enough to expiry that a
     * call started now could outlive it.
     */
    public function needsRefresh(\DateTimeImmutable $now, int $skewSeconds = 120): bool
    {
        if ($this->accessToken === null || $this->accessToken === '') {
            return true;
        }

        if ($this->tokenExpiresAt === null) {
            // Unknown expiry — refresh rather than gamble on a 401 mid-request.
            return true;
        }

        return $this->tokenExpiresAt->getTimestamp() - $skewSeconds <= $now->getTimestamp();
    }

    /**
     * Usable at all: switched on and holding a refresh token we can trade for
     * access. Without a refresh token the tenant must reconnect.
     */
    public function isUsable(): bool
    {
        return $this->enabled && $this->refreshToken !== null && $this->refreshToken !== '';
    }

    public function withTokens(
        string $accessToken,
        ?string $refreshToken,
        \DateTimeImmutable $expiresAt,
    ): self {
        return new self(
            id: $this->id,
            tenantId: $this->tenantId,
            platform: $this->platform,
            enabled: $this->enabled,
            isDefault: $this->isDefault,
            accessToken: $accessToken,
            // Zoom rotates the refresh token on every use and invalidates the
            // old one; Microsoft and Google usually return none, meaning keep
            // the existing. Both cases are handled by this coalesce.
            refreshToken: $refreshToken ?? $this->refreshToken,
            tokenExpiresAt: $expiresAt,
            clientId: $this->clientId,
            clientSecret: $this->clientSecret,
            settings: $this->settings,
        );
    }

    /**
     * Never let credentials reach a log line or an API response.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'tenantId'  => $this->tenantId,
            'platform'  => $this->platform->value,
            'label'     => $this->platform->label(),
            'enabled'   => $this->enabled,
            'isDefault' => $this->isDefault,
            'connected' => $this->isUsable(),
            'expiresAt' => $this->tokenExpiresAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
