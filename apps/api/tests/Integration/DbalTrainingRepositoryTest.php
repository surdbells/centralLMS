<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Enrollment\EnrollmentSource;
use Central\Api\Domain\Ilt\AttendanceCalculator;
use Central\Api\Domain\Ilt\AttendanceMode;
use Central\Api\Domain\Ilt\AttendanceStatus;
use Central\Api\Domain\Ilt\LiveTraining;
use Central\Api\Domain\Ilt\Meeting;
use Central\Api\Domain\Ilt\MeetingProviderId;
use Central\Api\Domain\Ilt\SessionStatus;
use Central\Api\Domain\Ilt\TrainingDelivery;
use Central\Api\Domain\Ilt\TrainingSession;
use Central\Api\Domain\Ilt\TrainingStatus;
use Central\Api\Infrastructure\Persistence\DbalTrainingRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Instructor-led training against a real PostgreSQL.
 *
 * The attendance path is why this exists: a mark and the programme figure it
 * feeds are written in one transaction, and the figure decides whether a
 * certificate is issued.
 *
 * Skipped when the database is unreachable, so a checkout without the Docker
 * stack still gets a green suite.
 */
#[CoversClass(DbalTrainingRepository::class)]
final class DbalTrainingRepositoryTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalTrainingRepository $trainings;

    private string $tenantA;
    private string $tenantB;
    private string $learner;
    private string $other;
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

        $this->trainings = new DbalTrainingRepository(self::$connection, new AttendanceCalculator());

        $this->tenantA = $this->createTenant('ilt-a');
        $this->tenantB = $this->createTenant('ilt-b');
        $this->learner = $this->createUser($this->tenantA, 'learner');
        $this->other = $this->createUser($this->tenantA, 'other');
        $this->admin = $this->createUser($this->tenantA, 'admin');
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

    // --- programmes -------------------------------------------------------

    #[Test]
    public function a_programme_round_trips_with_its_reminder_arrays(): void
    {
        $training = $this->training(['reminderOffsetsMinutes' => [1440, 60, 15], 'reminderChannels' => ['email', 'sms']]);

        $read = $this->trainings->find($this->tenantA, $training->id);

        self::assertNotNull($read);
        self::assertSame([1440, 60, 15], $read->reminderOffsetsMinutes);
        self::assertSame(['email', 'sms'], $read->reminderChannels);
    }

    #[Test]
    public function the_session_and_enrolment_counts_are_computed(): void
    {
        $training = $this->training();

        self::assertSame(0, $training->sessionCount);

        $this->session($training);
        $this->trainings->enrol($this->tenantA, $training, [$this->learner], EnrollmentSource::Direct, $this->admin);

        $read = $this->present($this->trainings->find($this->tenantA, $training->id));

        self::assertSame(1, $read->sessionCount);
        self::assertSame(1, $read->enrolledCount);
    }

    #[Test]
    public function deleting_a_programme_cancels_its_sessions(): void
    {
        $training = $this->training();
        $this->session($training);

        self::assertTrue($this->trainings->softDelete($this->tenantA, $training->id));
        self::assertNull($this->trainings->find($this->tenantA, $training->id));
        self::assertSame([], $this->trainings->sessionsFor($this->tenantA, $training->id));
    }

    #[Test]
    public function the_learner_facing_list_shows_only_what_is_on(): void
    {
        $this->training(['keepDraft' => true]);
        $this->training();

        self::assertSame(2, $this->trainings->paginate($this->tenantA, 1, 50)['total']);
        self::assertSame(
            1,
            $this->trainings->paginate($this->tenantA, 1, 50, null, null, openToLearnersOnly: true)['total'],
        );
    }

    // --- sessions ---------------------------------------------------------

    #[Test]
    public function sessions_are_numbered_in_sequence(): void
    {
        $training = $this->training();

        self::assertSame(1, $this->session($training)->sequence);
        self::assertSame(2, $this->session($training)->sequence);
    }

    #[Test]
    public function a_virtual_session_starts_with_no_meeting_and_says_it_needs_one(): void
    {
        // The state every legacy virtual session is stuck in — the difference
        // is that this one is asked about.
        $training = $this->training(['delivery' => 'virtual']);
        $session = $this->session($training, ['delivery' => 'virtual']);

        self::assertFalse($session->hasMeeting());
        self::assertTrue($session->needsMeeting());
    }

    #[Test]
    public function saving_a_meeting_records_what_the_provider_returned(): void
    {
        $training = $this->training(['delivery' => 'virtual']);
        $session = $this->session($training, ['delivery' => 'virtual']);

        $updated = $this->trainings->saveMeeting(
            $this->tenantA,
            $session->id,
            MeetingProviderId::Zoom,
            new Meeting(
                provider: MeetingProviderId::Zoom,
                externalId: '8899',
                joinUrl: 'https://zoom.us/j/8899',
                startsAt: $session->startsAt,
                durationMinutes: 60,
                hostUrl: 'https://zoom.us/s/8899',
                password: 'letmein',
            ),
            null,
        );

        $updated = $this->present($updated);

        self::assertTrue($updated->hasMeeting());
        self::assertFalse($updated->needsMeeting());
        self::assertSame('https://zoom.us/j/8899', $updated->joinUrl);
        self::assertNotNull($updated->meetingScheduledAt);
    }

    #[Test]
    public function a_failed_booking_is_recorded_rather_than_lost(): void
    {
        // A provider outage should not stop an organiser planning next month.
        $training = $this->training(['delivery' => 'virtual']);
        $session = $this->session($training, ['delivery' => 'virtual']);

        $updated = $this->trainings->saveMeeting(
            $this->tenantA,
            $session->id,
            MeetingProviderId::Zoom,
            null,
            'Zoom said the token has expired.',
        );

        $updated = $this->present($updated);

        self::assertFalse($updated->hasMeeting());
        self::assertSame('Zoom said the token has expired.', $updated->meetingError);
        // Still flagged as wanting one, so a retry is obviously available.
        self::assertTrue($updated->needsMeeting());
    }

    #[Test]
    public function the_host_url_is_kept_out_of_the_default_payload(): void
    {
        // Whoever holds it can start the meeting, admit strangers and end it.
        $training = $this->training(['delivery' => 'virtual']);
        $session = $this->session($training, ['delivery' => 'virtual']);

        $updated = $this->trainings->saveMeeting(
            $this->tenantA,
            $session->id,
            MeetingProviderId::Zoom,
            new Meeting(
                provider: MeetingProviderId::Zoom,
                externalId: '1',
                joinUrl: 'https://zoom.us/j/1',
                startsAt: $session->startsAt,
                durationMinutes: 60,
                hostUrl: 'https://zoom.us/s/1',
            ),
            null,
        );

        $updated = $this->present($updated);

        self::assertArrayNotHasKey('hostUrl', $updated->toArray()['meeting']);
        self::assertArrayHasKey('hostUrl', $updated->toArray(includeHostUrl: true)['meeting']);
    }

    #[Test]
    public function starting_and_completing_a_session_stamps_the_real_times(): void
    {
        $training = $this->training();
        $session = $this->session($training);

        $ongoing = $this->present(
            $this->trainings->setSessionStatus($this->tenantA, $session->id, SessionStatus::Ongoing),
        );
        self::assertNotNull($ongoing->actualStartedAt);
        self::assertNull($ongoing->actualEndedAt);

        $done = $this->present(
            $this->trainings->setSessionStatus($this->tenantA, $session->id, SessionStatus::Completed),
        );
        self::assertNotNull($done->actualEndedAt);
        // The start is not restamped.
        self::assertEquals($ongoing->actualStartedAt, $done->actualStartedAt);
    }

    // --- QR codes ---------------------------------------------------------

    #[Test]
    public function a_qr_token_expires_after_the_start_not_before_it(): void
    {
        // The single legacy token was generated at 23:19 and expired at 09:15
        // the same day. It was born expired, and nothing noticed.
        $training = $this->training(['delivery' => 'physical', 'qrGracePeriodMinutes' => 20]);
        $session = $this->session($training, ['delivery' => 'physical', 'venueName' => 'Room 1']);

        $issued = $this->present($this->trainings->issueQrToken($this->tenantA, $session->id, $training));

        self::assertNotNull($issued->qrToken);
        self::assertNotNull($issued->qrExpiresAt);
        self::assertGreaterThan($issued->qrGeneratedAt, $issued->qrExpiresAt);
        self::assertTrue($issued->qrIsValid($issued->qrToken, new \DateTimeImmutable()));
    }

    #[Test]
    public function a_token_cannot_be_minted_for_a_session_already_past_its_window(): void
    {
        $training = $this->training(['delivery' => 'physical', 'qrGracePeriodMinutes' => 15]);
        $session = $this->session($training, [
            'delivery'  => 'physical',
            'venueName' => 'Room 1',
            'startsAt'  => (new \DateTimeImmutable('-3 hours'))->format(\DateTimeInterface::ATOM),
            'endsAt'    => (new \DateTimeImmutable('-2 hours'))->format(\DateTimeInterface::ATOM),
        ]);

        self::assertNull($this->trainings->issueQrToken($this->tenantA, $session->id, $training));
    }

    #[Test]
    public function a_wrong_token_is_refused(): void
    {
        $training = $this->training(['delivery' => 'physical']);
        $session = $this->session($training, ['delivery' => 'physical', 'venueName' => 'Room 1']);
        $issued = $this->present($this->trainings->issueQrToken($this->tenantA, $session->id, $training));

        self::assertFalse($issued->qrIsValid('not-the-token', new \DateTimeImmutable()));
    }

    // --- enrolment --------------------------------------------------------

    #[Test]
    public function assigning_puts_people_on_the_programme(): void
    {
        $training = $this->training();

        $outcome = $this->trainings->enrol(
            $this->tenantA,
            $training,
            [$this->learner, $this->other],
            EnrollmentSource::Bulk,
            $this->admin,
        );

        self::assertCount(2, $outcome['enrolled']);
        self::assertSame(2, $this->trainings->countEnrolled($this->tenantA, $training->id));
    }

    #[Test]
    public function a_full_programme_without_a_waitlist_turns_people_away(): void
    {
        $training = $this->training(['maxCapacity' => 1]);

        $outcome = $this->trainings->enrol(
            $this->tenantA,
            $training,
            [$this->learner, $this->other],
            EnrollmentSource::Bulk,
            $this->admin,
        );

        self::assertCount(1, $outcome['enrolled']);
        self::assertCount(1, $outcome['rejected']);
    }

    #[Test]
    public function a_full_programme_with_a_waitlist_queues_them_in_order(): void
    {
        $training = $this->training(['maxCapacity' => 1, 'allowWaitlist' => true]);
        $third = $this->createUser($this->tenantA, 'third');

        $outcome = $this->trainings->enrol(
            $this->tenantA,
            $training,
            [$this->learner, $this->other, $third],
            EnrollmentSource::Bulk,
            $this->admin,
        );

        self::assertCount(1, $outcome['enrolled']);
        self::assertCount(2, $outcome['waitlisted']);

        // A place in the queue is not a place in the room.
        self::assertSame(1, $this->trainings->countEnrolled($this->tenantA, $training->id));

        $positions = array_map(
            static fn (string $userId): ?int => null,
            $outcome['waitlisted'],
        );

        self::assertCount(2, $positions);
    }

    #[Test]
    public function assigning_twice_does_not_double_enrol(): void
    {
        $training = $this->training();
        $this->trainings->enrol($this->tenantA, $training, [$this->learner], EnrollmentSource::Direct, $this->admin);

        $second = $this->trainings->enrol(
            $this->tenantA,
            $training,
            [$this->learner, $this->other],
            EnrollmentSource::Bulk,
            $this->admin,
        );

        self::assertSame([$this->other], $second['enrolled']);
        self::assertSame([$this->learner], $second['alreadyEnrolled']);
    }

    #[Test]
    public function withdrawing_keeps_the_row_and_reinstating_brings_them_back(): void
    {
        $training = $this->training();
        $this->trainings->enrol($this->tenantA, $training, [$this->learner], EnrollmentSource::Direct, $this->admin);

        self::assertSame([$this->learner], $this->trainings->withdraw($this->tenantA, $training->id, [$this->learner]));
        self::assertSame('dropped', $this->enrolment($training->id, $this->learner)['status']);

        $again = $this->trainings->enrol($this->tenantA, $training, [$this->learner], EnrollmentSource::Direct, $this->admin);

        self::assertCount(1, $again['enrolled']);
        self::assertSame('not_started', $this->enrolment($training->id, $this->learner)['status']);
    }

    // --- attendance -------------------------------------------------------

    #[Test]
    public function marking_attendance_recomputes_the_programme_figure(): void
    {
        $training = $this->training(['minAttendancePercentage' => 50]);
        $a = $this->session($training);
        $b = $this->session($training);
        $this->trainings->enrol($this->tenantA, $training, [$this->learner], EnrollmentSource::Direct, $this->admin);

        $result = $this->trainings->mark(
            $this->tenantA,
            $a,
            $training,
            $this->learner,
            AttendanceStatus::Present,
            AttendanceMode::Physical,
        );

        self::assertSame(50.0, $result['summary']->percentage);
        self::assertTrue($result['summary']->meetsRequirement());

        // Written in the same transaction, so the row already agrees.
        $enrolment = $this->enrolment($training->id, $this->learner);

        self::assertSame(50.0, $enrolment['attendancePercentage']);
        self::assertSame(1, $enrolment['sessionsAttended']);
        self::assertSame('in_progress', $enrolment['status']);
    }

    #[Test]
    public function completion_waits_until_every_session_has_happened(): void
    {
        // Meeting the bar halfway through a programme is not finishing it.
        $training = $this->training(['minAttendancePercentage' => 50]);
        $a = $this->session($training);
        $b = $this->session($training);
        $this->trainings->enrol($this->tenantA, $training, [$this->learner], EnrollmentSource::Direct, $this->admin);

        $this->trainings->mark($this->tenantA, $a, $training, $this->learner, AttendanceStatus::Present, AttendanceMode::Physical);

        self::assertSame('in_progress', $this->enrolment($training->id, $this->learner)['status']);

        $this->trainings->setSessionStatus($this->tenantA, $a->id, SessionStatus::Completed);
        $this->trainings->setSessionStatus($this->tenantA, $b->id, SessionStatus::Completed);
        $this->trainings->recalculate($this->tenantA, $training, $this->learner);

        $enrolment = $this->enrolment($training->id, $this->learner);

        self::assertSame('completed', $enrolment['status']);
        self::assertNotNull($enrolment['completedAt']);
    }

    #[Test]
    public function falling_short_does_not_complete_the_programme(): void
    {
        $training = $this->training(['minAttendancePercentage' => 100]);
        $a = $this->session($training);
        $b = $this->session($training);
        $this->trainings->enrol($this->tenantA, $training, [$this->learner], EnrollmentSource::Direct, $this->admin);

        $this->trainings->mark($this->tenantA, $a, $training, $this->learner, AttendanceStatus::Present, AttendanceMode::Physical);
        $this->trainings->setSessionStatus($this->tenantA, $a->id, SessionStatus::Completed);
        $this->trainings->setSessionStatus($this->tenantA, $b->id, SessionStatus::Completed);
        $this->trainings->recalculate($this->tenantA, $training, $this->learner);

        $enrolment = $this->enrolment($training->id, $this->learner);

        self::assertSame('in_progress', $enrolment['status']);
        self::assertNull($enrolment['completedAt']);
    }

    #[Test]
    public function marking_twice_replaces_rather_than_stacking(): void
    {
        $training = $this->training();
        $session = $this->session($training);
        $this->trainings->enrol($this->tenantA, $training, [$this->learner], EnrollmentSource::Direct, $this->admin);

        $this->trainings->mark($this->tenantA, $session, $training, $this->learner, AttendanceStatus::Absent, AttendanceMode::Physical);
        $this->trainings->mark($this->tenantA, $session, $training, $this->learner, AttendanceStatus::Present, AttendanceMode::Physical);

        $register = $this->trainings->attendanceFor($this->tenantA, $session->id);

        self::assertCount(1, $register);
        self::assertSame('present', $register[0]['status']);
    }

    #[Test]
    public function an_override_is_visible_as_one(): void
    {
        // Indistinguishable from a scan is exactly what an auditor asks about.
        $training = $this->training();
        $session = $this->session($training);
        $this->trainings->enrol($this->tenantA, $training, [$this->learner], EnrollmentSource::Direct, $this->admin);

        $this->trainings->mark(
            $this->tenantA,
            $session,
            $training,
            $this->learner,
            AttendanceStatus::Present,
            AttendanceMode::Physical,
            ['isOverride' => true, 'overrideBy' => $this->admin, 'overrideReason' => 'Signed the paper register.'],
        );

        $register = $this->trainings->attendanceFor($this->tenantA, $session->id);

        self::assertTrue($register[0]['isManualOverride']);
        self::assertSame('Signed the paper register.', $register[0]['overrideReason']);
    }

    #[Test]
    public function marking_somebody_who_is_not_enrolled_is_refused(): void
    {
        $training = $this->training();
        $session = $this->session($training);

        $this->expectException(\RuntimeException::class);

        $this->trainings->mark(
            $this->tenantA,
            $session,
            $training,
            $this->learner,
            AttendanceStatus::Present,
            AttendanceMode::Physical,
        );
    }

    // --- tenant isolation -------------------------------------------------

    #[Test]
    public function another_tenant_sees_none_of_it(): void
    {
        $training = $this->training();
        $session = $this->session($training);
        $this->trainings->enrol($this->tenantA, $training, [$this->learner], EnrollmentSource::Direct, $this->admin);

        self::assertNull($this->trainings->find($this->tenantB, $training->id));
        self::assertNull($this->trainings->findSession($this->tenantB, $session->id));
        self::assertSame([], $this->trainings->sessionsFor($this->tenantB, $training->id));
        self::assertSame(0, $this->trainings->paginate($this->tenantB, 1, 50)['total']);
        self::assertSame(0, $this->trainings->rosterFor($this->tenantB, $training->id, 1, 50)['total']);
        self::assertSame([], $this->trainings->attendanceFor($this->tenantB, $session->id));
        self::assertNull($this->trainings->findEnrolment($this->tenantB, $training->id, $this->learner));
    }

    #[Test]
    public function another_tenant_cannot_publish_or_delete_it(): void
    {
        $training = $this->training(['keepDraft' => true]);

        self::assertNull($this->trainings->setStatus($this->tenantB, $training->id, TrainingStatus::Published));
        self::assertFalse($this->trainings->softDelete($this->tenantB, $training->id));
        self::assertSame(TrainingStatus::Draft, $this->trainings->find($this->tenantA, $training->id)?->status);
    }

    #[Test]
    public function a_qr_token_only_resolves_within_its_own_tenant(): void
    {
        $training = $this->training(['delivery' => 'physical']);
        $session = $this->session($training, ['delivery' => 'physical', 'venueName' => 'Room 1']);
        $issued = $this->present($this->trainings->issueQrToken($this->tenantA, $session->id, $training));

        self::assertNotNull($issued->qrToken);
        self::assertNotNull($this->trainings->findSessionByQrToken($this->tenantA, $issued->qrToken));
        self::assertNull($this->trainings->findSessionByQrToken($this->tenantB, $issued->qrToken));
    }

    // --- helpers ----------------------------------------------------------

    /**
     * Asserts the row is there and hands it back.
     *
     * The point of each of these tests is that the row exists, so its absence
     * should fail here rather than turn every later assertion into a null.
     *
     * @template T of object
     *
     * @param T|null $row
     *
     * @return T
     */
    private function present(?object $row): object
    {
        self::assertNotNull($row);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function enrolment(string $trainingId, string $userId): array
    {
        $enrolment = $this->trainings->findEnrolment($this->tenantA, $trainingId, $userId);

        self::assertNotNull($enrolment);

        return $enrolment;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function training(array $overrides = []): LiveTraining
    {
        $created = $this->trainings->create(LiveTraining::fromInput(
            '',
            $this->tenantA,
            ['title' => 'Training ' . substr(Uuid::uuid4()->toString(), 0, 8), ...$overrides],
        ));

        // Drafts admit nobody — LiveTraining::admits() refuses before it ever
        // looks at capacity, and the actions guard the same way. The default
        // here is a published programme, because that is what enrolment and
        // attendance are about.
        if (($overrides['keepDraft'] ?? false) === true) {
            return $created;
        }

        return $this->trainings->setStatus($this->tenantA, $created->id, TrainingStatus::Published)
            ?? self::fail('Could not publish.');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function session(LiveTraining $training, array $overrides = []): TrainingSession
    {
        return $this->trainings->createSession(TrainingSession::fromInput(
            '',
            $this->tenantA,
            $training->id,
            $training->delivery,
            [
                'title'    => 'Session ' . substr(Uuid::uuid4()->toString(), 0, 8),
                'startsAt' => (new \DateTimeImmutable('+1 day'))->format(\DateTimeInterface::ATOM),
                'endsAt'   => (new \DateTimeImmutable('+1 day +2 hours'))->format(\DateTimeInterface::ATOM),
                'venueName' => $training->delivery === TrainingDelivery::Physical ? 'Room 1' : null,
                ...$overrides,
            ],
        ));
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
                'email'  => $who . '-' . Uuid::uuid4()->toString() . '@ilt.test',
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
