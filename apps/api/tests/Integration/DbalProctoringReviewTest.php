<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Proctoring\ProctoringSession;
use Central\Api\Domain\Proctoring\ViolationSeverity;
use Central\Api\Domain\Proctoring\ViolationType;
use Central\Api\Infrastructure\Persistence\DbalProctoringRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * The reviewer-side proctoring parity — sitting verdict, snapshot flagging and
 * per-sitting analytics — against a real PostgreSQL.
 */
#[CoversClass(DbalProctoringRepository::class)]
final class DbalProctoringReviewTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalProctoringRepository $proctoring;
    private string $tenant;
    private string $user;
    private string $reviewer;
    private string $attemptId;
    private ProctoringSession $session;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable.');
        }

        $this->proctoring = new DbalProctoringRepository(self::$connection);

        $this->tenant = $this->createTenant();
        $this->user = $this->createUser('Learner');
        $this->reviewer = $this->createUser('Reviewer');
        $assessmentId = $this->createAssessment();
        $this->attemptId = $this->createAttempt($assessmentId, $this->user);

        $this->session = $this->proctoring->openSession($this->tenant, $this->attemptId, $this->user);

        // Two violations of different types/severities, and one snapshot.
        $this->proctoring->recordViolation(
            $this->tenant, $this->session, ViolationType::TabSwitch, ViolationSeverity::High, 'Left the tab', null, [],
        );
        $this->proctoring->recordViolation(
            $this->tenant, $this->session, ViolationType::TabSwitch, ViolationSeverity::Low, 'Again', null, [],
        );
        $this->snapshotId = $this->proctoring->recordSnapshot($this->tenant, $this->session, [
            'kind' => 'periodic', 'imageUrl' => '/proctoring/snap.jpg', 'timeElapsedSeconds' => 30,
        ]);
    }

    private string $snapshotId;

    protected function tearDown(): void
    {
        if (self::$connection === null) {
            return;
        }

        foreach (['proctoring_snapshots', 'proctoring_violations', 'proctoring_sessions', 'attempts', 'assessments', 'users'] as $table) {
            self::$connection->executeStatement("DELETE FROM {$table} WHERE tenant_id = :id", ['id' => $this->tenant]);
        }
        self::$connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $this->tenant]);
    }

    #[Test]
    public function reviewing_a_sitting_records_the_verdict_notes_and_reviewer(): void
    {
        $updated = $this->proctoring->reviewSession(
            $this->tenant, $this->session->id, 'flagged', 'Repeated tab switching.', $this->reviewer,
        );

        self::assertNotNull($updated);
        self::assertSame('flagged', $updated->reviewStatus);
        self::assertSame('Repeated tab switching.', $updated->reviewNotes);
        self::assertSame($this->reviewer, $updated->reviewedBy);
        self::assertNotNull($updated->reviewedAt);

        // It round-trips through toArray for the API.
        self::assertSame('flagged', $updated->toArray()['reviewStatus']);
    }

    #[Test]
    public function a_pending_verdict_reopens_the_sitting_for_the_queue(): void
    {
        // First flag it (stamps reviewed_at)...
        $flagged = $this->proctoring->reviewSession(
            $this->tenant, $this->session->id, 'flagged', 'look again', $this->reviewer,
        );
        self::assertNotNull($flagged?->reviewedAt);

        // ...then reopen it. 'pending' must clear reviewed_at so the pending
        // queue (reviewed_at IS NULL) shows it again.
        $reopened = $this->proctoring->reviewSession(
            $this->tenant, $this->session->id, 'pending', 'still checking', $this->reviewer,
        );
        self::assertNotNull($reopened);
        self::assertSame('pending', $reopened->reviewStatus);
        self::assertNull($reopened->reviewedAt);

        $pending = $this->proctoring->reviewQueue($this->tenant, null, 1, 50, pendingOnly: true);
        $ids = array_column($pending['items'], 'sessionId');
        self::assertContains($this->session->id, $ids);
    }

    #[Test]
    public function reviewing_an_unknown_sitting_returns_null(): void
    {
        self::assertNull($this->proctoring->reviewSession(
            $this->tenant, Uuid::uuid4()->toString(), 'cleared', null, $this->reviewer,
        ));
    }

    #[Test]
    public function another_tenant_cannot_review_the_sitting(): void
    {
        self::assertNull($this->proctoring->reviewSession(
            Uuid::uuid4()->toString(), $this->session->id, 'cleared', null, $this->reviewer,
        ));
    }

    #[Test]
    public function flagging_a_snapshot_toggles_it_and_unflagging_clears_the_reason(): void
    {
        self::assertTrue($this->proctoring->flagSnapshot($this->tenant, $this->snapshotId, true, 'Face not visible'));

        $flagged = $this->proctoring->snapshotsFor($this->tenant, $this->session->id, flaggedOnly: true);
        self::assertCount(1, $flagged);
        self::assertSame('Face not visible', $flagged[0]['flagReason']);

        // Unflagging clears the reason.
        self::assertTrue($this->proctoring->flagSnapshot($this->tenant, $this->snapshotId, false, 'Face not visible'));
        $none = $this->proctoring->snapshotsFor($this->tenant, $this->session->id, flaggedOnly: true);
        self::assertCount(0, $none);

        $all = $this->proctoring->snapshotsFor($this->tenant, $this->session->id);
        self::assertNull($all[0]['flagReason']);
    }

    #[Test]
    public function find_snapshot_session_is_tenant_scoped(): void
    {
        self::assertSame($this->session->id, $this->proctoring->findSnapshotSession($this->tenant, $this->snapshotId));
        self::assertNull($this->proctoring->findSnapshotSession(Uuid::uuid4()->toString(), $this->snapshotId));
    }

    #[Test]
    public function session_analytics_break_the_violations_down(): void
    {
        $a = $this->proctoring->sessionAnalytics($this->tenant, $this->session->id);

        self::assertSame(2, $a['violationCount']);
        self::assertSame(1, $a['snapshotCount']);

        $byType = [];
        foreach ($a['byType'] as $r) {
            $byType[$r['type']] = $r['count'];
        }
        self::assertSame(2, $byType['tab_switch']);

        $bySeverity = [];
        foreach ($a['bySeverity'] as $r) {
            $bySeverity[$r['severity']] = $r['count'];
        }
        self::assertSame(1, $bySeverity['high']);
        self::assertSame(1, $bySeverity['low']);

        self::assertArrayHasKey('trust', $a);
    }

    // --- fixtures ---------------------------------------------------------

    private function createTenant(): string
    {
        $id = Uuid::uuid4()->toString();
        $this->db()->executeStatement(
            "INSERT INTO tenants (id, name, code, status) VALUES (:id, :name, :code, 'active')",
            ['id' => $id, 'name' => 'proc test', 'code' => 'prc-' . substr($id, 0, 8)],
        );

        return $id;
    }

    private function createUser(string $first): string
    {
        return (string) $this->db()->fetchOne(
            "INSERT INTO users (tenant_id, email, first_name, last_name, status)
             VALUES (:tenant, :email, :first, 'T', 'active') RETURNING id",
            ['tenant' => $this->tenant, 'email' => strtolower($first) . '-' . Uuid::uuid4()->toString() . '@prc.test', 'first' => $first],
        );
    }

    private function createAssessment(): string
    {
        return (string) $this->db()->fetchOne(
            "INSERT INTO assessments (tenant_id, title, status) VALUES (:tenant, 'Proctored', 'published') RETURNING id",
            ['tenant' => $this->tenant],
        );
    }

    private function createAttempt(string $assessmentId, string $userId): string
    {
        return (string) $this->db()->fetchOne(
            "INSERT INTO attempts (tenant_id, assessment_id, user_id, attempt_number, status, started_at)
             VALUES (:tenant, :assessment, :user, 1, 'in_progress', now()) RETURNING id",
            ['tenant' => $this->tenant, 'assessment' => $assessmentId, 'user' => $userId],
        );
    }

    private function db(): Connection
    {
        return self::$connection ?? self::fail('PostgreSQL is not reachable.');
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
