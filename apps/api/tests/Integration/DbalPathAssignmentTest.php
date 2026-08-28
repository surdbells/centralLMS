<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Audience\AudienceTarget;
use Central\Api\Domain\LearningPath\AssignmentStatus;
use Central\Api\Domain\LearningPath\PathAssignment;
use Central\Api\Infrastructure\Persistence\DbalAudienceResolver;
use Central\Api\Infrastructure\Persistence\DbalLearningPathRepository;
use Central\Api\Infrastructure\Persistence\DbalPathAssignmentRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Standing assignment rules against real PostgreSQL.
 *
 * The thing worth proving is that a rule outlives the moment it was made:
 * somebody who joins the department afterwards is enrolled by it too. That,
 * and that running it twice does not enrol anybody twice — which it has to
 * survive, because the sweep runs it again every hour.
 *
 * Skipped when the database is unreachable, so a checkout without the Docker
 * stack still gets a green suite.
 */
#[CoversClass(DbalPathAssignmentRepository::class)]
final class DbalPathAssignmentTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalPathAssignmentRepository $rules;

    private string $tenantId;
    private string $pathId;
    private string $operations;
    private string $finance;
    private string $admin;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable — start it with: pnpm up');
        }

        $this->rules = new DbalPathAssignmentRepository(
            self::$connection,
            new DbalAudienceResolver(self::$connection),
            new DbalLearningPathRepository(self::$connection),
        );

        $this->tenantId = $this->createTenant('asg');
        $this->pathId = $this->createPath('Fire safety');
        $this->operations = $this->createLookup('department', 'Operations');
        $this->finance = $this->createLookup('department', 'Finance');
        $this->admin = $this->createUser('admin', null);
    }

    protected function tearDown(): void
    {
        if (self::$connection === null || !isset($this->tenantId)) {
            return;
        }

        // In order: what references, before what is referenced.
        $sweep = [
            'DELETE FROM learning_path_enrollments WHERE tenant_id = :id',
            'DELETE FROM enrollments WHERE tenant_id = :id',
            'DELETE FROM learning_path_assignments WHERE tenant_id = :id',
            'DELETE FROM learning_paths WHERE tenant_id = :id',
            'UPDATE users SET department_id = NULL WHERE tenant_id = :id',
            'DELETE FROM users WHERE tenant_id = :id',
            'DELETE FROM lookups WHERE tenant_id = :id',
            'DELETE FROM tenants WHERE id = :id',
        ];

        foreach ($sweep as $sql) {
            self::$connection->executeStatement($sql, ['id' => $this->tenantId]);
        }
    }

    // --- what makes it a rule -------------------------------------------------

    #[Test]
    public function a_rule_enrols_everybody_it_covers_when_it_is_applied(): void
    {
        $this->createUser('ada', $this->operations);
        $this->createUser('bob', $this->operations);
        $this->createUser('cleo', $this->finance);

        $rule = $this->rules->save($this->rule($this->operations));

        self::assertSame(2, $this->rules->apply($this->tenantId, $rule->id));
        self::assertSame(2, $this->enrolmentCount());
    }

    #[Test]
    public function somebody_who_joins_the_department_later_is_enrolled_too(): void
    {
        $this->createUser('ada', $this->operations);
        $rule = $this->rules->save($this->rule($this->operations));

        self::assertSame(1, $this->rules->apply($this->tenantId, $rule->id));

        // The whole reason a rule exists rather than a list.
        $this->createUser('newcomer', $this->operations);

        self::assertSame(1, $this->rules->apply($this->tenantId, $rule->id));
        self::assertSame(2, $this->enrolmentCount());
    }

    #[Test]
    public function applying_a_rule_twice_enrols_nobody_twice(): void
    {
        $this->createUser('ada', $this->operations);
        $rule = $this->rules->save($this->rule($this->operations));

        // It has to survive this: the sweep runs it again every hour.
        self::assertSame(1, $this->rules->apply($this->tenantId, $rule->id));
        self::assertSame(0, $this->rules->apply($this->tenantId, $rule->id));
        self::assertSame(1, $this->enrolmentCount());
    }

    #[Test]
    public function a_paused_rule_enrols_nobody_new(): void
    {
        $this->createUser('ada', $this->operations);
        $rule = $this->rules->save($this->rule($this->operations));
        $this->rules->apply($this->tenantId, $rule->id);

        $this->rules->setStatus($this->tenantId, $rule->id, AssignmentStatus::Paused);
        $this->createUser('newcomer', $this->operations);

        self::assertSame(0, $this->rules->apply($this->tenantId, $rule->id));
        self::assertSame(1, $this->enrolmentCount());
    }

    #[Test]
    public function pausing_a_rule_leaves_the_people_it_already_enrolled_alone(): void
    {
        $this->createUser('ada', $this->operations);
        $rule = $this->rules->save($this->rule($this->operations));
        $this->rules->apply($this->tenantId, $rule->id);

        $this->rules->setStatus($this->tenantId, $rule->id, AssignmentStatus::Cancelled);

        // Taking a path away from somebody halfway through it is a different
        // decision, and not one anybody made by cancelling a rule.
        self::assertSame(1, $this->enrolmentCount());
    }

    #[Test]
    public function a_rule_leaves_people_who_have_left_alone(): void
    {
        $this->createUser('ada', $this->operations);
        $gone = $this->createUser('gone', $this->operations);

        $this->db()->executeStatement(
            "UPDATE users SET status = 'suspended' WHERE id = :id",
            ['id' => $gone],
        );

        $rule = $this->rules->save($this->rule($this->operations));

        // A rule that keeps enrolling leavers creates obligations nobody can
        // discharge.
        self::assertSame(1, $this->rules->apply($this->tenantId, $rule->id));
    }

    // --- due dates ------------------------------------------------------------

    #[Test]
    public function an_offset_becomes_a_date_counted_from_the_enrolment(): void
    {
        $this->createUser('ada', $this->operations);

        $rule = $this->rules->save(new PathAssignment(
            id: Uuid::uuid4()->toString(),
            tenantId: $this->tenantId,
            pathId: $this->pathId,
            targetType: AudienceTarget::Department,
            targetCriteria: ['departmentIds' => [$this->operations]],
            dueDaysFromAssignment: 30,
            assignedBy: $this->admin,
        ));

        $this->rules->apply($this->tenantId, $rule->id);

        $due = (string) $this->db()->fetchOne(
            'SELECT due_date FROM learning_path_enrollments WHERE tenant_id = :id',
            ['id' => $this->tenantId],
        );

        self::assertSame(
            (new \DateTimeImmutable())->modify('+30 days')->format('Y-m-d'),
            $due,
        );
    }

    #[Test]
    public function a_rule_with_no_deadline_sets_no_due_date(): void
    {
        $this->createUser('ada', $this->operations);
        $rule = $this->rules->save($this->rule($this->operations));
        $this->rules->apply($this->tenantId, $rule->id);

        // No deadline is not a deadline of today, and must not become one.
        self::assertNull($this->db()->fetchOne(
            'SELECT due_date FROM learning_path_enrollments WHERE tenant_id = :id',
            ['id' => $this->tenantId],
        ));
    }

    #[Test]
    public function the_database_refuses_a_fixed_date_and_an_offset_together(): void
    {
        // Two answers to "when is this due" is one too many.
        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);

        $this->db()->executeStatement(
            "INSERT INTO learning_path_assignments
                 (tenant_id, path_id, target_type, target_criteria, due_date, due_days_from_assignment)
             VALUES (:tenant, :path, 'all', '{}'::jsonb, CURRENT_DATE, 30)",
            ['tenant' => $this->tenantId, 'path' => $this->pathId],
        );
    }

    #[Test]
    public function the_database_refuses_a_department_rule_that_names_no_department(): void
    {
        // Without this it covers the whole organisation on its first sweep.
        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);

        $this->db()->executeStatement(
            "INSERT INTO learning_path_assignments (tenant_id, path_id, target_type)
             VALUES (:tenant, :path, 'department')",
            ['tenant' => $this->tenantId, 'path' => $this->pathId],
        );
    }

    // --- what the rule has done -----------------------------------------------

    #[Test]
    public function the_enrolments_a_rule_made_are_traceable_to_it(): void
    {
        $this->createUser('ada', $this->operations);
        $this->createUser('bob', $this->finance);

        $rule = $this->rules->save($this->rule($this->operations));
        $this->rules->apply($this->tenantId, $rule->id);

        // Somebody put on the same path by hand is not this rule's doing.
        $paths = new DbalLearningPathRepository($this->db());
        $paths->enrol(
            $this->tenantId,
            $this->pathId,
            [$this->userNamed('bob')],
            'direct',
            $this->admin,
            null,
            false,
        );

        self::assertSame(2, $this->enrolmentCount());

        $found = $this->rules->find($this->tenantId, $rule->id);
        self::assertNotNull($found);
        self::assertSame(1, $found->enrolledCount);
        self::assertSame(1, $this->rules->statistics($this->tenantId, $rule->id)['enrolled']);
    }

    #[Test]
    public function statistics_say_nothing_rather_than_nought_when_nobody_is_enrolled(): void
    {
        $rule = $this->rules->save($this->rule($this->operations));
        $stats = $this->rules->statistics($this->tenantId, $rule->id);

        self::assertSame(0, $stats['enrolled']);
        // No completion rate is not a completion rate of nought.
        self::assertNull($stats['completionRate']);
    }

    #[Test]
    public function a_preview_says_who_is_covered_and_who_is_already_on_it(): void
    {
        $ada = $this->createUser('ada', $this->operations);
        $this->createUser('bob', $this->operations);

        $paths = new DbalLearningPathRepository($this->db());
        $paths->enrol($this->tenantId, $this->pathId, [$ada], 'self', null, null, false);

        $rule = $this->rules->save($this->rule($this->operations));

        self::assertSame(
            ['covered' => 2, 'alreadyEnrolled' => 1, 'wouldEnrol' => 1],
            $this->rules->preview($this->tenantId, $rule->id),
        );
    }

    #[Test]
    public function the_sweep_stamps_a_rule_even_when_it_enrols_nobody(): void
    {
        $rule = $this->rules->save($this->rule($this->operations));

        self::assertNull($rule->lastAppliedAt);
        self::assertSame(0, $this->rules->apply($this->tenantId, $rule->id));

        // "Looked and found nobody" and "never ran" are different states, and
        // the screen shows which.
        $found = $this->rules->find($this->tenantId, $rule->id);
        self::assertNotNull($found);
        self::assertNotNull($found->lastAppliedAt);
    }

    #[Test]
    public function pending_learners_are_the_ones_who_have_not_finished(): void
    {
        $ada = $this->createUser('ada', $this->operations);
        $this->createUser('bob', $this->operations);

        $rule = $this->rules->save($this->rule($this->operations));
        $this->rules->apply($this->tenantId, $rule->id);

        $this->db()->executeStatement(
            "UPDATE learning_path_enrollments SET status = 'completed', completed_at = NOW()
             WHERE user_id = :user",
            ['user' => $ada],
        );

        $pending = $this->rules->pendingLearners($this->tenantId, $rule->id);

        self::assertCount(1, $pending);
        self::assertStringContainsString('Bob', (string) $pending[0]['name']);
    }

    // --- tenants --------------------------------------------------------------

    #[Test]
    public function rules_do_not_cross_tenants(): void
    {
        $rule = $this->rules->save($this->rule($this->operations));
        $other = Uuid::uuid4()->toString();

        self::assertNull($this->rules->find($other, $rule->id));
        self::assertSame([], $this->rules->forPath($other, $this->pathId));
        self::assertSame(0, $this->rules->apply($other, $rule->id));
    }

    // --- helpers --------------------------------------------------------------

    private function rule(string $departmentId): PathAssignment
    {
        return new PathAssignment(
            id: Uuid::uuid4()->toString(),
            tenantId: $this->tenantId,
            pathId: $this->pathId,
            targetType: AudienceTarget::Department,
            targetCriteria: ['departmentIds' => [$departmentId]],
            assignedBy: $this->admin,
        );
    }

    private function enrolmentCount(): int
    {
        return (int) $this->db()->fetchOne(
            'SELECT COUNT(*) FROM learning_path_enrollments
             WHERE tenant_id = :id AND deleted_at IS NULL',
            ['id' => $this->tenantId],
        );
    }

    private function userNamed(string $who): string
    {
        return (string) $this->db()->fetchOne(
            'SELECT id FROM users WHERE tenant_id = :tenant AND first_name = :name',
            ['tenant' => $this->tenantId, 'name' => ucfirst($who)],
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

    private function createLookup(string $kind, string $name): string
    {
        return (string) $this->db()->fetchOne(
            'INSERT INTO lookups (tenant_id, kind, name, code) VALUES (:tenant, :kind, :name, :code)
             RETURNING id',
            [
                'tenant' => $this->tenantId,
                'kind'   => $kind,
                'name'   => $name,
                'code'   => strtolower($name) . '-' . substr(Uuid::uuid4()->toString(), 0, 6),
            ],
        );
    }

    private function createUser(string $who, ?string $departmentId): string
    {
        return (string) $this->db()->fetchOne(
            "INSERT INTO users (tenant_id, email, first_name, last_name, status, department_id)
             VALUES (:tenant, :email, :first, 'Tester', 'active', :dept) RETURNING id",
            [
                'tenant' => $this->tenantId,
                'email'  => $who . '-' . substr(Uuid::uuid4()->toString(), 0, 8) . '@asg.test',
                'first'  => ucfirst($who),
                'dept'   => $departmentId,
            ],
        );
    }

    private function createPath(string $title): string
    {
        return (string) $this->db()->fetchOne(
            'INSERT INTO learning_paths (tenant_id, title, slug) VALUES (:tenant, :title, :slug)
             RETURNING id',
            [
                'tenant' => $this->tenantId,
                'title'  => $title,
                'slug'   => 'path-' . substr(Uuid::uuid4()->toString(), 0, 8),
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
