<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Infrastructure\Sso;

use Central\Api\Domain\Sso\SsoConnection;
use Central\Api\Domain\Sso\SsoProvider;
use Central\Api\Domain\Sso\SsoRepository;
use Central\Api\Infrastructure\Sso\EnvFallbackSsoRepository;
use PHPUnit\Framework\TestCase;

final class EnvFallbackSsoRepositoryTest extends TestCase
{
    private const DEFAULT_TENANT = '11111111-1111-1111-1111-111111111111';
    private const OTHER_TENANT   = '22222222-2222-2222-2222-222222222222';

    /**
     * @param array<string, array<string, mixed>> $config
     */
    private function repo(SsoRepository $inner, array $config, string $defaultTenant = self::DEFAULT_TENANT): EnvFallbackSsoRepository
    {
        return new EnvFallbackSsoRepository($inner, $defaultTenant, $config);
    }

    /**
     * @param  array<string, mixed>                 $overrides
     * @return array<string, array<string, mixed>>
     */
    private function microsoftConfig(array $overrides = []): array
    {
        return [
            'microsoft' => array_merge([
                'client_id'      => 'the-client-id',
                'client_secret'  => 'the-secret',
                'directory'      => 'dir-guid',
                'enabled'        => true,
                'domains'        => 'midwesternog.com, @example.com',
                'auto_provision' => true,
            ], $overrides),
        ];
    }

    public function testReadsTheDefaultTenantConnectionFromEnvWhenTheDatabaseHasNone(): void
    {
        $repo = $this->repo(new FakeSsoRepository(), $this->microsoftConfig());

        $connection = $repo->find(self::DEFAULT_TENANT, SsoProvider::Microsoft);

        self::assertNotNull($connection);
        self::assertSame(self::DEFAULT_TENANT, $connection->tenantId);
        self::assertSame(SsoProvider::Microsoft, $connection->provider);
        self::assertTrue($connection->enabled);
        self::assertSame('the-client-id', $connection->clientId);
        self::assertSame('the-secret', $connection->clientSecret);
        self::assertSame('dir-guid', $connection->directory);
        self::assertSame(['midwesternog.com', 'example.com'], $connection->domains);
        self::assertTrue($connection->autoProvision);
    }

    public function testADatabaseConnectionOverridesTheEnvironment(): void
    {
        $inner = new FakeSsoRepository();
        $inner->save(new SsoConnection(
            id: 'db-id',
            tenantId: self::DEFAULT_TENANT,
            provider: SsoProvider::Microsoft,
            enabled: true,
            clientId: 'db-client',
            clientSecret: 'db-secret',
        ));

        $connection = $this->repo($inner, $this->microsoftConfig())->find(self::DEFAULT_TENANT, SsoProvider::Microsoft);

        self::assertNotNull($connection);
        self::assertSame('db-id', $connection->id);
        self::assertSame('db-client', $connection->clientId);
    }

    public function testEnvIsNotUsedForOtherTenants(): void
    {
        $repo = $this->repo(new FakeSsoRepository(), $this->microsoftConfig());

        self::assertNull($repo->find(self::OTHER_TENANT, SsoProvider::Microsoft));
    }

    public function testEnvIsIgnoredWhenNoDefaultTenantIsConfigured(): void
    {
        $repo = $this->repo(new FakeSsoRepository(), $this->microsoftConfig(), '');

        self::assertNull($repo->find(self::DEFAULT_TENANT, SsoProvider::Microsoft));
    }

    public function testAHalfConfiguredProviderIsTreatedAsAbsent(): void
    {
        $noSecret   = $this->repo(new FakeSsoRepository(), $this->microsoftConfig(['client_secret' => '']));
        $noClientId = $this->repo(new FakeSsoRepository(), $this->microsoftConfig(['client_id' => '   ']));

        self::assertNull($noSecret->find(self::DEFAULT_TENANT, SsoProvider::Microsoft));
        self::assertNull($noClientId->find(self::DEFAULT_TENANT, SsoProvider::Microsoft));
    }

