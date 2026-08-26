<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Dashboard\DashboardPeriod;
use Central\Api\Domain\Report\ReportCriteria;
use Central\Api\Domain\Report\ReportScope;
use Central\Api\Infrastructure\Persistence\DbalDashboardRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * The parts of a dashboard no report already answers.
 *
 * The interesting cases are the boundaries: due tomorrow against due last week,
 * a day with activity against a day without, and a line manager's view against
 * the organisation's.
 */
#[CoversClass(DbalDashboardRepository::class)]
final class DbalDashboardRepositoryTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalDashboardRepository $dashboards;

    private string $tenantA;
    private string $tenantB;
    private string $manager;
    private string $ada;
    private string $sam;
    private string $fire;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable — start it with: pnpm up');
        }

        $this->dashboards = new DbalDashboardRepository(self::$connection);

        $this->tenantA = $this->createTenant('dash-a');
        $this->tenantB = $this->createTenant('dash-b');

        $this->manager = $this->createUser($this->tenantA, 'Manager', null);
        $this->ada     = $this->createUser($this->tenantA, 'Ada', $this->manager);
        $this->sam     = $this->createUser($this->tenantA, 'Sam', null);

        $this->fire = $this->createCourse('Fire Safety', 'published');
        // A draft, so it must never turn up in a recommendation.
        $this->createCourse('GDPR Basics', 'draft');
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

            foreach (['lesson_progress', 'enrollments', 'lessons', 'course_modules', 'courses', 'users'] as $table) {
                self::$connection->executeStatement(
                    sprintf('DELETE FROM %s WHERE tenant_id = :id', $table),
                    ['id' => $tenantId],
                );
            }

            self::$connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $tenantId]);
        }
    }

    // --- the overview -----------------------------------------------------

    #[Test]
    public function the_supplement_counts_what_the_executive_report_does_not(): void
    {
        $this->enrol($this->ada, $this->fire, 'in_progress', 40.0, null);
        $this->enrol($this->sam, $this->fire, 'not_started', 0.0, null);
        $this->enrol($this->manager, $this->fire, 'completed', 100.0, 80.0);

        $supplement = $this->dashboards->overviewSupplement($this->criteria(), $this->period());

        self::assertSame(1, $supplement['inProgress']);
        self::assertSame(1, $supplement['notStarted']);
        self::assertSame(1, $supplement['publishedCourses']);
        self::assertSame(80.0, $supplement['averageScore']);
    }

    #[Test]
    public function the_previous_window_is_counted_separately(): void
    {
        // Enrolled today and enrolled six weeks ago, with a 30-day window:
        // one falls inside it and one falls in the window before.
        $this->enrol($this->ada, $this->fire, 'not_started', 0.0, null, enrolledDaysAgo: 1);
        $this->enrol($this->sam, $this->fire, 'not_started', 0.0, null, enrolledDaysAgo: 40);

        $supplement = $this->dashboards->overviewSupplement($this->criteria(), $this->period());

        self::assertSame(1, $supplement['enrolmentsThisPeriod']);
        self::assertSame(1, $supplement['enrolmentsPreviousPeriod']);
        // 1 against 1 is no change — and the legacy's January comparison
        // against month zero would have made this +100%.
        self::assertSame(0.0, DashboardPeriod::growth(1, 1));
    }

    #[Test]
    public function a_line_manager_sees_their_own_reports_only(): void
    {
        $this->enrol($this->ada, $this->fire, 'in_progress', 40.0, null);
        $this->enrol($this->sam, $this->fire, 'in_progress', 40.0, null);

        $wide   = $this->dashboards->overviewSupplement($this->criteria(), $this->period());
        $narrow = $this->dashboards->overviewSupplement(
            $this->criteria(scope: $this->makeScope($this->tenantA, $this->manager, false)),
            $this->period(),
        );

        self::assertSame(2, $wide['inProgress']);
        // Ada reports to them; Sam does not.
        self::assertSame(1, $narrow['inProgress']);
    }

    // --- deadlines --------------------------------------------------------

    #[Test]
    public function a_deadline_in_the_future_is_upcoming_and_one_in_the_past_is_not(): void
    {
        // The two lists are different questions, and putting an overdue
        // enrolment in the upcoming one is how a dashboard says "3 days
        // remaining" about something a fortnight late.
        $this->enrol($this->ada, $this->fire, 'not_started', 0.0, null, dueInDays: 5);
        $this->enrol($this->sam, $this->fire, 'not_started', 0.0, null, dueInDays: -14);

        $upcoming = $this->dashboards->upcomingDeadlines($this->criteria(), 30, 10);

        self::assertCount(1, $upcoming);
        self::assertSame($this->ada, $upcoming[0]['userId']);
        self::assertSame(5, $upcoming[0]['daysRemaining']);
        self::assertSame('warning', $upcoming[0]['urgency']);
    }

    #[Test]
    public function the_horizon_is_respected(): void
    {
        $this->enrol($this->ada, $this->fire, 'not_started', 0.0, null, dueInDays: 45);

        self::assertSame([], $this->dashboards->upcomingDeadlines($this->criteria(), 30, 10));
        self::assertCount(1, $this->dashboards->upcomingDeadlines($this->criteria(), 60, 10));
    }

    #[Test]
    public function something_already_finished_has_no_deadline_left(): void
    {
        $this->enrol($this->ada, $this->fire, 'completed', 100.0, 90.0, dueInDays: 5);

        self::assertSame([], $this->dashboards->upcomingDeadlines($this->criteria(), 30, 10));
    }

    #[Test]
    public function urgency_is_graded_by_how_close_it_is(): void
    {
        $this->enrol($this->ada, $this->fire, 'not_started', 0.0, null, dueInDays: 2);
        $this->enrol($this->sam, $this->fire, 'not_started', 0.0, null, dueInDays: 20);

        $urgencies = array_column($this->dashboards->upcomingDeadlines($this->criteria(), 30, 10), 'urgency');

        self::assertSame(['critical', 'normal'], $urgencies);
    }

    // --- feeds ------------------------------------------------------------

    #[Test]
    public function the_activity_feed_merges_enrolments_and_completions_by_time(): void
    {
        // One list ordered across the sources, not the top of each list
        // concatenated — which is what makes "the fifteen most recent things"
        // actually the fifteen most recent.
        $this->enrol($this->ada, $this->fire, 'completed', 100.0, 90.0, enrolledDaysAgo: 10, completedDaysAgo: 1);
        $this->enrol($this->sam, $this->fire, 'not_started', 0.0, null, enrolledDaysAgo: 5);

        $feed = array_column($this->dashboards->recentActivity($this->criteria(), 10), 'kind');

        // Ada's completion yesterday, Sam's enrolment five days ago, Ada's own
        // enrolment ten days ago — interleaved, not one list after another.
        self::assertSame(['completed', 'enrolled', 'enrolled'], $feed);
    }

    #[Test]
    public function recent_completions_name_the_learner_and_the_course(): void
    {
        $this->enrol($this->ada, $this->fire, 'completed', 100.0, 91.5);

        $completions = $this->dashboards->recentCompletions($this->criteria(), 10);

        self::assertCount(1, $completions);
        self::assertSame('Ada Tester', $completions[0]['learnerName']);
        self::assertSame('Fire Safety', $completions[0]['courseTitle']);
        self::assertSame(91.5, $completions[0]['finalScore']);
        self::assertFalse($completions[0]['hasCertificate']);
    }

    #[Test]
    public function another_tenant_appears_in_none_of_it(): void
    {
        $this->enrol($this->ada, $this->fire, 'completed', 100.0, 90.0, dueInDays: 5);

        $other = $this->criteria(scope: $this->makeScope($this->tenantB, $this->manager, true));

        self::assertSame([], $this->dashboards->recentCompletions($other, 10));
        self::assertSame([], $this->dashboards->recentActivity($other, 10));
        self::assertSame(0, $this->dashboards->overviewSupplement($other, $this->period())['publishedCourses']);
    }

    // --- mixes ------------------------------------------------------------

    #[Test]
    public function courses_with_no_category_are_still_counted(): void
    {
        // Grouping on a join that drops the nulls is how a pie chart adds up
        // to less than the number of courses.
        $mix = $this->dashboards->courseMix($this->criteria());

        self::assertSame('Uncategorised', $mix['byCategory'][0]['label']);
        self::assertSame(2, $mix['byCategory'][0]['courses']);
        self::assertSame('Unspecified', $mix['byLevel'][0]['label']);
    }

    #[Test]
    public function people_are_grouped_by_account_status(): void
    {
        $mix = $this->dashboards->learnerMix($this->criteria(), $this->period());

        self::assertSame([['status' => 'active', 'people' => 3]], $mix['byStatus']);
        self::assertSame(3, $mix['joinedThisPeriod']);
    }

    // --- the learner's own view -------------------------------------------

    #[Test]
    public function a_learners_totals_cover_only_their_own_rows(): void
    {
        $this->enrol($this->ada, $this->fire, 'completed', 100.0, 88.0);
        $this->enrol($this->sam, $this->fire, 'in_progress', 30.0, null);

        $totals = $this->dashboards->learnerTotals($this->tenantA, $this->ada);

        self::assertSame(1, $totals['enrolled']);
        self::assertSame(1, $totals['completed']);
        self::assertSame(0, $totals['inProgress']);
        self::assertSame(88.0, $totals['averageScore']);
    }

    #[Test]
    public function overdue_work_is_counted_for_the_learner(): void
    {
        $this->enrol($this->ada, $this->fire, 'not_started', 0.0, null, dueInDays: -3);

        self::assertSame(1, $this->dashboards->learnerTotals($this->tenantA, $this->ada)['overdue']);
    }

    #[Test]
    public function the_days_somebody_was_active_come_from_what_they_did(): void
    {
        // Not from a counter kept beside them. `user_streaks` held eight rows
        // maintained by whatever wrote `learning_sessions`, and that table has
        // three rows for the whole platform.
        $enrollment = $this->enrol($this->ada, $this->fire, 'in_progress', 50.0, null);

        // Two lessons, because there is one progress row per lesson: the
        // resolution of this is "the last day you touched each lesson", which
        // is the finest the schema carries. `learning_sessions` was meant to
        // be finer and holds three rows for the whole platform.
        $this->recordLessonActivity($enrollment, $this->createLesson($this->fire), $this->ada, 2);
        $this->recordLessonActivity($enrollment, $this->createLesson($this->fire), $this->ada, 0);

        $days = $this->dashboards->activeDays($this->tenantA, $this->ada, 30);

        self::assertCount(2, $days);
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $days[0]);
    }

    #[Test]
    public function being_enrolled_on_something_is_not_a_day_spent_learning(): void
    {
        // Otherwise an administrator assigning a course on a Tuesday hands
        // somebody a streak for work they did not do.
        $this->enrol($this->ada, $this->fire, 'not_started', 0.0, null);

        self::assertSame([], $this->dashboards->activeDays($this->tenantA, $this->ada, 30));
    }

    #[Test]
    public function the_daily_chart_includes_the_quiet_days(): void
    {
        $enrollment = $this->enrol($this->ada, $this->fire, 'in_progress', 50.0, null);
        $lesson     = $this->createLesson($this->fire);

        $this->recordLessonActivity($enrollment, $lesson, $this->ada, 0, seconds: 900);

        $chart = $this->dashboards->dailyActivity($this->tenantA, $this->ada, 7);

        self::assertCount(7, $chart);
        self::assertSame(0, $chart[0]['seconds']);
        self::assertSame(900, $chart[6]['seconds']);
    }

    #[Test]
    public function a_course_somebody_already_holds_is_not_recommended(): void
    {
        $this->enrol($this->ada, $this->fire, 'in_progress', 10.0, null);

        // Fire Safety is the only published course, and Ada is on it.
        self::assertSame([], $this->dashboards->recommendedCourses($this->tenantA, $this->ada, 5));
        // Sam is not.
        self::assertCount(1, $this->dashboards->recommendedCourses($this->tenantA, $this->sam, 5));
    }

    #[Test]
    public function an_unpublished_course_is_never_recommended(): void
    {
        // GDPR Basics is a draft. Recommending it sends somebody to a course
        // they cannot open.
        $titles = array_column($this->dashboards->recommendedCourses($this->tenantA, $this->ada, 5), 'title');

        self::assertSame(['Fire Safety'], $titles);
    }

    // --- helpers ----------------------------------------------------------

    private function criteria(?ReportScope $scope = null): ReportCriteria
    {
        $violations = [];

        $criteria = ReportCriteria::fromInput(
            $scope ?? $this->makeScope($this->tenantA, $this->manager, true),
            ['perPage' => 100],
            $violations,
        );

        self::assertNotNull($criteria, (string) json_encode($violations));

        return $criteria;
    }

    private function period(): DashboardPeriod
    {
        return DashboardPeriod::of(30, new \DateTimeImmutable());
    }

    /**
     * A scope, built through reflection because there is deliberately no way
     * to construct one from anything but a token.
     */
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

    private function enrol(
        string $userId,
        string $courseId,
        string $status,
        float $progress,
        ?float $score,
        ?int $dueInDays = null,
        int $enrolledDaysAgo = 2,
        int $completedDaysAgo = 1,
    ): string {
        return (string) self::$connection?->fetchOne(
            "INSERT INTO enrollments
                 (tenant_id, course_id, user_id, status, source, progress_percentage, final_score,
                  due_date, time_spent_seconds, started_at, completed_at, last_activity_at, enrolled_at)
             VALUES (:t, :c, :u, :s, 'direct', :p, :score,
                     CASE WHEN :due::int IS NULL THEN NULL
                          ELSE CURRENT_DATE + make_interval(days => :due::int) END,
                     0,
                     CASE WHEN :s2 = 'not_started' THEN NULL ELSE now() - interval '1 day' END,
                     CASE WHEN :s3 = 'completed' THEN now() - make_interval(days => :done::int) ELSE NULL END,
                     now() - interval '1 hour',
                     now() - make_interval(days => :ago2::int))
             RETURNING id",
            [
                't'     => $this->tenantA,
                'c'     => $courseId,
                'u'     => $userId,
                's'     => $status,
                's2'    => $status,
                's3'    => $status,
                'p'     => $progress,
                'score' => $score,
                'due'   => $dueInDays,
                'done'  => $completedDaysAgo,
                'ago2'  => $enrolledDaysAgo,
            ],
        );
    }

    private function recordLessonActivity(
        string $enrollmentId,
        string $lessonId,
        string $userId,
        int $daysAgo,
        int $seconds = 60,
    ): void {
        self::$connection?->executeStatement(
            "INSERT INTO lesson_progress
                 (tenant_id, enrollment_id, user_id, lesson_id, status, progress_percentage,
                  time_spent_seconds, completed_at, last_activity_at)
             VALUES (:t, :e, :u, :l, 'completed', 100, :sec,
                     now() - make_interval(days => :ago::int),
                     now() - make_interval(days => :ago2::int))",
            [
                't'    => $this->tenantA,
                'e'    => $enrollmentId,
                'u'    => $userId,
                'l'    => $lessonId,
                'sec'  => $seconds,
                'ago'  => $daysAgo,
                'ago2' => $daysAgo,
            ],
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

    private function createUser(string $tenantId, string $first, ?string $managerId): string
    {
        $id = Uuid::uuid4()->toString();

        self::$connection?->executeStatement(
            "INSERT INTO users (id, tenant_id, email, password_hash, first_name, last_name, status, manager_id)
             VALUES (:id, :t, :e, 'x', :f, 'Tester', 'active', :m)",
            [
                'id' => $id,
                't'  => $tenantId,
                'e'  => strtolower($first) . '-' . substr($id, 0, 8) . '@dash.test',
                'f'  => $first,
                'm'  => $managerId,
            ],
        );

        return $id;
    }

    private function createCourse(string $title, string $status): string
    {
        $id = Uuid::uuid4()->toString();

        self::$connection?->executeStatement(
            'INSERT INTO courses (id, tenant_id, title, status) VALUES (:id, :t, :ti, :s)',
            ['id' => $id, 't' => $this->tenantA, 'ti' => $title, 's' => $status],
        );

        return $id;
    }

    private function createLesson(string $courseId): string
    {
        $moduleId = (string) self::$connection?->fetchOne(
            'INSERT INTO course_modules (tenant_id, course_id, title, sort_order)
             VALUES (:t, :c, :ti, 1) RETURNING id',
            ['t' => $this->tenantA, 'c' => $courseId, 'ti' => 'Module 1'],
        );

        return (string) self::$connection?->fetchOne(
            "INSERT INTO lessons (tenant_id, course_id, module_id, title, content_type, sort_order)
             VALUES (:t, :c, :m, 'Lesson 1', 'text', 1)
             RETURNING id",
            ['t' => $this->tenantA, 'c' => $courseId, 'm' => $moduleId],
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
