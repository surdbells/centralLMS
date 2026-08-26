<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Assessment\Assessment;
use Central\Api\Domain\Assessment\ProctoringLevel;
use Central\Api\Domain\Proctoring\ModuleEntitlements;
use Central\Api\Domain\Proctoring\ProctoringSession;
use Central\Api\Domain\Proctoring\ProctoringSettings;
use Central\Api\Domain\Proctoring\SessionStatus;
use Central\Api\Domain\Proctoring\ViolationSeverity;
use Central\Api\Domain\Proctoring\ViolationType;
use Central\Api\Infrastructure\Persistence\DbalAssessmentRepository;
use Central\Api\Infrastructure\Persistence\DbalAttemptRepository;
use Central\Api\Infrastructure\Persistence\DbalProctoringRepository;
use Central\Api\Infrastructure\Persistence\DbalQuestionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * The proctoring extension against a real PostgreSQL.
 *
 * Two things here cannot be checked without the database: that the level on
 * `assessments` and on `proctoring_settings` are written together, and that a
 * critical violation ends the sitting in the same transaction that records
 * why.
 *
 * Skipped when the database is unreachable, so a checkout without the Docker
 * stack still gets a green suite.
 */
#[CoversClass(DbalProctoringRepository::class)]
final class DbalProctoringRepositoryTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalProctoringRepository $proctoring;
    private DbalAssessmentRepository $assessments;
    private DbalAttemptRepository $attempts;

    private string $tenantA;
    private string $tenantB;
    private string $learner;
    private string $reviewer;
    private Assessment $assessment;
    private ProctoringSession $session;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable — start it with: pnpm up');
        }

        $questions = new DbalQuestionRepository(self::$connection);
        $this->proctoring = new DbalProctoringRepository(self::$connection);
        $this->assessments = new DbalAssessmentRepository(self::$connection, $questions);
        $this->attempts = new DbalAttemptRepository(self::$connection, $questions);

        $this->tenantA = $this->createTenant('prc-a');
        $this->tenantB = $this->createTenant('prc-b');
        $this->learner = $this->createUser($this->tenantA, 'learner');
        $this->reviewer = $this->createUser($this->tenantA, 'reviewer');

        $this->assessment = $this->assessments->create(new Assessment(
            id: '',
            tenantId: $this->tenantA,
            title: 'Proctored ' . substr(Uuid::uuid4()->toString(), 0, 8),
        ));

        $attempt = $this->attempts->start($this->tenantA, $this->assessment->id, $this->learner, null);
        $this->session = $this->proctoring->openSession($this->tenantA, $attempt->id, $this->learner);
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

    // --- entitlement ------------------------------------------------------

    #[Test]
    public function a_tenant_starts_with_no_modules(): void
    {
        self::assertFalse(
            $this->proctoring->entitlementsFor($this->tenantA)->has(ModuleEntitlements::PROCTORING),
        );
    }

    #[Test]
    public function granting_and_revoking_a_module_takes_effect(): void
    {
        $granted = $this->proctoring->setEntitlement($this->tenantA, ModuleEntitlements::PROCTORING, true);
        self::assertTrue($granted->has(ModuleEntitlements::PROCTORING));

        $revoked = $this->proctoring->setEntitlement($this->tenantA, ModuleEntitlements::PROCTORING, false);
        self::assertFalse($revoked->has(ModuleEntitlements::PROCTORING));
    }

    #[Test]
    public function granting_one_tenant_does_not_grant_another(): void
    {
        $this->proctoring->setEntitlement($this->tenantA, ModuleEntitlements::PROCTORING, true);

        self::assertFalse(
            $this->proctoring->entitlementsFor($this->tenantB)->has(ModuleEntitlements::PROCTORING),
        );
    }

    #[Test]
    public function granting_one_module_leaves_the_others_alone(): void
    {
        $this->proctoring->setEntitlement($this->tenantA, 'something_else', true);
        $this->proctoring->setEntitlement($this->tenantA, ModuleEntitlements::PROCTORING, true);

        $entitlements = $this->proctoring->entitlementsFor($this->tenantA);

        self::assertEqualsCanonicalizing(['something_else', 'proctoring'], $entitlements->enabled());
    }

    // --- settings ---------------------------------------------------------

    #[Test]
    public function saving_settings_keeps_the_assessment_level_in_step(): void
    {
        // Two copies of one fact only stay equal if nothing can write one
        // without the other.
        $this->proctoring->saveSettings(
            $this->tenantA,
            $this->assessment->id,
            ProctoringLevel::Standard,
            ProctoringSettings::forLevel(ProctoringLevel::Standard),
        );

        $stored = $this->proctoring->settingsFor($this->tenantA, $this->assessment->id);
        $assessment = $this->assessments->find($this->tenantA, $this->assessment->id);

        self::assertNotNull($stored);
        self::assertSame(ProctoringLevel::Standard, $stored['level']);
        self::assertSame(ProctoringLevel::Standard, $assessment?->proctoringLevel);
    }

    #[Test]
    public function saving_twice_updates_rather_than_duplicating(): void
    {
        foreach ([ProctoringLevel::Basic, ProctoringLevel::Advanced] as $level) {
            $this->proctoring->saveSettings(
                $this->tenantA,
                $this->assessment->id,
                $level,
                ProctoringSettings::forLevel($level),
            );
        }

        $stored = $this->proctoring->settingsFor($this->tenantA, $this->assessment->id);

        self::assertNotNull($stored);
        self::assertSame(ProctoringLevel::Advanced, $stored['level']);
        self::assertSame(1, (int) $this->db()->fetchOne(
            'SELECT COUNT(*) FROM proctoring_settings WHERE assessment_id = :id',
            ['id' => $this->assessment->id],
        ));
    }

    #[Test]
    public function settings_round_trip_with_their_overrides(): void
    {
        $this->proctoring->saveSettings(
            $this->tenantA,
            $this->assessment->id,
            ProctoringLevel::Basic,
            ProctoringSettings::fromInput(ProctoringLevel::Basic, [
                'preventCopyPaste' => false,
                'maxTabSwitches'   => 7,
            ]),
        );

        $stored = $this->proctoring->settingsFor($this->tenantA, $this->assessment->id);

        self::assertNotNull($stored);
        self::assertFalse($stored['settings']->preventCopyPaste);
        self::assertSame(7, $stored['settings']->maxTabSwitches);
    }

    #[Test]
    public function another_tenant_cannot_read_the_settings(): void
    {
        $this->proctoring->saveSettings(
            $this->tenantA,
            $this->assessment->id,
            ProctoringLevel::Standard,
            ProctoringSettings::forLevel(ProctoringLevel::Standard),
        );

        self::assertNull($this->proctoring->settingsFor($this->tenantB, $this->assessment->id));
    }

    // --- sessions ---------------------------------------------------------

    #[Test]
    public function opening_a_session_twice_returns_the_same_one(): void
    {
        // Reloading during the pre-flight checks must not split the evidence
        // across two sessions.
        $again = $this->proctoring->openSession(
            $this->tenantA,
            $this->session->attemptId,
            $this->learner,
        );

        self::assertSame($this->session->id, $again->id);
    }

    #[Test]
    public function a_session_starts_awaiting_its_checks(): void
    {
        self::assertSame(SessionStatus::PendingCheck, $this->session->status);
        self::assertNull($this->session->systemCheckPassed);
    }

    #[Test]
    public function only_allow_listed_fields_are_written(): void
    {
        // The method takes an array, and an array is one typo away from
        // writing a column nobody meant to expose.
        $updated = $this->proctoring->updateSession($this->tenantA, $this->session->id, [
            'status'      => SessionStatus::Active->value,
            'tenant_id'   => $this->tenantB,
            'attempt_id'  => Uuid::uuid4()->toString(),
            'nonsense'    => 'ignored',
        ]);

        self::assertSame(SessionStatus::Active, $updated->status);
        self::assertSame($this->tenantA, $updated->tenantId);
        self::assertSame($this->session->attemptId, $updated->attemptId);
    }

    #[Test]
    public function the_system_check_result_round_trips(): void
    {
        $updated = $this->proctoring->updateSession($this->tenantA, $this->session->id, [
            'systemCheckPassed' => true,
            'systemCheckAt'     => new \DateTimeImmutable(),
            'systemCheckData'   => ['browser' => 'Firefox', 'camera' => true],
            'status'            => SessionStatus::Active->value,
        ]);

        self::assertTrue($updated->systemCheckPassed);
        self::assertNotNull($updated->systemCheckAt);
        self::assertSame('Firefox', $updated->systemCheckData['browser'] ?? null);
    }

    #[Test]
    public function outstanding_checks_reflect_what_the_level_demands(): void
    {
        $advanced = ProctoringSettings::forLevel(ProctoringLevel::Advanced);
        $basic = ProctoringSettings::forLevel(ProctoringLevel::Basic);

        $checked = $this->proctoring->updateSession($this->tenantA, $this->session->id, [
            'systemCheckPassed' => true,
            'systemCheckAt'     => new \DateTimeImmutable(),
        ]);

        // The browser check alone satisfies basic, but not advanced.
        self::assertSame([], $checked->outstandingChecks($basic));
        self::assertEqualsCanonicalizing(
            ['id_verification', 'room_scan'],
            $checked->outstandingChecks($advanced),
        );
    }

    #[Test]
    public function another_tenant_cannot_see_the_session(): void
    {
        self::assertNull($this->proctoring->findSession($this->tenantB, $this->session->id));
        self::assertNull($this->proctoring->findSessionForAttempt($this->tenantB, $this->session->attemptId));
    }

    // --- violations -------------------------------------------------------

    #[Test]
    public function recording_an_event_lowers_the_score_by_its_weight(): void
    {
        $result = $this->record(ViolationType::TabSwitch);

        self::assertSame(100.0 - ViolationSeverity::Medium->weight(), $result['trust']->score);
        self::assertSame('warning_shown', $result['action']);
    }

    #[Test]
    public function a_critical_event_ends_the_sitting_as_it_records_it(): void
    {
        $result = $this->record(ViolationType::DifferentPerson);

        self::assertSame('terminated', $result['action']);
        self::assertTrue($result['trust']->hasCritical);

        // In the same transaction, so the session is never seen still running
        // with a critical event already against it.
        self::assertSame(
            SessionStatus::Terminated,
            $this->proctoring->findSession($this->tenantA, $this->session->id)?->status,
        );
    }

    #[Test]
    public function a_low_severity_event_neither_warns_nor_ends_anything(): void
    {
        $result = $this->record(ViolationType::SuspiciousMovement);

        self::assertSame('none', $result['action']);
        self::assertSame(
            SessionStatus::PendingCheck,
            $this->proctoring->findSession($this->tenantA, $this->session->id)?->status,
        );
    }

    #[Test]
    public function the_trust_score_accumulates_across_events(): void
    {
        $this->record(ViolationType::TabSwitch);
        $this->record(ViolationType::LookingAway);

        $trust = $this->proctoring->trustFor($this->tenantA, $this->session->id);

        self::assertSame(2, $trust->violationCount);
        self::assertSame(97.5, $trust->score);
    }

    #[Test]
    public function marking_an_event_a_false_positive_restores_the_score(): void
    {
        $result = $this->record(ViolationType::DifferentPerson);

        self::assertTrue(
            $this->proctoring->reviewViolation(
                $this->tenantA,
                $result['violation']['id'],
                'false_positive',
                'Their supervisor walked past.',
                $this->reviewer,
            ),
        );

        $trust = $this->proctoring->trustFor($this->tenantA, $this->session->id);

        self::assertSame(100.0, $trust->score);
        self::assertFalse($trust->hasCritical);
        self::assertSame(0, $trust->violationCount);
    }

    #[Test]
    public function confirming_an_event_leaves_it_counting(): void
    {
        $result = $this->record(ViolationType::PhoneDetected);

        $this->proctoring->reviewViolation(
            $this->tenantA,
            $result['violation']['id'],
            'valid',
            null,
            $this->reviewer,
        );

        self::assertSame(
            100.0 - ViolationSeverity::High->weight(),
            $this->proctoring->trustFor($this->tenantA, $this->session->id)->score,
        );
    }

    #[Test]
    public function another_tenant_cannot_review_the_event(): void
    {
        $result = $this->record(ViolationType::TabSwitch);

        self::assertFalse($this->proctoring->reviewViolation(
            $this->tenantB,
            $result['violation']['id'],
            'false_positive',
            null,
            $this->reviewer,
        ));
    }

    #[Test]
    public function the_event_list_is_scoped_to_the_tenant(): void
    {
        $this->record(ViolationType::TabSwitch);

        self::assertCount(1, $this->proctoring->violationsFor($this->tenantA, $this->session->id));
        self::assertSame([], $this->proctoring->violationsFor($this->tenantB, $this->session->id));
    }

    // --- snapshots --------------------------------------------------------

    #[Test]
    public function a_snapshot_round_trips_with_its_analysis(): void
    {
        $this->proctoring->recordSnapshot($this->tenantA, $this->session, [
            'imageUrl'        => 'https://example.test/frame.jpg',
            'kind'            => 'periodic',
            'faceDetected'    => true,
            'faceCount'       => 1,
            'faceConfidence'  => 98.5,
            'objectsDetected' => ['phone'],
            'flagged'         => true,
            'flagReason'      => 'Phone in frame',
        ]);

        $snapshots = $this->proctoring->snapshotsFor($this->tenantA, $this->session->id);

        self::assertCount(1, $snapshots);
        self::assertTrue($snapshots[0]['faceDetected']);
        self::assertSame(98.5, $snapshots[0]['faceConfidence']);
        self::assertSame(['phone'], $snapshots[0]['objectsDetected']);
        self::assertTrue($snapshots[0]['flagged']);
    }

    #[Test]
    public function an_unanalysed_snapshot_keeps_its_nulls(): void
    {
        // Absence means "not analysed", which is not the same as "nobody
        // there" — and reading it as false would fabricate a violation.
        $this->proctoring->recordSnapshot($this->tenantA, $this->session, [
            'imageUrl' => 'https://example.test/plain.jpg',
        ]);

        $snapshot = $this->proctoring->snapshotsFor($this->tenantA, $this->session->id)[0];

        self::assertNull($snapshot['faceDetected']);
        self::assertNull($snapshot['faceCount']);
        self::assertNull($snapshot['objectsDetected']);
    }

    #[Test]
    public function the_flagged_filter_narrows_to_what_a_reviewer_opens_on(): void
    {
        foreach ([false, true, false] as $flagged) {
            $this->proctoring->recordSnapshot($this->tenantA, $this->session, [
                'imageUrl' => 'https://example.test/' . Uuid::uuid4()->toString() . '.jpg',
                'flagged'  => $flagged,
            ]);
        }

        self::assertCount(3, $this->proctoring->snapshotsFor($this->tenantA, $this->session->id));
        self::assertCount(
            1,
            $this->proctoring->snapshotsFor($this->tenantA, $this->session->id, flaggedOnly: true),
        );
    }

    // --- review queue -----------------------------------------------------

    #[Test]
    public function the_queue_puts_the_worst_sitting_first(): void
    {
        // A second learner with a milder record.
        $other = $this->createUser($this->tenantA, 'other');
        $otherAttempt = $this->attempts->start($this->tenantA, $this->assessment->id, $other, null);
        $otherSession = $this->proctoring->openSession($this->tenantA, $otherAttempt->id, $other);

        $this->record(ViolationType::LookingAway, $otherSession);
        $this->record(ViolationType::DifferentPerson);

        $queue = $this->proctoring->reviewQueue($this->tenantA, $this->assessment->id, 1, 50);

        self::assertSame(2, $queue['total']);
        self::assertSame($this->session->id, $queue['items'][0]['sessionId']);
        self::assertSame(1, $queue['items'][0]['criticalCount']);
        self::assertLessThan($queue['items'][1]['trustScore'], $queue['items'][0]['trustScore']);
    }

    #[Test]
    public function the_queue_is_scoped_to_the_tenant(): void
    {
        $this->record(ViolationType::TabSwitch);

        self::assertSame(
            0,
            $this->proctoring->reviewQueue($this->tenantB, $this->assessment->id, 1, 50)['total'],
        );
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @return array{violation: array<string, mixed>, trust: \Central\Api\Domain\Proctoring\TrustScore, action: string}
     */
    private function record(ViolationType $type, ?ProctoringSession $session = null): array
    {
        $session ??= $this->session;

        return $this->proctoring->recordViolation(
            $this->tenantA,
            $session,
            $type,
            $type->defaultSeverity(),
            null,
            null,
            [],
        );
    }

    private function createTenant(string $prefix): string
    {
        $id = Uuid::uuid4()->toString();

        $this->db()->executeStatement(
            'INSERT INTO tenants (id, name, code, status) VALUES (:id, :name, :code, \'active\')',
            ['id' => $id, 'name' => $prefix . ' test tenant', 'code' => $prefix . '-' . substr($id, 0, 8)],
        );

        return $id;
    }

    private function createUser(string $tenantId, string $who): string
    {
        return (string) $this->db()->fetchOne(
            "INSERT INTO users (tenant_id, email, first_name, last_name, status)
             VALUES (:tenant, :email, :first, 'Tester', 'active') RETURNING id",
            [
                'tenant' => $tenantId,
                'email'  => $who . '-' . Uuid::uuid4()->toString() . '@prc.test',
                'first'  => ucfirst($who),
            ],
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
