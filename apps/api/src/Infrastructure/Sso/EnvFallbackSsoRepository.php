<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Sso;

use Central\Api\Domain\Sso\SsoConnection;
use Central\Api\Domain\Sso\SsoProvider;
use Central\Api\Domain\Sso\SsoRepository;

/**
 * Lets the deployment's default tenant configure SSO from the environment
 * instead of the database.
 *
 * A single-tenant deployment (DEFAULT_TENANT_ID set) usually has no
 * administrator in a multi-tenant console turning SSO on; its one
 * organisation's Entra/Google credentials are deployment configuration, like
 * the database password. This decorator reads those from the `sso` settings
 * section (populated from env vars) and presents them as the default tenant's
 * connection, so sign-in works without an admin screen.
 *
 * It is a fallback, not an override: a connection stored in the database for a
 * tenant always wins, so a stored connection is never shadowed by a stale env
 * var and a future admin screen keeps working. Every other tenant, and every
 * write, passes straight through to the wrapped repository untouched.
 */
final class EnvFallbackSsoRepository implements SsoRepository
{
    /**
     * @param array<string, array<string, mixed>> $config the `sso` settings
     *        section, keyed by provider value (`microsoft`, `google`)
     */
    public function __construct(
        private readonly SsoRepository $inner,
        private readonly string $defaultTenantId,
        private readonly array $config,
    ) {
    }

    public function forTenant(string $tenantId): array
    {
        $connections = $this->inner->forTenant($tenantId);

        if (!$this->isDefaultTenant($tenantId)) {
            return $connections;
        }

        $present = [];

        foreach ($connections as $connection) {
            $present[$connection->provider->value] = true;
        }

        // A provider already set up in the database is left as it is; the env
        // only fills a gap it does not cover.
        foreach (SsoProvider::cases() as $provider) {
            if (isset($present[$provider->value])) {
                continue;
            }

            $env = $this->envConnection($provider);

            if ($env !== null) {
                $connections[] = $env;
            }
        }

        return $connections;
    }

    public function find(string $tenantId, SsoProvider $provider): ?SsoConnection
    {
        $connection = $this->inner->find($tenantId, $provider);

        if ($connection !== null) {
            return $connection;
        }

        return $this->isDefaultTenant($tenantId) ? $this->envConnection($provider) : null;
    }

    public function save(SsoConnection $connection): SsoConnection
    {
        return $this->inner->save($connection);
    }

    public function delete(string $tenantId, SsoProvider $provider): bool
    {
        return $this->inner->delete($tenantId, $provider);
    }

    public function rememberState(
        string $state,
        string $tenantId,
        SsoProvider $provider,
        string $nonce,
        ?string $redirectTo,
        \DateTimeImmutable $expiresAt,
    ): void {
        $this->inner->rememberState($state, $tenantId, $provider, $nonce, $redirectTo, $expiresAt);
    }

    public function claimState(string $state, \DateTimeImmutable $now): ?array
    {
        return $this->inner->claimState($state, $now);
    }

    public function purgeStates(\DateTimeImmutable $before): int
    {
        return $this->inner->purgeStates($before);
    }

    public function findUserBySubject(SsoProvider $provider, string $subject): ?string
    {
        return $this->inner->findUserBySubject($provider, $subject);
    }

    public function linkUser(string $userId, SsoProvider $provider, string $subject): void
    {
        $this->inner->linkUser($userId, $provider, $subject);
    }

    private function isDefaultTenant(string $tenantId): bool
    {
        return $this->defaultTenantId !== '' && $tenantId === $this->defaultTenantId;
    }

    /**
     * The default tenant's connection for a provider as configured in the
     * environment, or null when the environment does not configure it.
     *
     * "Configured" means both a client id and a secret: either on its own is a
     * half-set connection that could only ever fail to sign anyone in, so it is
     * treated as absent rather than offered as a button that always breaks.
     */
    private function envConnection(SsoProvider $provider): ?SsoConnection
    {
        $cfg = $this->config[$provider->value] ?? null;

        if (!is_array($cfg)) {
            return null;
        }

        $clientId = trim((string) ($cfg['client_id'] ?? ''));
        $secret   = trim((string) ($cfg['client_secret'] ?? ''));

        if ($clientId === '' || $secret === '') {
            return null;
        }

        $directory = trim((string) ($cfg['directory'] ?? ''));

        return new SsoConnection(
            // A marker id, not a database id: this connection is never saved,
            // and nothing in the sign-in flow reads the id — it is keyed on
            // (tenant, provider) everywhere that matters.
            id: 'env:' . $provider->value,
            tenantId: $this->defaultTenantId,
            provider: $provider,
            enabled: (bool) ($cfg['enabled'] ?? true),
            clientId: $clientId,
            clientSecret: $secret,
            directory: $directory === '' ? null : $directory,
            domains: SsoConnection::parseDomains((string) ($cfg['domains'] ?? '')),
            autoProvision: (bool) ($cfg['auto_provision'] ?? false),
            // Configured in the environment: a settings screen shows it as
            // read-only, and an admin changes it where it is set, not here.
            managed: true,
        );
    }
}
