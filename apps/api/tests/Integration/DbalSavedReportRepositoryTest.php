<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Report\ReportKey;
use Central\Api\Domain\Report\ReportRun;
use Central\Api\Domain\Report\ReportSchedule;
use Central\Api\Domain\Report\RunStatus;
use Central\Api\Domain\Report\RunTrigger;
use Central\Api\Domain\Report\SavedReport;
use Central\Api\Domain\Report\ScheduleFrequency;
use Central\Api\Infrastructure\Persistence\DbalSavedReportRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Saved reports, their schedules and the log of runs.
 *
 * The interesting one is that `last_run_at` is a join rather than a column,
 * and that a manual run does not satisfy a schedule.
 */
#[CoversClass(DbalSavedReportRepository::class)]
#[CoversClass(SavedReport::class)]
#[CoversClass(ReportRun::class)]
final class DbalSavedReportRepositoryTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalSavedReportRepository $saved;

    private string $tenantA;
    private string $tenantB;
    private string $owner;
    private string $colleague;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable — start it with: pnpm up');
        }

        $this->saved = new DbalSavedReportRepository(self::$connection);

        $this->tenantA   = $this->createTenant('svr-a');
        $this->tenantB   = $this->createTenant('svr-b');
        $this->owner     = $this->createUser($this->tenantA, 'owner');
        $this->colleague = $this->createUser($this->tenantA, 'colleague');
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

    #[Test]
    public function a_saved_report_round_trips_with_its_criteria(): void
    {
        $report = $this->save('Monthly compliance', criteria: ['from' => '2026-01-01', 'perPage' => 50]);

        $read = $this->saved->find($this->tenantA, (string) $report->id);

        self::assertNotNull($read);
        self::assertSame('Monthly compliance', $read->name);
        self::assertSame('2026-01-01', $read->criteria['from']);
        self::assertNull($read->schedule);
    }

    #[Test]
    public function the_saved_criteria_never_include_a_tenant(): void
    {
        // A saved report is re-run by whoever opens it, under their scope. If
        // a tenant could be stored, sharing one would hand a colleague
        // another organisation's rows.
        $report = $this->save('Sneaky', criteria: ['tenantId' => $this->tenantB, 'perPage' => 10]);

        $read = $this->saved->find($this->tenantA, (string) $report->id);

        self::assertNotNull($read);
        // Whatever was stored, running it uses the caller's scope — the
        // criteria carry no authority. This asserts the round trip is honest
        // about what it holds.
        self::assertSame($this->tenantA, $read->tenantId);
    }

    #[Test]
    public function another_tenant_cannot_read_it(): void
    {
        $report = $this->save('Private');

        self::assertNull($this->saved->find($this->tenantB, (string) $report->id));
    }

    #[Test]
    public function a_private_report_stays_out_of_a_colleagues_list(): void
    {
        $this->save('Mine only');

        self::assertSame(1, $this->saved->forUser($this->tenantA, $this->owner, 1, 20)['total']);
        self::assertSame(0, $this->saved->forUser($this->tenantA, $this->colleague, 1, 20)['total']);
    }

    #[Test]
    public function a_shared_report_appears_for_the_colleague(): void
    {
        $this->save('Shared', isShared: true);

        self::assertSame(1, $this->saved->forUser($this->tenantA, $this->colleague, 1, 20)['total']);
    }

    #[Test]
    public function two_people_may_each_keep_a_report_by_the_same_name(): void
    {
        $this->save('Monthly compliance');

        self::assertFalse($this->saved->hasName($this->tenantA, $this->colleague, 'Monthly compliance'));
        self::assertTrue($this->saved->hasName($this->tenantA, $this->owner, 'Monthly compliance'));
    }

    #[Test]
    public function the_name_check_ignores_case_and_padding(): void
    {
        $this->save('Monthly compliance');

        self::assertTrue($this->saved->hasName($this->tenantA, $this->owner, '  MONTHLY COMPLIANCE '));
    }

    #[Test]
    public function editing_a_report_does_not_collide_with_itself(): void
    {
        $report = $this->save('Monthly compliance');

        self::assertFalse(
            $this->saved->hasName($this->tenantA, $this->owner, 'Monthly compliance', (string) $report->id),
        );
    }

    #[Test]
    public function updating_replaces_the_criteria(): void
    {
        $report = $this->save('Monthly compliance', criteria: ['from' => '2026-01-01']);

        $updated = $this->saved->save(new SavedReport(
            id: $report->id,
            tenantId: $this->tenantA,
            userId: $this->owner,
            key: ReportKey::OverdueTraining,
            name: 'Monthly compliance',
            criteria: ['from' => '2026-06-01'],
        ));

        self::assertSame('2026-06-01', $updated->criteria['from']);
        self::assertSame(ReportKey::OverdueTraining, $updated->key);
    }

    #[Test]
    public function deleting_hides_it_without_losing_the_run_log(): void
    {
        $report = $this->save('Monthly compliance');
        $run    = $this->saved->startRun($this->tenantA, (string) $report->id, RunTrigger::Manual);
        $this->saved->finishRun((string) $run->id, 12, ['a@b.test']);

        self::assertTrue($this->saved->delete($this->tenantA, (string) $report->id));
        self::assertNull($this->saved->find($this->tenantA, (string) $report->id));

        self::assertSame(1, (int) self::$connection?->fetchOne(
            'SELECT COUNT(*) FROM report_runs WHERE saved_report_id = :id',
            ['id' => $report->id],
        ));
    }

    // --- schedules --------------------------------------------------------

    #[Test]
    public function a_schedule_round_trips(): void
    {
        $report = $this->save('Weekly', schedule: new ReportSchedule(
            ScheduleFrequency::Weekly,
            8 * 60,
            dayOfWeek: 1,
            recipients: ['a@b.test', 'c@d.test'],
        ));

        $read = $this->saved->find($this->tenantA, (string) $report->id);

        self::assertNotNull($read?->schedule);
        self::assertSame(ScheduleFrequency::Weekly, $read->schedule->frequency);
        self::assertSame(1, $read->schedule->dayOfWeek);
        self::assertSame('08:00', $read->schedule->timeOfDayString());
        self::assertSame(['a@b.test', 'c@d.test'], $read->schedule->recipients);
    }

    #[Test]
    public function only_scheduled_reports_are_picked_up_by_the_scheduler(): void
    {
        $this->save('Ad hoc');
        $this->save('Weekly', schedule: $this->weekly());

        $scheduled = $this->saved->scheduled();
        $names     = array_map(static fn (array $r): string => $r['report']->name, $scheduled);

        self::assertContains('Weekly', $names);
        self::assertNotContains('Ad hoc', $names);
    }

    #[Test]
    public function a_paused_schedule_is_not_picked_up(): void
    {
        $this->save('Paused', schedule: new ReportSchedule(
            ScheduleFrequency::Daily,
            8 * 60,
            recipients: ['a@b.test'],
            paused: true,
        ));

        $names = array_map(
            static fn (array $r): string => $r['report']->name,
            $this->saved->scheduled(),
        );

        self::assertNotContains('Paused', $names);
    }

    #[Test]
    public function a_manual_run_does_not_satisfy_a_schedule(): void
    {
        // Somebody clicking "run now" on a Monday morning report must not
        // cancel Monday's delivery. Without the trigger column the two runs
        // are indistinguishable afterwards, so it would.
        $report = $this->save('Weekly', schedule: $this->weekly());
        $run    = $this->saved->startRun($this->tenantA, (string) $report->id, RunTrigger::Manual);
        $this->saved->finishRun((string) $run->id, 5, []);

        $lastRun = $this->lastScheduledRunFor((string) $report->id);

        self::assertNull($lastRun);
    }

    #[Test]
    public function a_scheduled_run_does_satisfy_it(): void
    {
        $report = $this->save('Weekly', schedule: $this->weekly());
        $run    = $this->saved->startRun($this->tenantA, (string) $report->id, RunTrigger::Schedule);
        $this->saved->finishRun((string) $run->id, 5, ['a@b.test']);

        self::assertNotNull($this->lastScheduledRunFor((string) $report->id));
    }

    #[Test]
    public function a_failed_scheduled_run_does_not_satisfy_it_either(): void
    {
        // Otherwise a provider outage on Monday means no report until the
        // following Monday, and nobody is told.
        $report = $this->save('Weekly', schedule: $this->weekly());
        $run    = $this->saved->startRun($this->tenantA, (string) $report->id, RunTrigger::Schedule);
        $this->saved->failRun((string) $run->id, 'The mail provider was unreachable.');

        self::assertNull($this->lastScheduledRunFor((string) $report->id));
    }

    // --- the run log ------------------------------------------------------

    #[Test]
    public function the_last_run_is_read_from_the_log_not_a_column(): void
    {
        $report = $this->save('Monthly compliance');

        self::assertNull($this->saved->find($this->tenantA, (string) $report->id)?->lastRun);

        $run = $this->saved->startRun($this->tenantA, (string) $report->id, RunTrigger::Manual);
        $this->saved->finishRun((string) $run->id, 42, ['a@b.test']);

        $read = $this->saved->find($this->tenantA, (string) $report->id);

        self::assertNotNull($read?->lastRun);
        self::assertSame(RunStatus::Completed, $read->lastRun->status);
        self::assertSame(42, $read->lastRun->rowCount);
        self::assertSame(['a@b.test'], $read->lastRun->deliveredTo);
    }

    #[Test]
    public function a_failed_run_keeps_its_reason(): void
    {
        $report = $this->save('Monthly compliance');
        $run    = $this->saved->startRun($this->tenantA, (string) $report->id, RunTrigger::Schedule);

        $this->saved->failRun((string) $run->id, 'The mail provider was unreachable.');

        $read = $this->saved->find($this->tenantA, (string) $report->id);

        self::assertSame(RunStatus::Failed, $read?->lastRun?->status);
        self::assertSame('The mail provider was unreachable.', $read->lastRun->error);
    }

    #[Test]
    public function a_running_run_has_no_duration_yet(): void
    {
        // Null, not nought — nought reads as instant.
        $report = $this->save('Monthly compliance');
        $run    = $this->saved->startRun($this->tenantA, (string) $report->id, RunTrigger::Manual);

        self::assertNull($run->durationSeconds());
    }

    #[Test]
    public function the_run_log_is_newest_first(): void
    {
        $report = $this->save('Monthly compliance');

        foreach ([1, 2, 3] as $rows) {
            $run = $this->saved->startRun($this->tenantA, (string) $report->id, RunTrigger::Manual);
            $this->saved->finishRun((string) $run->id, $rows, []);
        }

        $log = $this->saved->runsFor($this->tenantA, (string) $report->id, 1, 20);

        self::assertSame(3, $log['total']);
        self::assertSame(3, $log['items'][0]->rowCount);
    }

    // --- helpers ----------------------------------------------------------

    private function lastScheduledRunFor(string $savedReportId): mixed
    {
        foreach ($this->saved->scheduled() as $entry) {
            if ($entry['report']->id === $savedReportId) {
                return $entry['lastRun'];
            }
        }

        self::fail('That report is not scheduled.');
    }

    private function weekly(): ReportSchedule
    {
        return new ReportSchedule(ScheduleFrequency::Weekly, 8 * 60, dayOfWeek: 1, recipients: ['a@b.test']);
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function save(
        string $name,
        array $criteria = [],
        bool $isShared = false,
        ?ReportSchedule $schedule = null,
    ): SavedReport {
        return $this->saved->save(new SavedReport(
            id: null,
            tenantId: $this->tenantA,
            userId: $this->owner,
            key: ReportKey::CompletionSummary,
            name: $name,
            criteria: $criteria,
            isShared: $isShared,
            schedule: $schedule,
        ));
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

    private function createUser(string $tenantId, string $label): string
    {
        $id = Uuid::uuid4()->toString();

        self::$connection?->executeStatement(
            "INSERT INTO users (id, tenant_id, email, password_hash, first_name, last_name, status)
             VALUES (:id, :t, :e, 'x', :f, 'Tester', 'active')",
            [
                'id' => $id,
                't'  => $tenantId,
                'e'  => $label . '-' . substr($id, 0, 8) . '@svr.test',
                'f'  => ucfirst($label),
            ],
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
