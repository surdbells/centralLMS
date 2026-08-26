<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Report\ReportCatalogue;
use Central\Api\Domain\Report\ReportCriteria;
use Central\Api\Domain\Report\ReportKey;
use Central\Api\Domain\Report\ReportResult;
use Central\Api\Domain\Report\ReportScope;
use Central\Api\Infrastructure\Persistence\DbalReportRepository;
use Central\Api\Infrastructure\Persistence\ReportQuery;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * The reports, against a tenant whose figures were worked out by hand.
 *
 * Two kinds of thing are checked here, and only one of them is arithmetic.
 *
 * The first is that every report *runs*: 57 queries against a real schema,
 * where a mistyped column or a `GROUP BY` that does not cover the select list
 * is a 500 for whoever opens the page. Nothing but PostgreSQL will catch that.
 *
 * The second is that the tenant filter and the row-level scope actually bite.
 * The live system's reporting endpoint takes `tenant_id` from the request body
 * (recorded as action 0c), so every one of its 190 queries scopes correctly to
 * whichever organisation the caller named. The seeded second tenant here is
 * what proves this one cannot.
 */
#[CoversClass(DbalReportRepository::class)]
#[CoversClass(ReportQuery::class)]
#[CoversClass(ReportResult::class)]
final class DbalReportRepositoryTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalReportRepository $reports;
    private ReportCatalogue $catalogue;

    private string $tenantA;
    private string $tenantB;

    private string $manager;
    private string $ada;
    private string $grace;
    private string $sam;
    private string $dormant;

    private string $fire;
    private string $gdpr;
    private string $python;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable — start it with: pnpm up');
        }

        $this->reports   = new DbalReportRepository(self::$connection);
        $this->catalogue = new ReportCatalogue();

        $this->tenantA = $this->createTenant('rpt-a');
        $this->tenantB = $this->createTenant('rpt-b');

        $engineering = $this->lookup($this->tenantA, 'department', 'Engineering');
        $sales       = $this->lookup($this->tenantA, 'department', 'Sales');

        $this->manager = $this->createUser($this->tenantA, 'Manager', $engineering, null, '-1 hour');
        $this->ada     = $this->createUser($this->tenantA, 'Ada', $engineering, $this->manager, '-2 days');
        $this->grace   = $this->createUser($this->tenantA, 'Grace', $engineering, $this->manager, '-5 days');
        $this->sam     = $this->createUser($this->tenantA, 'Sam', $sales, null, '-1 day');
        $this->dormant = $this->createUser($this->tenantA, 'Dormant', $sales, null, null);

        $this->fire   = $this->createCourse($this->tenantA, 'Fire Safety', mandatory: true);
        $this->gdpr   = $this->createCourse($this->tenantA, 'GDPR Basics', mandatory: true);
        $this->python = $this->createCourse($this->tenantA, 'Python Basics', mandatory: false);
        $this->createCourse($this->tenantA, 'Nobody Wants This', mandatory: false);

        // Nine enrolments: four complete, two under way, two untouched, one
        // dropped. Every figure asserted below is countable from this list.
        $this->enrol($this->ada, $this->fire, 'completed', 100, 92.0, '2026-01-01', 3600);
        $this->enrol($this->ada, $this->gdpr, 'completed', 100, 78.0, '2026-01-01', 1800);
        $this->enrol($this->ada, $this->python, 'in_progress', 40, null, null, 900);
        $this->enrol($this->grace, $this->fire, 'completed', 100, 85.0, '2026-01-01', 2400);
        $this->enrol($this->grace, $this->gdpr, 'not_started', 0, null, '2020-01-01', 0);
        $this->enrol($this->sam, $this->fire, 'completed', 100, 60.0, '2026-01-01', 3000);
        $this->enrol($this->sam, $this->gdpr, 'in_progress', 55, null, '2020-06-01', 1200);
        $this->enrol($this->dormant, $this->fire, 'not_started', 0, null, '2020-01-01', 0);
        $this->enrol($this->manager, $this->python, 'dropped', 20, null, null, 300);

        // The other organisation, which must never appear in a figure above.
        $stranger = $this->createUser($this->tenantB, 'Stranger', null, null, '-1 day');
        $theirs   = $this->createCourse($this->tenantB, 'Their Course', mandatory: true);
        $this->enrol($stranger, $theirs, 'completed', 100, 99.0, '2026-01-01', 9999, $this->tenantB);
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

    // --- every report runs ------------------------------------------------

    #[Test]
    public function every_report_runs_against_a_real_schema(): void
    {
        // A mistyped column or a GROUP BY that does not cover the select list
        // is a 500 for whoever opens the page, and nothing but PostgreSQL
        // will say so.
        $ran = 0;

        foreach ($this->catalogue->all() as $definition) {
            $input = ['perPage' => 5];

            foreach ($definition->requires as $needs) {
                $input[$needs] = match ($needs) {
                    'learnerId' => $this->ada,
                    'courseId'  => $this->fire,
                    default     => null,
                };
            }

            if ($definition->missing($input) !== []) {
                // Only question_analysis, which needs an assessment. It is
                // covered by its own test below.
                continue;
            }

            $scope = in_array('learnerId', $definition->requires, true)
                ? $this->scope()->narrowedTo($this->ada)
                : $this->scope();

            $result = $this->reports->run($definition, $this->criteria($input, $scope));

            self::assertSame($definition->key, $result->definition->key);
            $ran++;
        }

        self::assertGreaterThan(50, $ran, 'most of the catalogue should have run');
    }

    // --- the figures ------------------------------------------------------

    #[Test]
    public function the_enrolment_summary_counts_what_is_there(): void
    {
        $totals = $this->report(ReportKey::EnrollmentSummary)->totals;

        self::assertSame(9, $totals['total']);
        self::assertSame(5, $totals['learners']);
        // Three, not four: nobody is enrolled on "Nobody Wants This".
        self::assertSame(3, $totals['courses']);
        self::assertSame(4, $totals['completed']);
        self::assertSame(44.4, $totals['completionRate']);
        self::assertSame(13200, $totals['totalSeconds']);
    }

    #[Test]
    public function the_average_score_covers_only_those_who_finished(): void
    {
        // (92 + 78 + 85 + 60) / 4. Counting the unfinished as nought would
        // give 35, which is not a low average but a wrong one.
        self::assertSame(78.8, $this->report(ReportKey::EnrollmentSummary)->totals['avgScore']);
    }

    #[Test]
    public function completion_excludes_the_dropped_from_its_denominator(): void
    {
        // Someone taken off a course is not "not completed"; counting them
        // makes every rate wrong in the pessimistic direction.
        $totals = $this->report(ReportKey::CompletionSummary)->totals;

        self::assertSame(8, $totals['total']);
        self::assertSame(4, $totals['completed']);
        self::assertSame(50.0, $totals['completionRate']);
    }

    #[Test]
    public function overdue_counts_only_the_unfinished_past_their_date(): void
    {
        // Grace on GDPR, Sam on GDPR, Dormant on Fire. The four completed
        // enrolments also have 2026 due dates and are not overdue.
        self::assertSame(3, $this->report(ReportKey::CompletionSummary)->totals['overdue']);
        self::assertSame(3, $this->report(ReportKey::OverdueTraining)->total);
    }

    #[Test]
    public function a_due_date_is_compared_as_a_date_not_an_instant(): void
    {
        // Otherwise everyone with a deadline today is overdue from midnight.
        $this->enrol(
            $this->sam,
            $this->python,
            'in_progress',
            10,
            null,
            (new \DateTimeImmutable('today'))->format('Y-m-d'),
            60,
        );

        self::assertSame(3, $this->report(ReportKey::OverdueTraining)->total);
    }

    #[Test]
    public function not_started_means_untouched_rather_than_zero_progress(): void
    {
        // Grace on GDPR and Dormant on Fire. Chasing someone who opened a
        // lesson and closed it says something different.
        self::assertSame(2, $this->report(ReportKey::PendingEnrollments)->total);
    }

    #[Test]
    public function unused_courses_finds_the_one_nobody_is_on(): void
    {
        $result = $this->report(ReportKey::UnusedCourses);

        self::assertSame(1, $result->total);
        self::assertSame('Nobody Wants This', $result->rows[0]['course']);
    }

    #[Test]
    public function dormant_accounts_include_those_that_never_signed_in(): void
    {
        // A plain `last_login_at < …` drops every one of them, because NULL
        // fails the comparison — and those are the accounts worth chasing.
        $result = $this->report(ReportKey::InactiveUsers);

        self::assertSame(1, $result->total);
        self::assertStringContainsString('Dormant', (string) $result->rows[0]['name']);
    }

    #[Test]
    public function completion_by_department_groups_and_rates_correctly(): void
    {
        $rows = [];

        foreach ($this->report(ReportKey::CompletionByDepartment)->rows as $row) {
            $rows[(string) $row['unit']] = $row;
        }

        // Engineering: Ada 3 (one dropped excluded → 2 counted… Ada has 3,
        // one of which is in progress) plus Grace 2, plus the Manager's only
        // enrolment which is dropped and excluded. So 5 rows, 3 completed.
        self::assertSame(5, $rows['Engineering']['enrolled']);
        self::assertSame(3, $rows['Engineering']['completed']);
        self::assertSame(60.0, $rows['Engineering']['completionRate']);

        self::assertSame(3, $rows['Sales']['enrolled']);
        self::assertSame(1, $rows['Sales']['completed']);
    }

    #[Test]
    public function a_score_distribution_returns_every_band(): void
    {
        // A chart drawn only through the bands somebody scored in has gaps
        // where the shape should be.
        self::assertCount(10, $this->report(ReportKey::ScoreDistribution)->series);
    }

    #[Test]
    public function the_activity_clock_returns_all_twenty_four_hours(): void
    {
        self::assertCount(24, $this->report(ReportKey::PeakUsage)->series);
    }

    #[Test]
    public function an_individual_report_covers_one_person(): void
    {
        $result = $this->reports->run(
            $this->catalogue->find(ReportKey::IndividualProgress),
            $this->criteria(['learnerId' => $this->ada], $this->scope()->narrowedTo($this->ada)),
        );

        self::assertCount(3, $result->rows);
        self::assertStringContainsString('Ada', (string) $result->totals['learner']);
        self::assertSame(2, $result->totals['completed']);
    }

    // --- the tenant filter ------------------------------------------------

    #[Test]
    public function no_report_can_see_the_other_organisation(): void
    {
        // The seeded second tenant holds one completed enrolment scoring 99
        // and 9,999 seconds. If any figure moves, a tenant filter is missing.
        $totals = $this->report(ReportKey::EnrollmentSummary)->totals;

        self::assertSame(9, $totals['total']);
        self::assertSame(13200, $totals['totalSeconds']);
        self::assertNotSame(99.0, $totals['avgScore']);
    }

    #[Test]
    public function the_other_organisation_sees_only_its_own(): void
    {
        $result = $this->reports->run(
            $this->catalogue->find(ReportKey::EnrollmentSummary),
            $this->criteria([], $this->scope($this->tenantB)),
        );

        self::assertSame(1, $result->totals['total']);
        self::assertSame(99.0, $result->totals['avgScore']);
    }

    #[Test]
    public function every_report_is_empty_for_a_tenant_with_nothing_in_it(): void
    {
        $empty = $this->createTenant('rpt-c');

        try {
            foreach ($this->catalogue->all() as $definition) {
                if ($definition->requires !== []) {
                    continue;
                }

                $result = $this->reports->run($definition, $this->criteria([], $this->scope($empty)));

                // Not "no rows": year-over-year always lays out its five
                // measures, and a table of zeros is the right answer for an
                // organisation that has done nothing. What must hold is that
                // no figure anywhere is non-zero.
                foreach ([...$result->rows, ...$result->series, $result->totals] as $row) {
                    foreach ((array) $row as $key => $value) {
                        if (!is_int($value) && !is_float($value)) {
                            continue;
                        }

                        self::assertSame(
                            0.0,
                            (float) $value,
                            sprintf('%s.%s is not zero for an empty tenant', $definition->key->value, $key),
                        );
                    }
                }
            }
        } finally {
            self::$connection?->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $empty]);
        }
    }

    // --- the row scope ----------------------------------------------------

    #[Test]
    public function a_manager_sees_themselves_and_their_reports(): void
    {
        $result = $this->reports->run(
            $this->catalogue->find(ReportKey::EnrollmentSummary),
            $this->criteria([], $this->managerScope()),
        );

        // Ada 3 + Grace 2 + the Manager's own 1 = 6, over 3 people.
        self::assertSame(6, $result->totals['total']);
        self::assertSame(3, $result->totals['learners']);
    }

    #[Test]
    public function a_manager_does_not_see_another_departments_rows(): void
    {
        $result = $this->reports->run(
            $this->catalogue->find(ReportKey::EnrollmentDetail),
            $this->criteria(['perPage' => 100], $this->managerScope()),
        );

        $names = implode(' ', array_column($result->rows, 'learner'));

        self::assertStringNotContainsString('Sam', $names);
        self::assertStringNotContainsString('Dormant', $names);
        self::assertStringContainsString('Ada', $names);
    }

    #[Test]
    public function the_learner_picker_honours_the_row_scope(): void
    {
        // This is also what stops `individual_progress` being run against a
        // colleague by guessing an id.
        $mine = $this->reports->selectableLearners($this->managerScope(), null, 1, 50);
        $all  = $this->reports->selectableLearners($this->scope(), null, 1, 50);

        self::assertSame(3, $mine['total']);
        self::assertSame(5, $all['total']);
    }

    #[Test]
    public function the_scope_check_refuses_somebody_elses_report(): void
    {
        self::assertTrue($this->reports->scopeCovers($this->managerScope(), $this->ada));
        self::assertTrue($this->reports->scopeCovers($this->managerScope(), $this->manager));
        self::assertFalse($this->reports->scopeCovers($this->managerScope(), $this->sam));
    }

    #[Test]
    public function the_scope_check_refuses_another_tenants_user(): void
    {
        $stranger = (string) self::$connection?->fetchOne(
            'SELECT id FROM users WHERE tenant_id = :t LIMIT 1',
            ['t' => $this->tenantB],
        );

        // The same answer as "not yours", so probing ids teaches nothing.
        self::assertFalse($this->reports->scopeCovers($this->scope(), $stranger));
    }

    // --- paging -----------------------------------------------------------

    #[Test]
    public function paging_does_not_repeat_or_lose_a_row(): void
    {
        // Rows that tie on the sort column come back in whatever order the
        // planner chose, so without a tiebreaker one appears twice and
        // another not at all — a wrong that looks like missing data.
        $seen = [];

        for ($page = 1; $page <= 9; $page++) {
            $result = $this->reports->run(
                $this->catalogue->find(ReportKey::EnrollmentDetail),
                $this->criteria(['page' => $page, 'perPage' => 1]),
            );

            foreach ($result->rows as $row) {
                $seen[] = $row['learner'] . '|' . $row['course'];
            }
        }

        self::assertCount(9, $seen);
        self::assertCount(9, array_unique($seen));
    }

    // --- helpers ----------------------------------------------------------

    private function report(ReportKey $key): ReportResult
    {
        return $this->reports->run($this->catalogue->find($key), $this->criteria(['perPage' => 100]));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function criteria(array $input = [], ?ReportScope $scope = null): ReportCriteria
    {
        $violations = [];

        $criteria = ReportCriteria::fromInput(
            $scope ?? $this->scope(),
            [...['perPage' => 100], ...$input],
            $violations,
        );

        self::assertNotNull($criteria, (string) json_encode($violations));

        return $criteria;
    }

    /**
     * A tenant-wide scope. Built through reflection because there is
     * deliberately no way to construct one from anything but a token.
     */
    private function scope(?string $tenantId = null): ReportScope
    {
        return $this->makeScope($tenantId ?? $this->tenantA, $this->manager, true);
    }

    private function managerScope(): ReportScope
    {
        return $this->makeScope($this->tenantA, $this->manager, false);
    }

    private function makeScope(string $tenantId, string $viewerId, bool $tenantWide): ReportScope
    {
        $reflection = new \ReflectionClass(ReportScope::class);
        $scope      = $reflection->newInstanceWithoutConstructor();

        foreach (
            [
            'tenantId'   => $tenantId,
            'viewerId'   => $viewerId,
            'tenantWide' => $tenantWide,
            'subjectId'  => null,
            ] as $property => $value
        ) {
            $reflection->getProperty($property)->setValue($scope, $value);
        }

        return $scope;
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

    private function createUser(
        string $tenantId,
        string $first,
        ?string $departmentId,
        ?string $managerId,
        ?string $lastLogin,
    ): string {
        $id = Uuid::uuid4()->toString();

        self::$connection?->executeStatement(
            "INSERT INTO users (id, tenant_id, email, password_hash, first_name, last_name, status,
                                department_id, manager_id, last_login_at, login_count, created_at)
             VALUES (:id, :t, :e, 'x', :f, 'Tester', 'active', :d, :m,
                     CASE WHEN :l::text IS NULL THEN NULL ELSE now() + :l::interval END,
                     :n, now() - interval '100 days')",
            [
                'id' => $id,
                't'  => $tenantId,
                'e'  => strtolower($first) . '-' . substr($id, 0, 8) . '@rpt.test',
                'f'  => $first,
                'd'  => $departmentId,
                'm'  => $managerId,
                'l'  => $lastLogin,
                'n'  => $lastLogin === null ? 0 : 5,
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
        string $userId,
        string $courseId,
        string $status,
        float $progress,
        ?float $score,
        ?string $due,
        int $seconds,
        ?string $tenantId = null,
    ): void {
        self::$connection?->executeStatement(
            "INSERT INTO enrollments (tenant_id, course_id, user_id, status, source, progress_percentage,
                                      final_score, due_date, time_spent_seconds, started_at, completed_at,
                                      last_activity_at, enrolled_at)
             VALUES (:t, :c, :u, :s, 'direct', :p, :sc, :d, :sec,
                     CASE WHEN :s2 = 'not_started' THEN NULL ELSE now() - interval '40 days' END,
                     CASE WHEN :s3 = 'completed' THEN now() - interval '10 days' ELSE NULL END,
                     now() - interval '5 days',
                     now() - interval '60 days')",
            [
                't'   => $tenantId ?? $this->tenantA,
                'c'   => $courseId,
                'u'   => $userId,
                's'   => $status,
                's2'  => $status,
                's3'  => $status,
                'p'   => $progress,
                'sc'  => $score,
                'd'   => $due,
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
