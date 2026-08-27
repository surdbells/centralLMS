<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Sso\SsoConnection;
use Central\Api\Domain\Sso\SsoProvider;
use Central\Api\Domain\Sso\SsoRepository;
use Central\Api\Support\Cipher;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

final class DbalSsoRepository implements SsoRepository
{
    private const COLUMNS = 'id, tenant_id, provider, enabled, client_id, client_secret,
                             directory, domains, auto_provision';

    public function __construct(
        private readonly Connection $connection,
        private readonly Cipher $cipher,
    ) {
    }

    public function forTenant(string $tenantId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT %s FROM sso_connections WHERE tenant_id = :tenant ORDER BY provider', self::COLUMNS),
            ['tenant' => $tenantId],
        );

        return array_map(fn (array $row): SsoConnection => $this->hydrate($row), $rows);
    }

    public function find(string $tenantId, SsoProvider $provider): ?SsoConnection
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM sso_connections WHERE tenant_id = :tenant AND provider = :provider',
                self::COLUMNS,
            ),
            ['tenant' => $tenantId, 'provider' => $provider->value],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function save(SsoConnection $connection): SsoConnection
    {
        $id = $connection->id === '' ? Uuid::uuid4()->toString() : $connection->id;

        // An empty secret means "leave the one that is there": the endpoint
        // never returns it, so a form that saves the other fields cannot send
        // it back and would otherwise blank it.
        $secret = $connection->clientSecret === ''
            ? null
            : $this->cipher->encrypt($connection->clientSecret);

        $this->connection->executeStatement(
            'INSERT INTO sso_connections
                 (id, tenant_id, provider, enabled, client_id, client_secret,
                  directory, domains, auto_provision)
             VALUES (:id, :tenant, :provider, :enabled, :clientId,
                     COALESCE(:secret, \'\'), :directory, :domains, :autoProvision)
             ON CONFLICT (tenant_id, provider) DO UPDATE SET
                 enabled        = EXCLUDED.enabled,
                 client_id      = EXCLUDED.client_id,
                 client_secret  = COALESCE(:secret, sso_connections.client_secret),
                 directory      = EXCLUDED.directory,
                 domains        = EXCLUDED.domains,
                 auto_provision = EXCLUDED.auto_provision,
                 updated_at     = NOW()',
            [
                'id'            => $id,
                'tenant'        => $connection->tenantId,
                'provider'      => $connection->provider->value,
                'enabled'       => $connection->enabled ? 'true' : 'false',
                'clientId'      => $connection->clientId,
                'secret'        => $secret,
                'directory'     => $connection->directory,
                'domains'       => implode(',', $connection->domains),
                'autoProvision' => $connection->autoProvision ? 'true' : 'false',
            ],
        );

        return $this->find($connection->tenantId, $connection->provider) ?? $connection;
    }

    public function delete(string $tenantId, SsoProvider $provider): bool
    {
        return $this->connection->executeStatement(
            'DELETE FROM sso_connections WHERE tenant_id = :tenant AND provider = :provider',
            ['tenant' => $tenantId, 'provider' => $provider->value],
        ) > 0;
    }

    // --- the redirect in flight ------------------------------------------

    public function rememberState(
        string $state,
        string $tenantId,
        SsoProvider $provider,
        string $nonce,
        ?string $redirectTo,
        \DateTimeImmutable $expiresAt,
    ): void {
        $this->connection->executeStatement(
            'INSERT INTO sso_states (state, tenant_id, provider, nonce, redirect_to, expires_at)
             VALUES (:state, :tenant, :provider, :nonce, :redirectTo, :expires)',
            [
                'state'      => $state,
                'tenant'     => $tenantId,
                'provider'   => $provider->value,
                'nonce'      => $nonce,
                'redirectTo' => $redirectTo,
                'expires'    => $expiresAt->format(\DateTimeInterface::ATOM),
            ],
        );
    }

    public function claimState(string $state, \DateTimeImmutable $now): ?array
    {
        // Deleted and returned in one statement. A read followed by a delete
        // leaves a window in which the same callback can be replayed twice.
        $row = $this->connection->fetchAssociative(
            'DELETE FROM sso_states
             WHERE state = :state AND expires_at > :now
             RETURNING tenant_id, provider, nonce, redirect_to',
            ['state' => $state, 'now' => $now->format(\DateTimeInterface::ATOM)],
        );

        if ($row === false) {
            return null;
        }

        return [
            'tenantId'   => (string) $row['tenant_id'],
            'provider'   => SsoProvider::from((string) $row['provider']),
            'nonce'      => (string) $row['nonce'],
            'redirectTo' => $row['redirect_to'] === null ? null : (string) $row['redirect_to'],
        ];
    }

    public function purgeStates(\DateTimeImmutable $before): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM sso_states WHERE expires_at <= :before',
            ['before' => $before->format(\DateTimeInterface::ATOM)],
        );
    }

    // --- the account ------------------------------------------------------

    public function findUserBySubject(SsoProvider $provider, string $subject): ?string
    {
        $id = $this->connection->fetchOne(
            'SELECT id FROM users
             WHERE sso_provider = :provider AND sso_subject = :subject AND deleted_at IS NULL',
            ['provider' => $provider->value, 'subject' => $subject],
        );

        return is_string($id) ? $id : null;
    }

    public function linkUser(string $userId, SsoProvider $provider, string $subject): void
    {
        $this->connection->executeStatement(
            'UPDATE users SET sso_provider = :provider, sso_subject = :subject, updated_at = NOW()
             WHERE id = :id',
            ['id' => $userId, 'provider' => $provider->value, 'subject' => $subject],
        );
    }

    // --- internals --------------------------------------------------------

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): SsoConnection
    {
        $secret = (string) ($row['client_secret'] ?? '');

        return new SsoConnection(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            provider: SsoProvider::from((string) $row['provider']),
            enabled: (bool) $row['enabled'],
            clientId: (string) $row['client_id'],
            // A secret that will not decrypt — a rotated key, a restored
            // backup — is empty rather than an exception, so an administrator
            // sees a connection asking to be reconnected instead of a 500.
            clientSecret: $secret === '' ? '' : ($this->decrypt($secret) ?? ''),
            directory: $this->text($row['directory'] ?? null),
            domains: SsoConnection::parseDomains((string) ($row['domains'] ?? '')),
            autoProvision: (bool) $row['auto_provision'],
        );
    }

    private function decrypt(string $value): ?string
    {
        try {
            return $this->cipher->decrypt($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
