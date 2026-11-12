<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Authz\Role;
use Central\Api\Domain\Report\CustomFilter;
use Central\Api\Domain\Report\CustomReportSpec;
use Central\Api\Domain\Report\CustomSource;
use Central\Api\Domain\Report\ReportCriteria;
use Central\Api\Domain\Report\ReportScope;
use Central\Api\Domain\Report\SavedCustomReport;
use Central\Api\Infrastructure\Persistence\DbalCustomReportRepository;
use Central\Api\Infrastructure\Persistence\DbalSavedCustomReportRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * The custom report builder against a real schema.
 *
 * Every source, with every column, every grouping and every filter, has to
 * run: a mistyped column is a 500 for whoever picks it, and only PostgreSQL
 * will say so. Then the two rules every report keeps — the tenant and the
 * caller's row scope — are checked against a second organisation and a team
 * lead.
 */
#[CoversClass(DbalCustomReportRepository::class)]
#[CoversClass(DbalSavedCustomReportRepository::class)]
final class DbalCustomReportRepositoryTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalCustomReportRepository $reports;

    private string $tenantA;
    private string $tenantB;
    private string $manager;
    private string $ada;
    private string $sam;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable — start it with: pnpm up');
        }

        $this->reports = new DbalCustomReportRepository(self::$connection);

        $this->tenantA = $this->createTenant('crb-a');
        $this->tenantB = $this->createTenant('crb-b');

        $engineering = $this->lookup($this->tenantA, 'department', 'Engineering');
        $sales       = $this->lookup($this->tenantA, 'department', 'Sales');

        $this->manager = $this->createUser($this->tenantA, 'Manager', $engineering, null);
        $this->ada     = $this->createUser($this->tenantA, 'Ada', $engineering, $this->manager);
        $this->sam     = $this->createUser($this->tenantA, 'Sam', $sales, null);

        $fire   = $this->createCourse($this->tenantA, 'Fire Safety', true);
        $python = $this->createCourse($this->tenantA, 'Python Basics', false);

        $this->enrol($this->tenantA, $this->ada, $fire, 'completed', 100, 90.0);
        $this->enrol($this->tenantA, $this->ada, $python, 'in_progress', 40, null);
        $this->enrol($this->tenantA, $this->sam, $fire, 'not_started', 0, null);
        $this->enrol($this->tenantA, $this->manager, $python, 'completed', 100, 70.0);

        $stranger = $this->createUser($this->tenantB, 'Stranger', null, null);
        $theirs   = $this->createCourse($this->tenantB, 'Their Course', true);
        $this->enrol($this->tenantB, $stranger, $theirs, 'completed', 100, 99.0);
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

            self::$connection->executeStatement('DELETE FROM custom_reports WHERE tenant_id = :id', ['id' => $tenantId]);
            self::$connection->executeStatement('DELETE FROM users WHERE tenant_id = :id', ['id' => $tenantId]);
            self::$connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $tenantId]);
        }
    }

    #[Test]
    public function every_source_runs_with_every_column_grouping_and_filter(): void
    {
        foreach ($this->reports->sources() as $source) {
            $columns = array_map(static fn ($f): string => $f->key, $source->fields);
            $filters = $this->everyFilter($source);

            foreach ($columns as $sortBy) {
                $result = $this->reports->run(
                    $this->spec($source, ['columns' => $columns, 'sortBy' => $sortBy, 'sortDirection' => 'desc']),
                    $this->criteria(),
                );

                self::assertGreaterThanOrEqual(count($result->rows), $result->total);
            }

            // With every filter at once: it must run, whatever it matches.
            $this->reports->run($this->spec($source, ['columns' => $columns, 'filters' => $filters]), $this->criteria());

            foreach (array_keys($source->groupings) as $grouping) {
                foreach ([null, CustomReportSpec::RECORDS, $grouping] as $sortBy) {
                    $result = $this->reports->run(
                        $this->spec($source, ['columns' => $columns, 'groupBy' => $grouping, 'sortBy' => $sortBy]),
                        $this->criteria(),
                    );

                    foreach ($result->rows as $row) {
                        self::assertArrayHasKey($grouping, $row);
                        self::assertIsInt($row[CustomReportSpec::RECORDS]);
                    }
                }

                $this->reports->run(
                    $this->spec($source, ['columns' => $columns, 'groupBy' => $grouping, 'filters' => $filters]),
                    $this->criteria(),
                );
            }
        }
    }

    #[Test]
    public function another_organisations_rows_never_appear(): void
    {
        $source = $this->source('enrollments');

        $result = $this->reports->run($this->spec($source, ['columns' => ['learner', 'course']]), $this->criteria());

        self::assertSame(4, $result->total);
        self::assertNotContains('Their Course', array_column($result->rows, 'course'));
    }

    #[Test]
    public function a_team_lead_sees_themselves_and_their_reports(): void
    {
        $source = $this->source('enrollments');

        $result = $this->reports->run(
            $this->spec($source, ['columns' => ['learner']]),
            $this->criteria($this->manager, [Permission::ReportView]),
        );

        // Ada's two and the manager's one; not Sam's.
        self::assertSame(3, $result->total);
        self::assertNotContains('Sam Tester', array_column($result->rows, 'learner'));
    }

    #[Test]
    public function a_grouped_report_counts_and_summarises(): void
    {
        $source = $this->source('enrollments');

        $result = $this->reports->run(
            $this->spec($source, ['columns' => ['progress'], 'groupBy' => 'department', 'sortBy' => 'department', 'sortDirection' => 'asc']),
            $this->criteria(),
        );

        self::assertSame(
            [
                ['department' => 'Engineering', 'records' => 3, 'progress' => 80.0],
                ['department' => 'Sales', 'records' => 1, 'progress' => 0.0],
            ],
            $result->rows,
        );
    }

    #[Test]
    public function a_filter_narrows(): void
    {
        $source = $this->source('enrollments');

        $result = $this->reports->run(
            $this->spec($source, ['columns' => ['learner'], 'filters' => ['status' => 'completed']]),
            $this->criteria(),
        );

        self::assertSame(2, $result->total);
    }

    #[Test]
    public function a_saved_custom_report_round_trips(): void
    {
        \assert(self::$connection !== null);

        $saved = new DbalSavedCustomReportRepository(self::$connection);
        $spec  = $this->spec($this->source('learners'), ['columns' => ['learner', 'enrolments'], 'groupBy' => 'department']);

        $violations = [];
        $report     = SavedCustomReport::fromInput($this->tenantA, $this->ada, null, $spec, ['name' => 'By dept'], $violations);
        self::assertNotNull($report);

        $stored = $saved->save($report);
        self::assertNotNull($stored->id);
        self::assertSame('learners', $stored->source);
        self::assertSame(['learner', 'enrolments'], $stored->config['columns']);
        self::assertTrue($saved->hasName($this->tenantA, $this->ada, 'BY DEPT'));
        self::assertFalse($saved->hasName($this->tenantA, $this->ada, 'By dept', $stored->id));
        self::assertCount(1, $saved->forUser($this->tenantA, $this->ada));
        self::assertCount(0, $saved->forUser($this->tenantA, $this->sam));
        self::assertNull($saved->find($this->tenantB, $stored->id), 'Not visible from another tenant.');

        $violations = [];
        $again      = CustomReportSpec::fromInput($this->source('learners'), $stored->config, $violations);
        self::assertNotNull($again);
        self::assertSame('department', $again->groupBy);

        self::assertTrue($saved->delete($this->tenantA, $stored->id));
        self::assertNull($saved->find($this->tenantA, $stored->id));
    }

    // --- helpers ------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private function everyFilter(CustomSource $source): array
    {
        $filters = [];

        foreach ($source->filters as $filter) {
            $filters[$filter->key] = match ($filter->type) {
                CustomFilter::DATE   => $filter->key === 'from' ? '2000-01-01' : '2100-01-01',
                CustomFilter::SELECT => $filter->options[0]['value'],
                default              => Uuid::uuid4()->toString(),
            };
        }

        return $filters;
    }

    private function source(string $key): CustomSource
    {
        $source = $this->reports->source($key);
        self::assertNotNull($source);

        return $source;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function spec(CustomSource $source, array $input): CustomReportSpec
    {
        $violations = [];
        $spec       = CustomReportSpec::fromInput($source, $input, $violations);

        self::assertSame([], $violations);
        self::assertNotNull($spec);

        return $spec;
    }

    /**
     * @param list<Permission> $permissions
     */
    private function criteria(
        ?string $viewer = null,
        array $permissions = [Permission::ReportView, Permission::ReportExport],
    ): ReportCriteria {
        $viewer ??= $this->manager;

        $scope = ReportScope::forCaller(
            new AuthenticatedUser(
                $viewer,
                $this->tenantA,
                'viewer@crb.test',
                [new Role('r', $this->tenantA, 'tester', 'Tester', $permissions)],
            ),
            $this->tenantA,
        );

        $violations = [];
        $criteria   = ReportCriteria::fromInput($scope, ['perPage' => 500], $violations);
        self::assertNotNull($criteria);

        return $criteria;
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
            ['id' => $id, 't' => $tenantId, 'k' => $kind, 'c' => strtolower($name) . '-' . substr($id, 0, 6), 'n' => $name],
        );

        return $id;
    }

    private function createUser(string $tenantId, string $first, ?string $departmentId, ?string $managerId): string
    {
        $id = Uuid::uuid4()->toString();

        self::$connection?->executeStatement(
            "INSERT INTO users (id, tenant_id, email, password_hash, first_name, last_name, status,
                                department_id, manager_id, last_login_at, login_count, created_at)
             VALUES (:id, :t, :e, 'x', :f, 'Tester', 'active', :d, :m, now(), 3, now() - interval '100 days')",
            [
                'id' => $id,
                't'  => $tenantId,
                'e'  => strtolower($first) . '-' . substr($id, 0, 8) . '@crb.test',
                'f'  => $first,
                'd'  => $departmentId,
                'm'  => $managerId,
            ],
        );

        return $id;
    }

    private function createCourse(string $tenantId, string $title, bool $mandatory): string
    {
        $id = Uuid::uuid4()->toString();

        self::$connection?->executeStatement(
            "INSERT INTO courses (id, tenant_id, title, status, is_mandatory, pass_percentage,
                                  duration_minutes, published_at)
             VALUES (:id, :t, :ti, 'published', :m, 70, 60, now() - interval '90 days')",
            ['id' => $id, 't' => $tenantId, 'ti' => $title, 'm' => $mandatory],
            ['m' => \Doctrine\DBAL\ParameterType::BOOLEAN],
        );

        return $id;
    }

    private function enrol(
        string $tenantId,
        string $userId,
        string $courseId,
        string $status,
        float $progress,
        ?float $score,
    ): void {
        self::$connection?->executeStatement(
            "INSERT INTO enrollments (tenant_id, course_id, user_id, status, source, progress_percentage,
                                      final_score, due_date, time_spent_seconds, started_at, completed_at,
                                      last_activity_at, enrolled_at)
             VALUES (:t, :c, :u, :s, 'direct', :p, :sc, now() - interval '1 day', 600,
                     CASE WHEN :s2 = 'not_started' THEN NULL ELSE now() - interval '40 days' END,
                     CASE WHEN :s3 = 'completed' THEN now() - interval '10 days' ELSE NULL END,
                     now() - interval '5 days',
                     now() - interval '60 days')",
            [
                't'  => $tenantId,
                'c'  => $courseId,
                'u'  => $userId,
                's'  => $status,
                's2' => $status,
                's3' => $status,
                'p'  => $progress,
                'sc' => $score,
            ],
        );
    }

    private static function connect(): ?Connection
    {
        $env = static fn (string $name, string $default): string
            => (string) ($_ENV[$name] ?? (getenv($name) !== false ? getenv($name) : $default));

        try {
            $connection = DriverManager::getConnection([
                'driver'   => 'pdo_pgsql',
                'host'     => $env('DB_HOST', '127.0.0.1'),
                'port'     => (int) $env('DB_PORT', '5442'),
                'dbname'   => $env('DB_NAME', 'central_lms'),
                'user'     => $env('DB_USER', 'central_lms'),
                'password' => $env('DB_PASSWORD', 'central_lms'),
            ]);

            $connection->executeQuery('SELECT 1');

            return $connection;
        } catch (\Throwable) {
            return null;
        }
    }
}
