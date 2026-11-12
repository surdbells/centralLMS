<?php

declare(strict_types=1);

namespace Central\Api\Application\Notification;

use Central\Api\Domain\Notification\EmailBranding;
use Central\Api\Domain\Settings\SettingKey;
use Central\Api\Domain\Settings\SettingsRepository;
use Central\Api\Domain\Tenant\TenantRepository;
use Psr\Log\LoggerInterface;

/**
 * Reads one organisation's email branding from its settings.
 *
 * Logo, in order: the Email logo setting (declared for exactly this, and until
 * now read by nothing), then the main Logo setting, then the logo on the
 * organisation's profile. A relative `/v1/public/branding/...` address — what
 * an uploaded logo is stored as — is made absolute against the public API
 * address. Anything an inbox could not load is dropped by
 * {@see EmailBranding}, and the organisation's name is shown instead.
 *
 * Memoised per tenant for the life of the object: the worker sends fifty
 * emails for one organisation in a pass and should not read its settings
 * fifty times. Never throws — an email in the platform's colours is better
 * than no email.
 */
final class EmailBrandingResolver
{
    /** @var array<string, EmailBranding> */
    private array $resolved = [];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly TenantRepository $tenants,
        private readonly LoggerInterface $logger,
        /** The learner web app, e.g. https://learn.example.com — where links go. */
        private readonly string $webUrl = '',
        /** This API's public address, for logos uploaded through it. */
        private readonly string $apiUrl = '',
        private readonly string $platformName = 'Central LMS',
    ) {
    }

    public function forTenant(string $tenantId): EmailBranding
    {
        return $this->resolved[$tenantId] ??= $this->resolve($tenantId);
    }

    public function webUrl(): string
    {
        return rtrim($this->webUrl, '/');
    }

    public function apiUrl(): string
    {
        return rtrim($this->apiUrl, '/');
    }

    /**
     * What the settings screen shows above the templates: the branding every
     * email will carry, and whether a logo was set that inboxes cannot load.
     *
     * @return array{organisation: string, logoUrl: string|null, primaryColor: string,
     *               textOnPrimary: string, siteUrl: string, logoConfigured: bool, logoIsPublic: bool}
     */
    public function summary(string $tenantId): array
    {
        $brand      = $this->forTenant($tenantId);
        $configured = false;

        try {
            $settings   = $this->settings->forTenant($tenantId);
            $configured = trim($settings->string(SettingKey::EmailHeaderLogoUrl)) !== ''
                || trim($settings->string(SettingKey::LogoUrl)) !== ''
                || trim((string) $this->tenants->find($tenantId)?->logoUrl) !== '';
        } catch (\Throwable) {
            // The summary is advisory; the branding above is what counts.
        }

        return [
            'organisation'   => $brand->organisation,
            'logoUrl'        => $brand->logoUrl,
            'primaryColor'   => $brand->primary,
            'textOnPrimary'  => $brand->onPrimary,
            'siteUrl'        => $brand->siteUrl,
            'logoConfigured' => $configured,
            // A logo was set, but it is not one an inbox can fetch (relative,
            // http, or a short-lived media link): emails show the name instead.
            'logoIsPublic'   => $brand->logoUrl !== null,
        ];
    }

    /** Forget what was read, after the branding has been changed. */
    public function forget(string $tenantId): void
    {
        unset($this->resolved[$tenantId]);
    }

    private function resolve(string $tenantId): EmailBranding
    {
        $name = '';

        try {
            $tenant = $this->tenants->find($tenantId);
            $name   = $tenant->name ?? '';
            $profileLogo = $tenant?->logoUrl;

            $settings = $this->settings->forTenant($tenantId);

            $logo = null;

            foreach ([
                $settings->string(SettingKey::EmailHeaderLogoUrl),
                $settings->string(SettingKey::LogoUrl),
                (string) $profileLogo,
            ] as $candidate) {
                $candidate = EmailBranding::publicImageUrl($this->absolute($candidate));

                if ($candidate !== null) {
                    $logo = $candidate;
                    break;
                }
            }

            return new EmailBranding(
                organisation: $name,
                primary: $settings->string(SettingKey::PrimaryColour),
                logoUrl: $logo,
                siteUrl: $this->webUrl(),
                showPoweredBy: $settings->bool(SettingKey::ShowPoweredBy),
                platformName: $this->platformName,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Could not read email branding; using the platform look', [
                'tenant' => $tenantId,
                'error'  => $e->getMessage(),
            ]);

            return EmailBranding::fallback($name, $this->webUrl());
        }
    }

    /** A relative address served by this API, made absolute. */
    private function absolute(string $url): string
    {
        $url = trim($url);

        if (str_starts_with($url, '/v1/public/') && $this->apiUrl() !== '') {
            return $this->apiUrl() . $url;
        }

        // A logo uploaded here was saved with the API address of the day.
        // Re-based on today's public address, so setting PUBLIC_API_URL later
        // (e.g. behind a proxy) repairs logos uploaded before it was set.
        if (
            $this->apiUrl() !== ''
            && preg_match('#^https?://[^/]+(/v1/public/branding/[^\s]+)$#i', $url, $m) === 1
        ) {
            return $this->apiUrl() . $m[1];
        }

        return $url;
    }
}
