<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Messaging\Broadcast;
use Central\Api\Domain\Messaging\BroadcastPriority;
use Central\Api\Domain\Messaging\BroadcastStatus;
use Central\Api\Domain\Messaging\BroadcastTarget;
use Central\Api\Infrastructure\Persistence\DbalBroadcastRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Broadcasts against a real PostgreSQL.
 *
 * What is worth proving: that the audience is resolved when it is sent rather
 * than when it is read, that people who have left do not receive policy
 * acknowledgements they can never give, and that acknowledging implies having
 * read — the state an auditor would query for.
 *
 * Skipped when the database is unreachable, so a checkout without the Docker
 * stack still gets a green suite.
 */
#[CoversClass(DbalBroadcastRepository::class)]
final class DbalBroadcastRepositoryTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalBroadcastRepository $broadcasts;

    private string $tenantA;
    private string $tenantB;
    private string $author;
    private string $alice;
    private string $bob;
    private string $departmentId;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable — start it with: pnpm up');
        }

        $this->broadcasts = new DbalBroadcastRepository(self::$connection);

        $this->tenantA = $this->createTenant('bc-a');
        $this->tenantB = $this->createTenant('bc-b');
        $this->departmentId = $this->createLookup($this->tenantA, 'department', 'Operations');

        $this->author = $this->createUser($this->tenantA, 'author');
        $this->alice = $this->createUser($this->tenantA, 'alice', $this->departmentId);
        $this->bob = $this->createUser($this->tenantA, 'bob');
    }

    protected function tearDown(): void
    {
        if (self::$connection === null) {
            return;
        }

        foreach ([$this->tenantA ?? null, $this->tenantB ?? null] as $tenantId) {
            if ($tenantId === null) {
                continue;
            }

            self::$connection->executeStatement('DELETE FROM users WHERE tenant_id = :id', ['id' => $tenantId]);
            self::$connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $tenantId]);
        }
    }

    // --- sending --------------------------------------------------------------

    #[Test]
    public function sending_to_everybody_reaches_every_active_person(): void
    {
        $broadcast = $this->broadcasts->save($this->announcement());

        self::assertSame(3, $this->broadcasts->send($this->tenantA, $broadcast->id));
    }

    #[Test]
    public function somebody_who_has_left_does_not_receive_it(): void
    {
        // `suspended`, not `inactive`: the four statuses are invited,
        // active, suspended and locked, and only the active ones are people
        // who can act on an announcement.
        self::$connection?->executeStatement(
            "UPDATE users SET status = 'suspended' WHERE id = :id",
            ['id' => $this->bob],
        );

        $broadcast = $this->broadcasts->save($this->announcement());

        // A policy acknowledgement sent to somebody who has left is an
        // obligation nobody can discharge.
        self::assertSame(2, $this->broadcasts->send($this->tenantA, $broadcast->id));
    }

    #[Test]
    public function a_department_broadcast_reaches_only_that_department(): void
    {
        $broadcast = $this->broadcasts->save($this->announcement(
            target: BroadcastTarget::Department,
            criteria: ['departmentIds' => [$this->departmentId]],
        ));

        self::assertSame(1, $this->broadcasts->send($this->tenantA, $broadcast->id));

        $alices = $this->broadcasts->forRecipient($this->tenantA, $this->alice, false);
        $bobs = $this->broadcasts->forRecipient($this->tenantA, $this->bob, false);

        self::assertCount(1, $alices);
        self::assertSame([], $bobs);
    }

    #[Test]
    public function an_audience_naming_nobody_reaches_nobody(): void
    {
        $broadcast = $this->broadcasts->save($this->announcement(
            target: BroadcastTarget::Department,
            criteria: ['departmentIds' => [Uuid::uuid4()->toString()]],
        ));

        // Not everybody. An empty `IN` list means nobody was named, which is
        // a different thing from "all of them".
        self::assertSame(0, $this->broadcasts->send($this->tenantA, $broadcast->id));
    }

    #[Test]
    public function the_audience_is_fixed_when_it_is_sent_not_when_it_is_read(): void
    {
        $broadcast = $this->broadcasts->save($this->announcement());
        $this->broadcasts->send($this->tenantA, $broadcast->id);

        // Somebody joins afterwards.
        $latecomer = $this->createUser($this->tenantA, 'latecomer');

        // They do not see an announcement from before they arrived.
        self::assertSame([], $this->broadcasts->forRecipient($this->tenantA, $latecomer, false));
    }

    #[Test]
    public function sending_twice_does_not_send_twice(): void
    {
        $broadcast = $this->broadcasts->save($this->announcement());

        self::assertSame(3, $this->broadcasts->send($this->tenantA, $broadcast->id));
        self::assertSame(0, $this->broadcasts->send($this->tenantA, $broadcast->id));

        self::assertCount(1, $this->broadcasts->forRecipient($this->tenantA, $this->alice, false));
    }

    // --- reading and acknowledging -------------------------------------------

    #[Test]
    public function acknowledging_marks_it_read_as_well(): void
    {
        $broadcast = $this->broadcasts->save($this->announcement());
        $this->broadcasts->send($this->tenantA, $broadcast->id);

        // Straight to acknowledging, without reading first. "Confirmed but
        // not read" is the state an auditor would ask about, and the check
        // constraint refuses it.
        self::assertTrue($this->broadcasts->acknowledge($this->tenantA, $this->alice, $broadcast->id));

        $mine = $this->broadcasts->findForRecipient($this->tenantA, $this->alice, $broadcast->id);

        self::assertNotNull($mine);
        self::assertTrue($mine['isRead']);
        self::assertTrue($mine['isAcknowledged']);
    }

    #[Test]
    public function reading_twice_does_not_move_the_first_time(): void
    {
        $broadcast = $this->broadcasts->save($this->announcement());
        $this->broadcasts->send($this->tenantA, $broadcast->id);

        self::assertTrue($this->broadcasts->markRead($this->tenantA, $this->alice, $broadcast->id));

        // The second is a no-op: the first-read time is the one that matters.
        self::assertFalse($this->broadcasts->markRead($this->tenantA, $this->alice, $broadcast->id));
    }

    #[Test]
    public function the_unread_count_falls_as_people_read(): void
    {
        $broadcast = $this->broadcasts->save($this->announcement());
        $this->broadcasts->send($this->tenantA, $broadcast->id);

        self::assertSame(1, $this->broadcasts->unreadCount($this->tenantA, $this->alice));

        $this->broadcasts->markRead($this->tenantA, $this->alice, $broadcast->id);

        self::assertSame(0, $this->broadcasts->unreadCount($this->tenantA, $this->alice));
    }

    #[Test]
    public function an_expired_announcement_drops_out_of_the_list(): void
    {
        $broadcast = $this->broadcasts->save($this->announcement());
        $this->broadcasts->send($this->tenantA, $broadcast->id);

        self::$connection?->executeStatement(
            "UPDATE broadcasts SET expires_at = NOW() - INTERVAL '1 day' WHERE id = :id",
            ['id' => $broadcast->id],
        );

        // Old news that cannot be cleared is how a list stops being read.
        self::assertSame([], $this->broadcasts->forRecipient($this->tenantA, $this->alice, false));
        self::assertSame(0, $this->broadcasts->unreadCount($this->tenantA, $this->alice));
    }

    // --- counts ---------------------------------------------------------------

    #[Test]
    public function the_counts_are_taken_from_the_recipients(): void
    {
        $broadcast = $this->broadcasts->save($this->announcement());
        $this->broadcasts->send($this->tenantA, $broadcast->id);

        $this->broadcasts->markRead($this->tenantA, $this->alice, $broadcast->id);
        $this->broadcasts->acknowledge($this->tenantA, $this->bob, $broadcast->id);

        $read = $this->broadcasts->find($this->tenantA, $broadcast->id);

        self::assertNotNull($read);
        self::assertSame(3, $read->recipientCount);
        self::assertSame(2, $read->readCount);
        self::assertSame(1, $read->acknowledgedCount);
        self::assertSame(67, $read->readPercentage());
    }

    #[Test]
    public function an_unsent_broadcast_reports_no_progress_rather_than_all_of_it(): void
    {
        $broadcast = $this->broadcasts->save($this->announcement());

        // Nought of nobody having read it is 0%, not 100%.
        self::assertSame(0, $broadcast->readPercentage());
    }

    // --- lifecycle ------------------------------------------------------------

    #[Test]
    public function a_sent_broadcast_can_no_longer_be_edited_or_cancelled(): void
    {
        $broadcast = $this->broadcasts->save($this->announcement());
        $this->broadcasts->send($this->tenantA, $broadcast->id);

        $after = $this->broadcasts->find($this->tenantA, $broadcast->id);

        self::assertNotNull($after);
        self::assertSame(BroadcastStatus::Sent, $after->status);
        self::assertFalse($after->isEditable());
        self::assertFalse($this->broadcasts->cancel($this->tenantA, $broadcast->id));
    }

    #[Test]
    public function a_scheduled_broadcast_becomes_due(): void
    {
        $broadcast = $this->broadcasts->save(new Broadcast(
            id: Uuid::uuid4()->toString(),
            tenantId: $this->tenantA,
            title: 'Later',
            content: 'This goes out in a moment.',
            scheduledAt: new \DateTimeImmutable('-1 minute'),
            status: BroadcastStatus::Scheduled,
            createdBy: $this->author,
        ));

        $due = $this->broadcasts->due(new \DateTimeImmutable(), 10);
        $ids = array_map(static fn (Broadcast $b): string => $b->id, $due);

        self::assertContains($broadcast->id, $ids);
    }

    #[Test]
    public function one_tenant_cannot_read_anothers_broadcast(): void
    {
        $broadcast = $this->broadcasts->save($this->announcement());

        self::assertNull($this->broadcasts->find($this->tenantB, $broadcast->id));
        self::assertSame([], $this->broadcasts->authored($this->tenantB, null, 1, 25)['items']);
    }

    // --- fixtures -------------------------------------------------------------

    /**
     * @param array<string, mixed> $criteria
     */
    private function announcement(
        BroadcastTarget $target = BroadcastTarget::All,
        array $criteria = [],
    ): Broadcast {
        return new Broadcast(
            id: Uuid::uuid4()->toString(),
            tenantId: $this->tenantA,
            title: 'Site closed Monday',
            content: 'The yard is shut for the bank holiday.',
            targetType: $target,
            targetCriteria: $criteria,
            priority: BroadcastPriority::Important,
            requiresAcknowledgment: true,
            createdBy: $this->author,
        );
    }

    private function createLookup(string $tenantId, string $kind, string $name): string
    {
        return (string) $this->db()->fetchOne(
            'INSERT INTO lookups (tenant_id, kind, name) VALUES (:tenant, :kind, :name) RETURNING id',
            ['tenant' => $tenantId, 'kind' => $kind, 'name' => $name],
        );
    }

    private function createTenant(string $prefix): string
    {
        $id = Uuid::uuid4()->toString();

        $this->db()->executeStatement(
            "INSERT INTO tenants (id, name, code, status) VALUES (:id, :name, :code, 'active')",
            ['id' => $id, 'name' => $prefix . ' test tenant', 'code' => $prefix . '-' . substr($id, 0, 8)],
        );

        return $id;
    }

    private function createUser(string $tenantId, string $who, ?string $departmentId = null): string
    {
        return (string) $this->db()->fetchOne(
            "INSERT INTO users (tenant_id, email, first_name, last_name, status, department_id)
             VALUES (:tenant, :email, :first, 'Tester', 'active', :department) RETURNING id",
            [
                'tenant'     => $tenantId,
                'email'      => $who . '-' . substr(Uuid::uuid4()->toString(), 0, 8) . '@bc.test',
                'first'      => ucfirst($who),
                'department' => $departmentId,
            ],
        );
    }

    private function db(): Connection
    {
        return self::$connection ?? throw new \RuntimeException('No connection.');
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
