<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Ilt\AttendanceCalculator;
use Central\Api\Domain\Ilt\InstructorRole;
use Central\Api\Infrastructure\Persistence\DbalTrainingRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * More than one instructor on a session, against real PostgreSQL.
 *
 * The things worth proving are the two invariants the interface leans on: one
 * lead per session, and one host. Both are enforced by moving the previous
 * holder aside rather than refusing, because an administrator changing who
 * runs a workshop means to change it.
 *
 * And that the session's own `instructorId` is read from the roster rather
 * than kept beside it — two places recording who teaches a session is two
 * answers to the question.
 *
 * Skipped when the database is unreachable, so a checkout without the Docker
 * stack still gets a green suite.
 */
#[CoversClass(DbalTrainingRepository::class)]
final class DbalSessionInstructorTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalTrainingRepository $trainings;

    private string $tenantId;
    private string $sessionId;
    private string $ada;
    private string $bob;
    private string $cleo;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable — start it with: pnpm up');
        }

        $this->trainings = new DbalTrainingRepository(self::$connection, new AttendanceCalculator());

        $this->tenantId = $this->createTenant('inst');
        $this->ada = $this->createUser('ada');
        $this->bob = $this->createUser('bob');
        $this->cleo = $this->createUser('cleo');
        $this->sessionId = $this->createSession('Day one');
    }

    protected function tearDown(): void
    {
        if (self::$connection === null || !isset($this->tenantId)) {
            return;
        }

        // In order: what references, before what is referenced.
        $sweep = [
            'DELETE FROM session_instructors WHERE tenant_id = :id',
            'DELETE FROM training_sessions WHERE tenant_id = :id',
            'DELETE FROM live_trainings WHERE tenant_id = :id',
            'DELETE FROM users WHERE tenant_id = :id',
            'DELETE FROM tenants WHERE id = :id',
        ];

        foreach ($sweep as $sql) {
            self::$connection->executeStatement($sql, ['id' => $this->tenantId]);
        }
    }

    // --- more than one --------------------------------------------------------

    #[Test]
    public function a_session_can_be_taught_by_several_people(): void
    {
        $this->assign($this->ada, InstructorRole::Primary, true);
        $this->assign($this->bob, InstructorRole::CoInstructor, false);
        $this->assign($this->cleo, InstructorRole::Assistant, false);

        $roster = $this->trainings->instructorsFor($this->tenantId, $this->sessionId);

        self::assertCount(3, $roster);
        // The lead first, because that is the order a register is read in.
        self::assertSame('primary', $roster[0]['role']);
        self::assertSame($this->ada, $roster[0]['userId']);
        self::assertSame('Lead instructor', $roster[0]['roleLabel']);
    }

    #[Test]
    public function somebody_appears_once_however_often_they_are_added(): void
    {
        $this->assign($this->ada, InstructorRole::Assistant, false);
        $this->assign($this->ada, InstructorRole::CoInstructor, false);

        $roster = $this->trainings->instructorsFor($this->tenantId, $this->sessionId);

        self::assertCount(1, $roster);
        // The second answer replaces the first rather than sitting beside it.
        self::assertSame('co_instructor', $roster[0]['role']);
    }

    // --- one lead -------------------------------------------------------------

    #[Test]
    public function naming_a_new_lead_moves_the_old_one_aside(): void
    {
        $this->assign($this->ada, InstructorRole::Primary, false);
        $this->assign($this->bob, InstructorRole::Primary, false);

        $roles = $this->rolesByUser();

        // Being made to demote the previous lead first is a step with no
        // decision in it.
        self::assertSame('primary', $roles[$this->bob]);
        self::assertSame('co_instructor', $roles[$this->ada]);
    }

    #[Test]
    public function the_database_refuses_two_leads_on_one_session(): void
    {
        $this->assign($this->ada, InstructorRole::Primary, false);

        // "Co-instructor" and "assistant" are relative to somebody.
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

        $this->db()->executeStatement(
            "INSERT INTO session_instructors (session_id, user_id, tenant_id, role)
             VALUES (:session, :user, :tenant, 'primary')",
            ['session' => $this->sessionId, 'user' => $this->bob, 'tenant' => $this->tenantId],
        );
    }

    // --- one host -------------------------------------------------------------

    #[Test]
    public function naming_a_new_host_takes_the_link_from_the_old_one(): void
    {
        $this->assign($this->ada, InstructorRole::Primary, true);
        $this->assign($this->bob, InstructorRole::CoInstructor, true);

        $hosts = array_values(array_filter(
            $this->trainings->instructorsFor($this->tenantId, $this->sessionId),
            static fn (array $row): bool => $row['isHost'] === true,
        ));

        // The provider issues one host URL. Two hosts is a state it cannot
        // represent, and the second would fail silently on the morning.
        self::assertCount(1, $hosts);
        self::assertSame($this->bob, $hosts[0]['userId']);
    }

    #[Test]
    public function the_database_refuses_two_hosts_on_one_session(): void
    {
        $this->assign($this->ada, InstructorRole::Primary, true);

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

        $this->db()->executeStatement(
            "INSERT INTO session_instructors (session_id, user_id, tenant_id, role, is_host)
             VALUES (:session, :user, :tenant, 'assistant', TRUE)",
            ['session' => $this->sessionId, 'user' => $this->bob, 'tenant' => $this->tenantId],
        );
    }

    // --- the session's own instructor -----------------------------------------

    #[Test]
    public function the_sessions_instructor_is_read_from_the_roster(): void
    {
        $this->assign($this->ada, InstructorRole::Primary, true);

        $session = $this->trainings->findSession($this->tenantId, $this->sessionId);

        self::assertNotNull($session);
        self::assertSame($this->ada, $session->instructorId);

        // And it follows the roster rather than having to be written again.
        $this->assign($this->bob, InstructorRole::Primary, false);

        $session = $this->trainings->findSession($this->tenantId, $this->sessionId);
        self::assertNotNull($session);
        self::assertSame($this->bob, $session->instructorId);
    }

    #[Test]
    public function a_session_with_nobody_teaching_it_says_so(): void
    {
        $session = $this->trainings->findSession($this->tenantId, $this->sessionId);

        self::assertNotNull($session);
        self::assertNull($session->instructorId);
    }

    // --- removing -------------------------------------------------------------

    #[Test]
    public function removing_the_lead_leaves_the_session_without_one(): void
    {
        $this->assign($this->ada, InstructorRole::Primary, true);
        $this->assign($this->bob, InstructorRole::CoInstructor, false);

        self::assertTrue($this->trainings->removeInstructor($this->tenantId, $this->sessionId, $this->ada));

        // Nobody is promoted. A session with no lead is visibly wrong on the
        // screen, which is where somebody should choose who takes over —
        // guessing would put a name on it that nobody chose.
        $session = $this->trainings->findSession($this->tenantId, $this->sessionId);
        self::assertNotNull($session);
        self::assertNull($session->instructorId);
        self::assertCount(1, $this->trainings->instructorsFor($this->tenantId, $this->sessionId));
    }

    #[Test]
    public function removing_somebody_who_is_not_on_it_changes_nothing(): void
    {
        self::assertFalse(
            $this->trainings->removeInstructor($this->tenantId, $this->sessionId, $this->ada),
        );
    }

    // --- tenants --------------------------------------------------------------

    #[Test]
    public function a_roster_does_not_cross_tenants(): void
    {
        $this->assign($this->ada, InstructorRole::Primary, true);
        $other = Uuid::uuid4()->toString();

        self::assertSame([], $this->trainings->instructorsFor($other, $this->sessionId));
        self::assertFalse($this->trainings->assignInstructor(
            $other,
            $this->sessionId,
            $this->bob,
            InstructorRole::Assistant,
            false,
        ));
    }

    // --- helpers --------------------------------------------------------------

    private function assign(string $userId, InstructorRole $role, bool $isHost): void
    {
        $this->trainings->assignInstructor($this->tenantId, $this->sessionId, $userId, $role, $isHost);
    }

    /**
     * @return array<string, string>
     */
    private function rolesByUser(): array
    {
        $roles = [];

        foreach ($this->trainings->instructorsFor($this->tenantId, $this->sessionId) as $row) {
            $roles[(string) $row['userId']] = (string) $row['role'];
        }

        return $roles;
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

    private function createUser(string $who): string
    {
        return (string) $this->db()->fetchOne(
            "INSERT INTO users (tenant_id, email, first_name, last_name, status)
             VALUES (:tenant, :email, :first, 'Tester', 'active') RETURNING id",
            [
                'tenant' => $this->tenantId,
                'email'  => $who . '-' . substr(Uuid::uuid4()->toString(), 0, 8) . '@inst.test',
                'first'  => ucfirst($who),
            ],
        );
    }

    private function createSession(string $title): string
    {
        $trainingId = (string) $this->db()->fetchOne(
            "INSERT INTO live_trainings (tenant_id, title, status) VALUES (:tenant, :title, 'published')
             RETURNING id",
            ['tenant' => $this->tenantId, 'title' => 'Instructor harness'],
        );

        return (string) $this->db()->fetchOne(
            "INSERT INTO training_sessions (tenant_id, training_id, title, starts_at, ends_at)
             VALUES (:tenant, :training, :title, NOW() + INTERVAL '1 day', NOW() + INTERVAL '1 day 2 hours')
             RETURNING id",
            ['tenant' => $this->tenantId, 'training' => $trainingId, 'title' => $title],
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
