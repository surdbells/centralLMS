<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Infrastructure\Persistence\DbalAssessmentAssignmentRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Assessment assignments against a real PostgreSQL.
 *
 * The substance is the target: an assignment names who must sit an assessment,
 * and its name is resolved on read — from the users table for a person, from
 * the lookups for a department, team or branch — so a rename follows through
 * rather than going stale.
 */
#[CoversClass(DbalAssessmentAssignmentRepository::class)]
final class DbalAssessmentAssignmentRepositoryTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalAssessmentAssignmentRepository $assignments;

    private string $tenant;
    private string $assessment;
    private string $ada;
    private string $department;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable — start it with: pnpm up');
        }

        $this->assignments = new DbalAssessmentAssignmentRepository(self::$connection);

        $this->tenant = $this->createTenant('asg');
        $this->ada = $this->createUser($this->tenant, 'Ada');
        $this->assessment = $this->createAssessment($this->tenant, 'Fire Safety');
        $this->department = $this->createLookup($this->tenant, 'department', 'Operations');
    }

    protected function tearDown(): void
    {
        if (self::$connection === null || !isset($this->tenant)) {
            return;
        }

        foreach (['assessment_assignments', 'assessments', 'lookups', 'users'] as $table) {
            self::$connection->executeStatement(
                sprintf('DELETE FROM %s WHERE tenant_id = :id', $table),
                ['id' => $this->tenant],
            );
        }
        self::$connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $this->tenant]);
    }

    #[Test]
    public function an_assignment_resolves_its_target_name_on_read(): void
    {
        $this->assignments->create($this->tenant, $this->assessment, 'user', $this->ada, null, null);
        $this->assignments->create($this->tenant, $this->assessment, 'department', $this->department, null, null);
        $this->assignments->create($this->tenant, $this->assessment, 'all', null, null, null);

        $listed = $this->assignments->forAssessment($this->tenant, $this->assessment);
        $byType = [];
        foreach ($listed as $a) {
            $byType[$a->assignmentType] = $a;
        }

        self::assertCount(3, $listed);
        self::assertSame('Ada Tester', $byType['user']->targetName);
        self::assertSame('Operations', $byType['department']->targetName);
        // "Everyone" is supplied by the value object, not stored.
        self::assertNull($byType['all']->targetName);
        self::assertSame('Everyone', $byType['all']->toArray()['targetName']);
    }

    #[Test]
    public function the_same_target_cannot_be_assigned_twice(): void
    {
        $this->assignments->create($this->tenant, $this->assessment, 'user', $this->ada, null, null);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->assignments->create($this->tenant, $this->assessment, 'user', $this->ada, null, null);
    }

    #[Test]
    public function everyone_cannot_be_assigned_twice(): void
    {
        $this->assignments->create($this->tenant, $this->assessment, 'all', null, null, null);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->assignments->create($this->tenant, $this->assessment, 'all', null, null, null);
    }

    #[Test]
    public function an_assignment_can_be_removed(): void
    {
        $created = $this->assignments->create($this->tenant, $this->assessment, 'user', $this->ada, null, null);

        self::assertTrue($this->assignments->delete($this->tenant, $this->assessment, $created->id));
        self::assertSame([], $this->assignments->forAssessment($this->tenant, $this->assessment));
        // A second delete finds nothing.
        self::assertFalse($this->assignments->delete($this->tenant, $this->assessment, $created->id));
    }

    #[Test]
    public function the_targets_list_offers_people_and_the_groups(): void
    {
        $targets = $this->assignments->targets($this->tenant);

        self::assertContains('Ada Tester', array_column($targets['users'], 'name'));
        self::assertContains('Operations', array_column($targets['departments'], 'name'));
    }

    // --- helpers ----------------------------------------------------------

    private function createTenant(string $prefix): string
    {
        $id = Uuid::uuid4()->toString();
        self::$connection?->executeStatement(
            "INSERT INTO tenants (id, name, code, status) VALUES (:id, :name, :code, 'active')",
            ['id' => $id, 'name' => 'Test ' . $prefix, 'code' => $prefix . '-' . substr($id, 0, 8)],
        );

        return $id;
    }

    private function createUser(string $tenantId, string $first): string
    {
        $id = Uuid::uuid4()->toString();
        self::$connection?->executeStatement(
            "INSERT INTO users (id, tenant_id, email, password_hash, first_name, last_name, status)
             VALUES (:id, :t, :e, 'x', :f, 'Tester', 'active')",
            ['id' => $id, 't' => $tenantId, 'e' => strtolower($first) . '-' . substr($id, 0, 8) . '@asg.test', 'f' => $first],
        );

        return $id;
    }

    private function createAssessment(string $tenantId, string $title): string
    {
        $id = Uuid::uuid4()->toString();
        self::$connection?->executeStatement(
            "INSERT INTO assessments (id, tenant_id, title, kind, status) VALUES (:id, :t, :ti, 'quiz', 'draft')",
            ['id' => $id, 't' => $tenantId, 'ti' => $title],
        );

        return $id;
    }

    private function createLookup(string $tenantId, string $kind, string $name): string
    {
        $id = Uuid::uuid4()->toString();
        self::$connection?->executeStatement(
            "INSERT INTO lookups (id, tenant_id, kind, code, name, is_active)
             VALUES (:id, :t, :k, :c, :n, TRUE)",
            ['id' => $id, 't' => $tenantId, 'k' => $kind, 'c' => strtolower($name), 'n' => $name],
        );

        return $id;
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
