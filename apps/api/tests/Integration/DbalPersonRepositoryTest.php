<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Identity\UserStatus;
use Central\Api\Domain\People\Person;
use Central\Api\Domain\People\PersonFilters;
use Central\Api\Infrastructure\Persistence\DbalPersonRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * People records against a real PostgreSQL.
 *
 * Three things here need the database.
 *
 * The tenant scope: `User::getById(int $id)` ran `SELECT * FROM users WHERE
 * id = ?` with no tenant clause, and `single.php` handed it whatever the
 * request body said — so any id returned any organisation's record. The second
 * tenant seeded here is what proves this one cannot.
 *
 * The management cycle: two people managing each other is representable in one
 * update each, and the recursive query that walks the chain is the thing that
 * would hang.
 *
 * And the counts, which are derived rather than stored — the legacy ran four
 * separate queries per person, inside the loop that built the list.
 */
#[CoversClass(DbalPersonRepository::class)]
#[CoversClass(Person::class)]
final class DbalPersonRepositoryTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalPersonRepository $people;

    private string $tenantA;
    private string $tenantB;
    private string $engineering;
    private string $sales;
    private string $learnerRole;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable — start it with: pnpm up');
        }

        $this->people = new DbalPersonRepository(self::$connection);

        $this->tenantA = $this->createTenant('ppl-a');
        $this->tenantB = $this->createTenant('ppl-b');

        $this->engineering = $this->lookup($this->tenantA, 'department', 'Engineering');
        $this->sales       = $this->lookup($this->tenantA, 'department', 'Sales');

        $this->learnerRole = (string) self::$connection->fetchOne(
            "SELECT id FROM roles WHERE key = 'learner' AND tenant_id IS NULL LIMIT 1",
        );
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

            self::$connection->executeStatement(
                'DELETE FROM user_roles WHERE user_id IN (SELECT id FROM users WHERE tenant_id = :id)',
                ['id' => $tenantId],
            );
            self::$connection->executeStatement('DELETE FROM users WHERE tenant_id = :id', ['id' => $tenantId]);
            self::$connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $tenantId]);
        }
    }

    // --- the round trip ---------------------------------------------------

    #[Test]
    public function a_person_round_trips_with_their_roles(): void
    {
        $created = $this->create('ada@ppl.test', 'Ada', 'Lovelace');

        $read = $this->people->find($this->tenantA, (string) $created->id);

        self::assertNotNull($read);
        self::assertSame('ada@ppl.test', $read->email);
        self::assertSame('Ada Lovelace', $read->fullName());
        self::assertSame([$this->learnerRole], $read->roleIds);
        self::assertSame('en', $read->locale);
    }

    #[Test]
    public function creating_without_a_password_leaves_no_hash(): void
    {
        // An invited account sets its own, which is the only arrangement where
        // an administrator does not learn somebody's password.
        $created = $this->create('invited@ppl.test', 'In', 'Vited');

        self::assertNull($this->people->passwordHashFor($this->tenantA, (string) $created->id));
    }

    #[Test]
    public function another_tenant_cannot_read_the_record(): void
    {
        $created = $this->create('ada@ppl.test', 'Ada', 'Lovelace');

        self::assertNull($this->people->find($this->tenantB, (string) $created->id));
    }

    #[Test]
    public function an_update_replaces_the_roles_it_is_given(): void
    {
        $created = $this->create('ada@ppl.test', 'Ada', 'Lovelace');

        $instructor = (string) self::$connection?->fetchOne(
            "SELECT id FROM roles WHERE key = 'instructor' AND tenant_id IS NULL LIMIT 1",
        );

        $updated = $this->people->update(new Person(
            id: $created->id,
            tenantId: $this->tenantA,
            email: $created->email,
            firstName: 'Ada',
            lastName: 'Lovelace',
            status: UserStatus::Active,
            roleIds: [$instructor],
        ));

        self::assertSame([$instructor], $updated->roleIds);
    }

    #[Test]
    public function setting_a_password_activates_an_invited_account(): void
    {
        // Somebody who has set a password is no longer waiting to.
        $created = $this->create('invited@ppl.test', 'In', 'Vited');

        self::assertSame(UserStatus::Invited, $created->status);

        $this->people->setPasswordHash($this->tenantA, (string) $created->id, 'hashed');

        self::assertSame(UserStatus::Active, $this->people->find($this->tenantA, (string) $created->id)?->status);
    }

    #[Test]
    public function setting_a_password_does_not_revive_a_suspended_account(): void
    {
        $created = $this->create('ada@ppl.test', 'Ada', 'Lovelace', UserStatus::Suspended);

        $this->people->setPasswordHash($this->tenantA, (string) $created->id, 'hashed');

        self::assertSame(
            UserStatus::Suspended,
            $this->people->find($this->tenantA, (string) $created->id)?->status,
        );
    }

    #[Test]
    public function deleting_is_soft(): void
    {
        // Enrolments, attempts and certificates all point here, and a
        // certificate is a record of something that happened to a named person.
        $created = $this->create('ada@ppl.test', 'Ada', 'Lovelace');

        self::assertTrue($this->people->softDelete($this->tenantA, (string) $created->id));
        self::assertNull($this->people->find($this->tenantA, (string) $created->id));

        self::assertSame(1, (int) self::$connection?->fetchOne(
            'SELECT COUNT(*) FROM users WHERE id = :id',
            ['id' => $created->id],
        ));
    }

    // --- addresses --------------------------------------------------------

    #[Test]
    public function an_address_is_taken_only_within_its_own_tenant(): void
    {
        // The same person can hold accounts in two organisations, which the
        // unique index allows and this has to match.
        $this->create('ada@ppl.test', 'Ada', 'Lovelace');

        self::assertTrue($this->people->emailExists($this->tenantA, 'ada@ppl.test'));
        self::assertFalse($this->people->emailExists($this->tenantB, 'ada@ppl.test'));
    }

    #[Test]
    public function editing_a_record_does_not_collide_with_itself(): void
    {
        $created = $this->create('ada@ppl.test', 'Ada', 'Lovelace');

        self::assertFalse(
            $this->people->emailExists($this->tenantA, 'ada@ppl.test', (string) $created->id),
        );
    }

    #[Test]
    public function a_deleted_account_frees_its_address(): void
    {
        $created = $this->create('ada@ppl.test', 'Ada', 'Lovelace');
        $this->people->softDelete($this->tenantA, (string) $created->id);

        self::assertFalse($this->people->emailExists($this->tenantA, 'ada@ppl.test'));
    }

    // --- filtering --------------------------------------------------------

    #[Test]
    public function the_list_is_scoped_to_the_tenant(): void
    {
        $this->create('ada@ppl.test', 'Ada', 'Lovelace');
        $this->create('grace@ppl.test', 'Grace', 'Hopper');
        $this->create('stranger@ppl.test', 'Stranger', 'Danger', tenantId: $this->tenantB);

        self::assertSame(2, $this->people->paginate($this->tenantA, PersonFilters::forTable(), 1, 50)['total']);
        self::assertSame(1, $this->people->paginate($this->tenantB, PersonFilters::forTable(), 1, 50)['total']);
    }

    #[Test]
    public function searching_matches_a_surname_as_well_as_a_forename(): void
    {
        // The legacy searched `first_name` only, so looking somebody up by
        // their surname found nothing.
        $this->create('ada@ppl.test', 'Ada', 'Lovelace');
        $this->create('grace@ppl.test', 'Grace', 'Hopper');

        $filters = PersonFilters::fromQuery(['search' => 'Hopper']);

        $result = $this->people->paginate($this->tenantA, $filters, 1, 50);

        self::assertSame(1, $result['total']);
        self::assertSame('Grace Hopper', $result['items'][0]->fullName());
    }

    #[Test]
    public function searching_matches_an_address(): void
    {
        $this->create('ada@ppl.test', 'Ada', 'Lovelace');

        $result = $this->people->paginate(
            $this->tenantA,
            PersonFilters::fromQuery(['search' => 'ada@']),
            1,
            50,
        );

        self::assertSame(1, $result['total']);
    }

    #[Test]
    public function the_list_filters_by_department(): void
    {
        $this->create('ada@ppl.test', 'Ada', 'Lovelace', departmentId: $this->engineering);
        $this->create('sam@ppl.test', 'Sam', 'Seller', departmentId: $this->sales);

        $result = $this->people->paginate(
            $this->tenantA,
            PersonFilters::fromQuery(['departmentId' => $this->engineering]),
            1,
            50,
        );

        self::assertSame(1, $result['total']);
    }

    #[Test]
    public function the_list_filters_by_role(): void
    {
        $this->create('ada@ppl.test', 'Ada', 'Lovelace');

        $result = $this->people->paginate(
            $this->tenantA,
            PersonFilters::forTable()->withOnly($this->learnerRole),
            1,
            50,
        );

        self::assertSame(1, $result['total']);
    }

    #[Test]
    public function placement_names_are_resolved_in_the_same_query(): void
    {
        // Five lookups per row, called inside the loop, is what this replaces.
        $this->create('ada@ppl.test', 'Ada', 'Lovelace', departmentId: $this->engineering);

        $result = $this->people->paginate($this->tenantA, PersonFilters::forTable(), 1, 50);

        self::assertSame('Engineering', $result['items'][0]->placement?->department);
    }

    #[Test]
    public function placement_is_absent_rather_than_empty_when_not_asked_for(): void
    {
        // A list that does not join the lookups must not claim everybody is
        // unassigned.
        $this->create('ada@ppl.test', 'Ada', 'Lovelace', departmentId: $this->engineering);

        $result = $this->people->paginate(
            $this->tenantA,
            PersonFilters::fromQuery([]),
            1,
            50,
        );

        self::assertNull($result['items'][0]->placement);
    }

    // --- activity ---------------------------------------------------------

    #[Test]
    public function activity_is_counted_from_the_enrolments(): void
    {
        $person = $this->create('ada@ppl.test', 'Ada', 'Lovelace');
        $course = $this->createCourse();

        $this->enrol((string) $person->id, $course, 'completed', 3600);
        $this->enrol((string) $person->id, $this->createCourse('Second'), 'in_progress', 900);

        $read = $this->people->find($this->tenantA, (string) $person->id);

        self::assertSame(2, $read?->activity?->enrolments);
        self::assertSame(1, $read->activity->completed);
        self::assertSame(1, $read->activity->inProgress);
        self::assertSame(4500, $read->activity->secondsSpent);
        self::assertSame(50.0, $read->activity->completionRate());
    }

    // --- the management chain ---------------------------------------------

    #[Test]
    public function a_straight_reporting_line_is_allowed(): void
    {
        $manager = $this->create('manager@ppl.test', 'Man', 'Ager');
        $report  = $this->create('report@ppl.test', 'Re', 'Port');

        self::assertFalse($this->people->wouldFormManagementCycle(
            $this->tenantA,
            (string) $report->id,
            (string) $manager->id,
        ));
    }

    #[Test]
    public function reporting_to_yourself_is_a_cycle(): void
    {
        $person = $this->create('ada@ppl.test', 'Ada', 'Lovelace');

        self::assertTrue($this->people->wouldFormManagementCycle(
            $this->tenantA,
            (string) $person->id,
            (string) $person->id,
        ));
    }

    #[Test]
    public function reporting_to_your_own_report_is_a_cycle(): void
    {
        // Two people managing each other is representable in one update each,
        // and every reporting query then walks in circles.
        $manager = $this->create('manager@ppl.test', 'Man', 'Ager');
        $report  = $this->create('report@ppl.test', 'Re', 'Port', managerId: (string) $manager->id);

        self::assertTrue($this->people->wouldFormManagementCycle(
            $this->tenantA,
            (string) $manager->id,
            (string) $report->id,
        ));
    }

    #[Test]
    public function a_longer_loop_is_caught_too(): void
    {
        $top    = $this->create('top@ppl.test', 'Top', 'Boss');
        $middle = $this->create('middle@ppl.test', 'Mid', 'Dle', managerId: (string) $top->id);
        $bottom = $this->create('bottom@ppl.test', 'Bot', 'Tom', managerId: (string) $middle->id);

        self::assertTrue($this->people->wouldFormManagementCycle(
            $this->tenantA,
            (string) $top->id,
            (string) $bottom->id,
        ));
    }

    // --- statistics -------------------------------------------------------

    #[Test]
    public function statistics_count_only_this_tenant(): void
    {
        $this->create('ada@ppl.test', 'Ada', 'Lovelace', UserStatus::Active);
        $this->create('invited@ppl.test', 'In', 'Vited');
        $this->create('stranger@ppl.test', 'Stranger', 'Danger', tenantId: $this->tenantB);

        $stats = $this->people->statistics($this->tenantA);

        self::assertSame(2, $stats['total']);
        self::assertSame(1, $stats['active']);
        self::assertSame(1, $stats['invited']);
        self::assertSame(2, $stats['neverSignedIn']);
    }

    #[Test]
    public function a_deleted_person_leaves_the_statistics(): void
    {
        $created = $this->create('ada@ppl.test', 'Ada', 'Lovelace');
        $this->people->softDelete($this->tenantA, (string) $created->id);

        self::assertSame(0, $this->people->statistics($this->tenantA)['total']);
    }

    // --- helpers ----------------------------------------------------------

    private function create(
        string $email,
        string $firstName,
        string $lastName,
        UserStatus $status = UserStatus::Invited,
        ?string $departmentId = null,
        ?string $managerId = null,
        ?string $tenantId = null,
    ): Person {
        return $this->people->create(
            new Person(
                id: null,
                tenantId: $tenantId ?? $this->tenantA,
                email: $email,
                firstName: $firstName,
                lastName: $lastName,
                status: $status,
                managerId: $managerId,
                departmentId: $departmentId,
                roleIds: [$this->learnerRole],
            ),
            null,
            null,
        );
    }

    private function createTenant(string $prefix): string
    {
        $id = Uuid::uuid4()->toString();

        self::$connection?->executeStatement(
            "INSERT INTO tenants (id, name, code, status) VALUES (:id, :name, :code, 'active')",
            ['id' => $id, 'name' => 'Test ' . $prefix, 'code' => $prefix . '-' . substr($id, 0, 8)],
        );

        return $id;
    }

    private function lookup(string $tenantId, string $kind, string $name): string
    {
        $id = Uuid::uuid4()->toString();

        self::$connection?->executeStatement(
            'INSERT INTO lookups (id, tenant_id, kind, code, name) VALUES (:id, :t, :k, :c, :n)',
            [
                'id' => $id,
                't'  => $tenantId,
                'k'  => $kind,
                'c'  => strtolower($name) . '-' . substr($id, 0, 6),
                'n'  => $name,
            ],
        );

        return $id;
    }

    private function createCourse(string $title = 'Fire Safety'): string
    {
        $id = Uuid::uuid4()->toString();

        self::$connection?->executeStatement(
            "INSERT INTO courses (id, tenant_id, title, status) VALUES (:id, :t, :ti, 'published')",
            ['id' => $id, 't' => $this->tenantA, 'ti' => $title],
        );

        return $id;
    }

    private function enrol(string $userId, string $courseId, string $status, int $seconds): void
    {
        self::$connection?->executeStatement(
            // A completed enrolment must carry a date and 100% — the check
            // constraint ties the three together so they cannot drift.
            "INSERT INTO enrollments (tenant_id, course_id, user_id, status, source,
                                      time_spent_seconds, progress_percentage,
                                      started_at, completed_at, last_activity_at)
             VALUES (:t, :c, :u, :s, 'direct', :sec,
                     CASE WHEN :s3 = 'completed' THEN 100 ELSE 40 END,
                     now() - interval '10 days',
                     CASE WHEN :s2 = 'completed' THEN now() - interval '1 day' ELSE NULL END,
                     now())",
            [
                't'   => $this->tenantA,
                'c'   => $courseId,
                'u'   => $userId,
                's'   => $status,
                's2'  => $status,
                's3'  => $status,
                'sec' => $seconds,
            ],
        );
    }

    private static function connect(): ?Connection
    {
        try {
            $connection = DriverManager::getConnection([
                'driver'   => 'pdo_pgsql',
                'host'     => $_ENV['DB_HOST'] ?? '127.0.0.1',
                'port'     => (int) ($_ENV['DB_PORT'] ?? 5442),
                'dbname'   => $_ENV['DB_NAME'] ?? 'central_lms',
                'user'     => $_ENV['DB_USER'] ?? 'central_lms',
                'password' => $_ENV['DB_PASSWORD'] ?? 'central_lms',
            ]);

            $connection->executeQuery('SELECT 1');

            return $connection;
        } catch (\Throwable) {
            return null;
        }
    }
}
