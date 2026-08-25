<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Lookup\Lookup;
use Central\Api\Domain\Lookup\LookupKind;
use Central\Api\Infrastructure\Persistence\DbalLookupRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Tenant isolation, against a real PostgreSQL.
 *
 * This exists because the legacy code failed exactly here and unit tests with
 * a fake repository would not have caught it: `SELECT * FROM branches` with no
 * WHERE clause, and `UPDATE branches … WHERE branch_id = ?` with no tenant
 * column. Both passed every check that did not involve a second tenant.
 *
 * Skipped when the database is unreachable, so a checkout without the Docker
 * stack still gets a green suite.
 */
#[CoversClass(DbalLookupRepository::class)]
final class DbalLookupRepositoryTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalLookupRepository $lookups;
    private string $tenantA;
    private string $tenantB;

    public static function setUpBeforeClass(): void
    {
        $connection = self::connect();

        if ($connection === null) {
            return;
        }

        self::$connection = $connection;
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable — start it with: pnpm up');
        }

        $this->lookups = new DbalLookupRepository(self::$connection);

        // Two throwaway tenants. The whole point is that a second one exists.
        $this->tenantA = $this->createTenant('iso-a');
        $this->tenantB = $this->createTenant('iso-b');
    }

    protected function tearDown(): void
    {
        if (self::$connection === null) {
            return;
        }

        foreach ([$this->tenantA ?? null, $this->tenantB ?? null] as $tenantId) {
            if ($tenantId !== null) {
                // lookups cascade from tenants.
                self::$connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $tenantId]);
            }
        }
    }

    #[Test]
    public function a_list_never_includes_another_tenants_rows(): void
    {
        $this->lookups->create($this->branch($this->tenantA, 'Lagos HQ'));
        $this->lookups->create($this->branch($this->tenantA, 'Abuja'));
        $this->lookups->create($this->branch($this->tenantB, 'Nairobi'));

        $listA = $this->lookups->paginate($this->tenantA, LookupKind::Branch, 1, 50);
        $listB = $this->lookups->paginate($this->tenantB, LookupKind::Branch, 1, 50);

        self::assertSame(2, $listA['total']);
        self::assertSame(1, $listB['total']);
        self::assertSame(
            ['Abuja', 'Lagos HQ'],
            array_map(static fn (Lookup $l): string => $l->name, $listA['items']),
        );
    }

    #[Test]
    public function finding_another_tenants_row_by_id_returns_nothing(): void
    {
        $theirs = $this->lookups->create($this->branch($this->tenantA, 'Lagos HQ'));

        // The id is real and correct — only the tenant differs.
        self::assertNotNull($this->lookups->find($this->tenantA, LookupKind::Branch, $theirs->id));
        self::assertNull($this->lookups->find($this->tenantB, LookupKind::Branch, $theirs->id));
    }

    #[Test]
    public function updating_another_tenants_row_changes_nothing(): void
    {
        $theirs = $this->lookups->create($this->branch($this->tenantA, 'Lagos HQ'));

        // Same id, attacker's tenant. The legacy UPDATE had no tenant clause
        // and would have renamed it.
        $attempt = new Lookup(
            id: $theirs->id,
            tenantId: $this->tenantB,
            kind: LookupKind::Branch,
            name: 'Pwned',
        );

        try {
            $this->lookups->update($attempt);
        } catch (\RuntimeException) {
            // update() reads the row back and finds nothing — acceptable.
        }

        $unchanged = $this->lookups->find($this->tenantA, LookupKind::Branch, $theirs->id);

        self::assertNotNull($unchanged);
        self::assertSame('Lagos HQ', $unchanged->name);
    }

    #[Test]
    public function deactivating_another_tenants_row_does_nothing(): void
    {
        $theirs = $this->lookups->create($this->branch($this->tenantA, 'Lagos HQ'));

        self::assertFalse(
            $this->lookups->setActive($this->tenantB, LookupKind::Branch, $theirs->id, false),
            'setActive must report that it matched nothing.',
        );

        self::assertTrue($this->lookups->find($this->tenantA, LookupKind::Branch, $theirs->id)?->isActive);
    }

    #[Test]
    public function soft_deleting_another_tenants_row_does_nothing(): void
    {
        $theirs = $this->lookups->create($this->branch($this->tenantA, 'Lagos HQ'));

        self::assertFalse($this->lookups->softDelete($this->tenantB, LookupKind::Branch, $theirs->id));
        self::assertNotNull($this->lookups->find($this->tenantA, LookupKind::Branch, $theirs->id));
    }

    #[Test]
    public function the_same_name_is_allowed_in_two_different_tenants(): void
    {
        // Isolation cuts both ways: one tenant naming a branch "Head Office"
        // must not stop another doing the same.
        $this->lookups->create($this->branch($this->tenantA, 'Head Office'));
        $this->lookups->create($this->branch($this->tenantB, 'Head Office'));

        self::assertTrue($this->lookups->existsWithName($this->tenantA, LookupKind::Branch, 'Head Office'));
        self::assertTrue($this->lookups->existsWithName($this->tenantB, LookupKind::Branch, 'Head Office'));
    }

    #[Test]
    public function a_name_clash_within_one_tenant_is_detected_case_insensitively(): void
    {
        // The legacy MySQL collation was case-insensitive; PostgreSQL is not,
        // so without lower() the port would start admitting duplicates.
        $this->lookups->create($this->branch($this->tenantA, 'Lagos HQ'));

        self::assertTrue($this->lookups->existsWithName($this->tenantA, LookupKind::Branch, 'lagos hq'));
        self::assertTrue($this->lookups->existsWithName($this->tenantA, LookupKind::Branch, 'LAGOS HQ'));
    }

    #[Test]
    public function kinds_do_not_leak_into_each_other(): void
    {
        $branch = $this->lookups->create($this->branch($this->tenantA, 'Operations'));
        $this->lookups->create(new Lookup(
            id: '',
            tenantId: $this->tenantA,
            kind: LookupKind::Department,
            name: 'Operations',
        ));

        // Same tenant, same name, different kind — both allowed, and neither
        // is reachable through the other's kind.
        self::assertNull($this->lookups->find($this->tenantA, LookupKind::Department, $branch->id));
        self::assertSame(1, $this->lookups->paginate($this->tenantA, LookupKind::Branch, 1, 50)['total']);
        self::assertSame(1, $this->lookups->paginate($this->tenantA, LookupKind::Department, 1, 50)['total']);
    }

    #[Test]
    public function the_active_list_excludes_deactivated_entries(): void
    {
        $live = $this->lookups->create($this->branch($this->tenantA, 'Lagos HQ'));
        $retired = $this->lookups->create($this->branch($this->tenantA, 'Old Office'));

        $this->lookups->setActive($this->tenantA, LookupKind::Branch, $retired->id, false);

        $active = $this->lookups->active($this->tenantA, LookupKind::Branch);

        self::assertCount(1, $active);
        self::assertSame($live->id, $active[0]->id);

        // ...but the admin list still shows both, so it can be reactivated.
        self::assertSame(2, $this->lookups->paginate($this->tenantA, LookupKind::Branch, 1, 50)['total']);
    }

    #[Test]
    public function search_is_scoped_to_the_tenant(): void
    {
        $this->lookups->create($this->branch($this->tenantA, 'Lagos HQ'));
        $this->lookups->create($this->branch($this->tenantB, 'Lagos Annex'));

        $found = $this->lookups->paginate($this->tenantB, LookupKind::Branch, 1, 50, search: 'Lagos');

        self::assertSame(1, $found['total']);
        self::assertSame('Lagos Annex', $found['items'][0]->name);
    }

    // --- helpers ------------------------------------------------------------

    private function branch(string $tenantId, string $name): Lookup
    {
        return new Lookup(
            id: '',
            tenantId: $tenantId,
            kind: LookupKind::Branch,
            name: $name,
            code: strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name) ?? 'X', 0, 3)) . random_int(100, 999),
        );
    }

    private function createTenant(string $prefix): string
    {
        $id = Uuid::uuid4()->toString();
        $connection = self::$connection;

        if ($connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable.');
        }

        $connection->executeStatement(
            'INSERT INTO tenants (id, name, code, status) VALUES (:id, :name, :code, \'active\')',
            ['id' => $id, 'name' => $prefix . ' test tenant', 'code' => $prefix . '-' . substr($id, 0, 8)],
        );

        return $id;
    }

    private static function connect(): ?Connection
    {
        $root = dirname(__DIR__, 2);

        if (is_file($root . '/.env')) {
            \Dotenv\Dotenv::createImmutable($root)->safeLoad();
        }

        try {
            $connection = DriverManager::getConnection([
                'driver'   => 'pdo_pgsql',
                'host'     => $_ENV['DB_HOST'] ?? '127.0.0.1',
                'port'     => (int) ($_ENV['DB_PORT'] ?? 5432),
                'dbname'   => $_ENV['DB_NAME'] ?? 'central_lms',
                'user'     => $_ENV['DB_USER'] ?? 'central_lms',
                'password' => $_ENV['DB_PASSWORD'] ?? '',
            ], new \Doctrine\DBAL\Configuration());

            $connection->executeQuery('SELECT 1');

            return $connection;
        } catch (\Throwable) {
            return null;
        }
    }
}
