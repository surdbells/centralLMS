<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Ilt\MeetingProviderId;
use Central\Api\Domain\Ilt\VideoIntegration;
use Central\Api\Domain\Ilt\VideoIntegrationRepository;
use Central\Api\Support\Cipher;
use Central\Api\Support\Clock;
use Doctrine\DBAL\Connection;

/**
 * DBAL-backed store for tenant conferencing connections.
 *
 * Deliberately not a Doctrine entity. This table is written from OAuth
 * callbacks and from token refreshes that must persist independently of the
 * surrounding unit of work — an ORM identity map and a flush at the end of the
 * request are the wrong shape for that, and a lost token write permanently
 * disconnects a customer.
 *
 * Credentials are encrypted on the way in and decrypted on the way out, so no
 * caller has to remember to do it.
 */
final class DbalVideoIntegrationRepository implements VideoIntegrationRepository
{
    private const TABLE = 'video_integrations';

    private const COLUMNS = 'id, tenant_id, platform, enabled, is_default, client_id, client_secret,
                             access_token, refresh_token, token_expires_at, settings';

    public function __construct(
        private readonly Connection $connection,
        private readonly Cipher $cipher,
        private readonly Clock $clock,
    ) {
    }

    public function find(string $tenantId, MeetingProviderId $platform): ?VideoIntegration
    {
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT %s FROM %s WHERE tenant_id = :tenant AND platform = :platform', self::COLUMNS, self::TABLE),
            ['tenant' => $tenantId, 'platform' => $platform->value],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function findAllForTenant(string $tenantId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT %s FROM %s WHERE tenant_id = :tenant ORDER BY platform', self::COLUMNS, self::TABLE),
            ['tenant' => $tenantId],
        );

        return array_map(fn (array $row): VideoIntegration => $this->hydrate($row), $rows);
    }

    public function findDefault(string $tenantId): ?VideoIntegration
    {
        // Falls back to any enabled connection: a tenant that connected one
        // platform without explicitly marking it default still expects
        // scheduling to work.
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM %s
                 WHERE tenant_id = :tenant AND enabled
                 ORDER BY is_default DESC, created_at ASC
                 LIMIT 1',
                self::COLUMNS,
                self::TABLE,
            ),
            ['tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function saveTokens(VideoIntegration $integration): void
    {
        $this->connection->executeStatement(
            sprintf(
                'UPDATE %s
                 SET access_token = :access, refresh_token = :refresh,
                     token_expires_at = :expires, updated_at = :now
                 WHERE tenant_id = :tenant AND platform = :platform',
                self::TABLE,
            ),
            [
                'access'   => $this->cipher->encryptNullable($integration->accessToken),
                'refresh'  => $this->cipher->encryptNullable($integration->refreshToken),
                'expires'  => $integration->tokenExpiresAt?->format('c'),
                'now'      => $this->clock->now()->format('c'),
                'tenant'   => $integration->tenantId,
                'platform' => $integration->platform->value,
            ],
        );
    }

    public function save(VideoIntegration $integration): void
    {
        $this->connection->transactional(function (Connection $connection) use ($integration): void {
            // Demote any other default first: the partial unique index would
            // otherwise reject the insert rather than silently allowing two.
            if ($integration->isDefault) {
                $connection->executeStatement(
                    sprintf(
                        'UPDATE %s SET is_default = FALSE
                         WHERE tenant_id = :tenant AND platform <> :platform AND is_default',
                        self::TABLE,
                    ),
                    ['tenant' => $integration->tenantId, 'platform' => $integration->platform->value],
                );
            }

            $connection->executeStatement(
                sprintf(
                    'INSERT INTO %s
                        (tenant_id, platform, enabled, is_default, client_id, client_secret,
                         access_token, refresh_token, token_expires_at, settings, connected_at)
                     VALUES
                        (:tenant, :platform, :enabled, :isDefault, :clientId, :clientSecret,
                         :access, :refresh, :expires, CAST(:settings AS JSONB), :now)
                     ON CONFLICT (tenant_id, platform) DO UPDATE SET
                        enabled          = EXCLUDED.enabled,
                        is_default       = EXCLUDED.is_default,
                        client_id        = EXCLUDED.client_id,
                        client_secret    = EXCLUDED.client_secret,
                        access_token     = EXCLUDED.access_token,
                        refresh_token    = EXCLUDED.refresh_token,
                        token_expires_at = EXCLUDED.token_expires_at,
                        settings         = EXCLUDED.settings,
                        updated_at       = EXCLUDED.connected_at',
                    self::TABLE,
                ),
                [
                    'tenant'       => $integration->tenantId,
                    'platform'     => $integration->platform->value,
                    'enabled'      => $integration->enabled ? 'true' : 'false',
                    'isDefault'    => $integration->isDefault ? 'true' : 'false',
                    'clientId'     => $integration->clientId,
                    'clientSecret' => $this->cipher->encryptNullable($integration->clientSecret),
                    'access'       => $this->cipher->encryptNullable($integration->accessToken),
                    'refresh'      => $this->cipher->encryptNullable($integration->refreshToken),
                    'expires'      => $integration->tokenExpiresAt?->format('c'),
                    'settings'     => json_encode($integration->settings, JSON_THROW_ON_ERROR),
                    'now'          => $this->clock->now()->format('c'),
                ],
            );
        });
    }

    public function delete(string $tenantId, MeetingProviderId $platform): void
    {
        $this->connection->executeStatement(
            sprintf('DELETE FROM %s WHERE tenant_id = :tenant AND platform = :platform', self::TABLE),
            ['tenant' => $tenantId, 'platform' => $platform->value],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): VideoIntegration
    {
        $platform = MeetingProviderId::tryFrom((string) $row['platform']);

        if ($platform === null) {
            // The check constraint makes this unreachable, but an unknown
            // platform must never be silently treated as Zoom.
            throw new \RuntimeException(sprintf('Unknown video platform "%s" in database.', $row['platform']));
        }

        $settings = [];
        if (is_string($row['settings']) && $row['settings'] !== '') {
            $decoded  = json_decode($row['settings'], true);
            $settings = is_array($decoded) ? $decoded : [];
        }

        return new VideoIntegration(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            platform: $platform,
            enabled: (bool) $row['enabled'],
            isDefault: (bool) $row['is_default'],
            accessToken: $this->cipher->decryptNullable($this->asNullableString($row['access_token'])),
            refreshToken: $this->cipher->decryptNullable($this->asNullableString($row['refresh_token'])),
            tokenExpiresAt: $this->asDate($row['token_expires_at'] ?? null),
            clientId: $this->asNullableString($row['client_id'] ?? null),
            clientSecret: $this->cipher->decryptNullable($this->asNullableString($row['client_secret'] ?? null)),
            settings: $settings,
        );
    }

    private function asNullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function asDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