    public function testDisabledFlagAndEmptyDirectoryArePassedThrough(): void
    {
        $repo = $this->repo(new FakeSsoRepository(), $this->microsoftConfig([
            'enabled'   => false,
            'directory' => '',
        ]));

        $connection = $repo->find(self::DEFAULT_TENANT, SsoProvider::Microsoft);

        self::assertNotNull($connection);
        self::assertFalse($connection->enabled);
        self::assertNull($connection->directory);
    }

    public function testForTenantMergesEnvProvidersTheDatabaseDoesNotCover(): void
    {
        $inner = new FakeSsoRepository();
        // Google is already set up in the database; Microsoft only in env.
        $inner->save(new SsoConnection(
            id: 'db-google',
            tenantId: self::DEFAULT_TENANT,
            provider: SsoProvider::Google,
            enabled: true,
            clientId: 'g-client',
            clientSecret: 'g-secret',
        ));

        $connections = $this->repo($inner, $this->microsoftConfig())->forTenant(self::DEFAULT_TENANT);

        $google    = $this->pick($connections, SsoProvider::Google);
        $microsoft = $this->pick($connections, SsoProvider::Microsoft);

        self::assertNotNull($google);
        self::assertNotNull($microsoft);
        self::assertSame('db-google', $google->id);
        self::assertSame('the-client-id', $microsoft->clientId);
    }

    /**
     * @param list<SsoConnection> $connections
     */
    private function pick(array $connections, SsoProvider $provider): ?SsoConnection
    {
        foreach ($connections as $connection) {
            if ($connection->provider === $provider) {
                return $connection;
            }
        }

        return null;
    }

    public function testForTenantDoesNotShadowADatabaseProviderWithEnv(): void
    {
        $inner = new FakeSsoRepository();
        $inner->save(new SsoConnection(
            id: 'db-ms',
            tenantId: self::DEFAULT_TENANT,
            provider: SsoProvider::Microsoft,
            enabled: true,
            clientId: 'db-client',
            clientSecret: 'db-secret',
        ));

        $connections = $this->repo($inner, $this->microsoftConfig())->forTenant(self::DEFAULT_TENANT);

        self::assertCount(1, $connections);
        self::assertSame('db-ms', $connections[0]->id);
    }

    public function testWritesGoStraightToTheWrappedRepository(): void
    {
        $inner = new FakeSsoRepository();
        $repo  = $this->repo($inner, $this->microsoftConfig());

        $repo->save(new SsoConnection(
            id: '',
            tenantId: self::DEFAULT_TENANT,
            provider: SsoProvider::Microsoft,
            enabled: true,
            clientId: 'written',
            clientSecret: 'written-secret',
        ));

        // The write landed in the database, and now wins over the env on read.
        $connection = $repo->find(self::DEFAULT_TENANT, SsoProvider::Microsoft);
        self::assertNotNull($connection);
        self::assertSame('written', $connection->clientId);
    }
}

/**
 * An in-memory SsoRepository for the decorator to wrap.
 */
final class FakeSsoRepository implements SsoRepository
{
    /** @var array<string, SsoConnection> keyed by "tenant|provider" */
    private array $rows = [];

    public function forTenant(string $tenantId): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (SsoConnection $c): bool => $c->tenantId === $tenantId,
        ));
    }

    public function find(string $tenantId, SsoProvider $provider): ?SsoConnection
    {
        return $this->rows[$tenantId . '|' . $provider->value] ?? null;
    }

    public function save(SsoConnection $connection): SsoConnection
    {
        $this->rows[$connection->tenantId . '|' . $connection->provider->value] = $connection;

        return $connection;
    }

    public function delete(string $tenantId, SsoProvider $provider): bool
    {
        $key = $tenantId . '|' . $provider->value;
        $existed = isset($this->rows[$key]);
        unset($this->rows[$key]);

        return $existed;
    }

    public function rememberState(
        string $state,
        string $tenantId,
        SsoProvider $provider,
        string $nonce,
        ?string $redirectTo,
        \DateTimeImmutable $expiresAt,
    ): void {
    }

    public function claimState(string $state, \DateTimeImmutable $now): ?array
    {
        return null;
    }

    public function purgeStates(\DateTimeImmutable $before): int
    {
        return 0;
    }

    public function findUserBySubject(SsoProvider $provider, string $subject): ?string
    {
        return null;
    }

    public function linkUser(string $userId, SsoProvider $provider, string $subject): void
    {
    }
}
