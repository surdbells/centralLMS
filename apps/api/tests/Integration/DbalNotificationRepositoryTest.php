<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Notification\ChannelRouter;
use Central\Api\Domain\Notification\DeliveryStatus;
use Central\Api\Domain\Notification\Notification;
use Central\Api\Domain\Notification\NotificationChannel;
use Central\Api\Domain\Notification\NotificationComposer;
use Central\Api\Domain\Notification\NotificationDelivery;
use Central\Api\Domain\Notification\NotificationPreference;
use Central\Api\Domain\Notification\NotificationSchedule;
use Central\Api\Domain\Notification\NotificationType;
use Central\Api\Domain\Notification\PendingDelivery;
use Central\Api\Domain\Notification\QuietHours;
use Central\Api\Domain\Notification\ScheduleStatus;
use Central\Api\Infrastructure\Persistence\DbalNotificationRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Notifications against a real PostgreSQL.
 *
 * Three things here cannot be checked with a fake, and each is a bug that
 * would be invisible until it mattered:
 *
 *   * the claim. `FOR UPDATE SKIP LOCKED` is what stops two workers sending
 *     the same email, and the compose file overlaps workers on every restart.
 *   * the retry arithmetic, which is done in SQL from the row's own counters
 *     so that two workers cannot race on it.
 *   * `syncSchedules` being idempotent, since it runs on every save of a
 *     session and must not resurrect a reminder that has already gone out.
 *
 * Skipped when the database is unreachable, so a checkout without the Docker
 * stack still gets a green suite.
 */
#[CoversClass(DbalNotificationRepository::class)]
#[CoversClass(Notification::class)]
#[CoversClass(NotificationDelivery::class)]
#[CoversClass(NotificationSchedule::class)]
final class DbalNotificationRepositoryTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalNotificationRepository $notifications;
    private NotificationComposer $composer;

    private string $tenantA;
    private string $tenantB;
    private string $learner;
    private string $other;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    /**
     * Each test runs inside a transaction that is rolled back afterwards.
     *
     * Deleting the tenants was not enough. Three of the methods under test
     * are table-wide on purpose — `claimDue()` drains every due delivery,
     * `releaseStale()` sweeps every abandoned one, and `releaseStale()`
     * marks a row `failed` once its attempts are spent. Run against a
     * database shared with the end-to-end suites, they were claiming real
     * deliveries that belonged to nobody here, marking them sent, and
     * burning their retry budgets. A rollback puts all of it back.
     */
    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable — start it with: pnpm up');
        }

        self::$connection->beginTransaction();

        $this->notifications = new DbalNotificationRepository(self::$connection);
        $this->composer      = new NotificationComposer();

        $this->tenantA = $this->createTenant('ntf-a');
        $this->tenantB = $this->createTenant('ntf-b');
        $this->learner = $this->createUser($this->tenantA, 'learner');
        $this->other   = $this->createUser($this->tenantA, 'other');
    }

    protected function tearDown(): void
    {
        if (self::$connection?->isTransactionActive() === true) {
            self::$connection->rollBack();
        }
    }

    // --- the inbox --------------------------------------------------------

    #[Test]
    public function a_notification_round_trips_with_its_wording(): void
    {
        $raised = $this->raise(NotificationType::CourseCompleted, ['subjectTitle' => 'Fire Safety']);

        $read = $this->notifications->find($this->tenantA, (string) $raised->id);

        self::assertNotNull($read);
        self::assertStringContainsString('Fire Safety', $read->title);
        self::assertSame('Fire Safety', $read->data['subjectTitle']);
        self::assertFalse($read->isRead());
    }

    #[Test]
    public function another_tenant_cannot_read_it(): void
    {
        $raised = $this->raise(NotificationType::Welcome);

        self::assertNull($this->notifications->find($this->tenantB, (string) $raised->id));
    }

    #[Test]
    public function the_inbox_is_newest_first(): void
    {
        $this->raise(NotificationType::Welcome, [], at: '2026-03-01 09:00');
        $this->raise(NotificationType::CourseCompleted, ['subjectTitle' => 'Later'], at: '2026-03-01 11:00');

        $inbox = $this->notifications->inbox($this->tenantA, $this->learner, 1, 20);

        self::assertSame(2, $inbox['total']);
        self::assertStringContainsString('Later', $inbox['items'][0]->title);
    }

    #[Test]
    public function the_inbox_holds_only_your_own(): void
    {
        $this->raise(NotificationType::Welcome);
        $this->raise(NotificationType::Welcome, userId: $this->other);

        self::assertSame(1, $this->notifications->inbox($this->tenantA, $this->learner, 1, 20)['total']);
    }

    #[Test]
    public function an_expired_notification_drops_off_the_bell(): void
    {
        // Applied in the repository, not left to each caller: a reminder for
        // a finished session reappearing because one query forgot a clause is
        // exactly what this prevents.
        $raised = $this->raise(NotificationType::SessionReminder, ['subjectTitle' => 'Python Q&A']);

        $this->expire((string) $raised->id);

        self::assertSame(0, $this->notifications->inbox($this->tenantA, $this->learner, 1, 20)['total']);
        self::assertSame(0, $this->notifications->unreadCount($this->tenantA, $this->learner));
    }

    #[Test]
    public function marking_read_lowers_the_count(): void
    {
        $raised = $this->raise(NotificationType::Welcome);

        self::assertSame(1, $this->notifications->unreadCount($this->tenantA, $this->learner));
        self::assertSame(1, $this->notifications->markRead($this->tenantA, $this->learner, (string) $raised->id));
        self::assertSame(0, $this->notifications->unreadCount($this->tenantA, $this->learner));
    }

    #[Test]
    public function marking_read_twice_changes_nothing_the_second_time(): void
    {
        $raised = $this->raise(NotificationType::Welcome);

        $this->notifications->markRead($this->tenantA, $this->learner, (string) $raised->id);

        self::assertSame(0, $this->notifications->markRead($this->tenantA, $this->learner, (string) $raised->id));
    }

    #[Test]
    public function you_cannot_mark_somebody_elses_notification_read(): void
    {
        // The user id is in the WHERE clause rather than checked beforehand,
        // so there is no window between the check and the write.
        $raised = $this->raise(NotificationType::Welcome, userId: $this->other);

        self::assertSame(0, $this->notifications->markRead($this->tenantA, $this->learner, (string) $raised->id));
        self::assertSame(1, $this->notifications->unreadCount($this->tenantA, $this->other));
    }

    #[Test]
    public function marking_all_read_leaves_other_peoples_alone(): void
    {
        $this->raise(NotificationType::Welcome);
        $this->raise(NotificationType::CourseCompleted, ['subjectTitle' => 'X']);
        $this->raise(NotificationType::Welcome, userId: $this->other);

        self::assertSame(2, $this->notifications->markAllRead($this->tenantA, $this->learner));
        self::assertSame(1, $this->notifications->unreadCount($this->tenantA, $this->other));
    }

    #[Test]
    public function deleting_hides_it_without_losing_the_record(): void
    {
        $raised = $this->raise(NotificationType::Welcome);

        self::assertTrue($this->notifications->softDelete($this->tenantA, $this->learner, (string) $raised->id));
        self::assertSame(0, $this->notifications->inbox($this->tenantA, $this->learner, 1, 20)['total']);

        self::assertSame(1, (int) self::$connection?->fetchOne(
            'SELECT COUNT(*) FROM notifications WHERE id = :id',
            ['id' => $raised->id],
        ));
    }

    #[Test]
    public function you_cannot_delete_somebody_elses(): void
    {
        $raised = $this->raise(NotificationType::Welcome, userId: $this->other);

        self::assertFalse($this->notifications->softDelete($this->tenantA, $this->learner, (string) $raised->id));
    }

    #[Test]
    public function the_unread_filter_narrows_the_inbox(): void
    {
        $first = $this->raise(NotificationType::Welcome);
        $this->raise(NotificationType::CourseCompleted, ['subjectTitle' => 'X']);

        $this->notifications->markRead($this->tenantA, $this->learner, (string) $first->id);

        $unread = $this->notifications->inbox($this->tenantA, $this->learner, 1, 20, ['unreadOnly' => true]);

        self::assertSame(1, $unread['total']);
    }

    // --- delivery ---------------------------------------------------------

    #[Test]
    public function raising_writes_the_deliveries_alongside_it(): void
    {
        $raised = $this->raise(NotificationType::EnrollmentAssigned, ['subjectTitle' => 'Fire Safety']);

        $read = $this->notifications->findWithDeliveries($this->tenantA, (string) $raised->id);

        self::assertNotNull($read);
        self::assertCount(2, $read->deliveries);
    }

    #[Test]
    public function the_in_app_delivery_is_already_sent_when_it_is_written(): void
    {
        // Nothing is transmitted — the notification row is on the bell the
        // instant it exists. Recording it as pending would put a queue entry
        // in the delivery log for something already delivered.
        $raised = $this->raise(NotificationType::EnrollmentAssigned, ['subjectTitle' => 'Fire Safety']);

        $read = $this->notifications->findWithDeliveries($this->tenantA, (string) $raised->id);

        self::assertNotNull($read);

        $inApp = $this->deliveryOn($read, NotificationChannel::InApp);
        $email = $this->deliveryOn($read, NotificationChannel::Email);

        self::assertSame(DeliveryStatus::Sent, $inApp->status);
        self::assertNotNull($inApp->sentAt);
        self::assertSame(DeliveryStatus::Pending, $email->status);
    }

    #[Test]
    public function only_the_channels_that_need_sending_reach_a_worker(): void
    {
        $this->raise(NotificationType::EnrollmentAssigned, ['subjectTitle' => 'Fire Safety']);

        $claimed = $this->claimMine('worker-1');

        self::assertCount(1, $claimed);
        self::assertSame(NotificationChannel::Email, $claimed[0]->channel);
    }

    #[Test]
    public function claiming_takes_a_due_delivery_and_marks_the_attempt(): void
    {
        $this->raise(NotificationType::EnrollmentAssigned, ['subjectTitle' => 'Fire Safety']);

        $claimed = $this->claimMine('worker-1');

        self::assertCount(1, $claimed);
        self::assertSame(1, $claimed[0]->attempts);
        self::assertStringContainsString('Fire Safety', $claimed[0]->title);
    }

    #[Test]
    public function a_second_worker_finds_nothing_left_to_claim(): void
    {
        // The heart of it. Without FOR UPDATE SKIP LOCKED both workers take
        // the same rows and the same email goes out twice — and the compose
        // file overlaps workers on every five-minute restart.
        $this->raise(NotificationType::EnrollmentAssigned, ['subjectTitle' => 'Fire Safety']);

        $first  = $this->claimMine('worker-1');
        $second = $this->claimMine('worker-2');

        self::assertCount(1, $first);
        self::assertCount(0, $second);
    }

    #[Test]
    public function a_delivery_scheduled_for_later_is_not_claimed_yet(): void
    {
        $raised = $this->raise(NotificationType::EnrollmentAssigned, ['subjectTitle' => 'Fire Safety']);
        $this->defer((string) $raised->id, '+1 hour');

        self::assertCount(0, $this->claimMine('worker-1'));
    }

    #[Test]
    public function a_skipped_delivery_is_never_claimed(): void
    {
        // The reason it was skipped — no mobile number, opted out — does not
        // change by trying again.
        $raised = $this->raiseWithDeliveries(
            NotificationType::SessionReminder,
            [$this->delivery(NotificationChannel::Sms, status: DeliveryStatus::Skipped)],
        );

        self::assertCount(0, $this->claimMine('worker-1'));
        self::assertNotNull($this->notifications->findWithDeliveries($this->tenantA, (string) $raised->id));
    }

    #[Test]
    public function marking_sent_records_when_and_clears_the_claim(): void
    {
        $this->raise(NotificationType::EnrollmentAssigned, ['subjectTitle' => 'Fire Safety']);
        $claimed = $this->claimMine('worker-1');

        $this->notifications->markSent($claimed[0]->deliveryId, new \DateTimeImmutable(), 'msg-123');

        $row = $this->deliveryRow($claimed[0]->deliveryId);

        self::assertSame('sent', $row['status']);
        self::assertNotNull($row['sent_at']);
        self::assertNull($row['claimed_at']);
        self::assertSame('msg-123', $row['provider_message_id']);
    }

    #[Test]
    public function a_failed_attempt_goes_back_to_pending_with_a_backoff(): void
    {
        $this->raise(NotificationType::EnrollmentAssigned, ['subjectTitle' => 'Fire Safety']);
        $claimed = $this->claimMine('worker-1');

        $this->notifications->markAttemptFailed(
            $claimed[0]->deliveryId,
            new \DateTimeImmutable(),
            'The provider timed out.',
        );

        $row = $this->deliveryRow($claimed[0]->deliveryId);

        self::assertSame('pending', $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertSame('The provider timed out.', $row['error']);
        self::assertNull($row['claimed_at']);
        // Not due again immediately, or a dead provider is hammered.
        self::assertGreaterThan(new \DateTimeImmutable(), new \DateTimeImmutable((string) $row['scheduled_for']));
    }

    #[Test]
    public function the_last_attempt_lands_on_failed(): void
    {
        $this->raise(NotificationType::EnrollmentAssigned, ['subjectTitle' => 'Fire Safety']);

        $deliveryId = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            // A day ahead, so the backoff between attempts is already past.
            $claimed = $this->claimMine('worker-1', '+1 day');

            self::assertCount(1, $claimed, 'nothing left to claim on attempt ' . $attempt);

            $deliveryId = $claimed[0]->deliveryId;
            $this->notifications->markAttemptFailed($deliveryId, new \DateTimeImmutable(), 'still down');
            $this->undefer();
        }

        $row = $this->deliveryRow((string) $deliveryId);

        self::assertSame('failed', $row['status']);
        self::assertSame(3, (int) $row['attempts']);
        self::assertNotNull($row['failed_at']);
    }

    #[Test]
    public function a_permanent_rejection_burns_the_remaining_attempts(): void
    {
        // A malformed address will fail identically twice more, and all the
        // retries achieve is delaying the moment someone can see what is
        // wrong.
        $this->raise(NotificationType::EnrollmentAssigned, ['subjectTitle' => 'Fire Safety']);
        $claimed = $this->claimMine('worker-1');

        $this->notifications->markAttemptFailed(
            $claimed[0]->deliveryId,
            new \DateTimeImmutable(),
            'Not a valid email address.',
            exhaust: true,
        );

        $row = $this->deliveryRow($claimed[0]->deliveryId);

        self::assertSame('failed', $row['status']);
        self::assertSame((int) $row['max_attempts'], (int) $row['attempts']);
    }

    #[Test]
    public function a_delivery_abandoned_by_a_dead_worker_is_released(): void
    {
        // Without this a worker killed between claiming and sending leaves
        // rows in `sending` forever and notifications silently stop.
        $this->raise(NotificationType::EnrollmentAssigned, ['subjectTitle' => 'Fire Safety']);
        $claimed = $this->claimMine('worker-1');

        $this->notifications->releaseStale(new \DateTimeImmutable('+1 hour'));

        // Asserted on the row, not on the number the sweep returned: the
        // sweep is deliberately table-wide, so its count is not this test's
        // to predict.
        self::assertSame('pending', $this->deliveryRow($claimed[0]->deliveryId)['status']);
        self::assertCount(1, $this->claimMine('worker-2'));
    }

    #[Test]
    public function releasing_does_not_reset_the_attempt_count(): void
    {
        // A repeatedly crashing worker must still exhaust its budget rather
        // than looping forever.
        $this->raise(NotificationType::EnrollmentAssigned, ['subjectTitle' => 'Fire Safety']);
        $claimed = $this->claimMine('worker-1');

        $this->notifications->releaseStale(new \DateTimeImmutable('+1 hour'));

        self::assertSame(1, (int) $this->deliveryRow($claimed[0]->deliveryId)['attempts']);
    }

    #[Test]
    public function the_delivery_log_is_scoped_to_the_tenant(): void
    {
        $this->raise(NotificationType::EnrollmentAssigned, ['subjectTitle' => 'Fire Safety']);

        self::assertSame(2, $this->notifications->deliveries($this->tenantA, 1, 20)['total']);
        self::assertSame(0, $this->notifications->deliveries($this->tenantB, 1, 20)['total']);
    }

    #[Test]
    public function the_delivery_log_does_not_hand_out_addresses(): void
    {
        // It is an admin screen for whether things arrived, not a way to read
        // everybody's email address off a course roster.
        $this->raise(NotificationType::EnrollmentAssigned, ['subjectTitle' => 'Fire Safety']);

        foreach ($this->notifications->deliveries($this->tenantA, 1, 20)['items'] as $row) {
            self::assertArrayNotHasKey('address', $row);
        }
    }

    // --- preferences ------------------------------------------------------

    #[Test]
    public function a_preference_round_trips(): void
    {
        $this->notifications->savePreference(new NotificationPreference(
            null,
            $this->tenantA,
            $this->learner,
            NotificationType::CourseCompleted,
            NotificationChannel::Email,
            false,
        ));

        $stored = $this->notifications->preferencesFor($this->tenantA, $this->learner);

        self::assertCount(1, $stored);
        self::assertFalse($stored[0]->enabled);
    }

    #[Test]
    public function saving_the_same_preference_twice_updates_rather_than_duplicates(): void
    {
        $preference = new NotificationPreference(
            null,
            $this->tenantA,
            $this->learner,
            NotificationType::CourseCompleted,
            NotificationChannel::Email,
            false,
        );

        $this->notifications->savePreference($preference);
        $this->notifications->savePreference(new NotificationPreference(
            null,
            $this->tenantA,
            $this->learner,
            NotificationType::CourseCompleted,
            NotificationChannel::Email,
            true,
        ));

        $stored = $this->notifications->preferencesFor($this->tenantA, $this->learner);

        self::assertCount(1, $stored);
        self::assertTrue($stored[0]->enabled);
    }

    #[Test]
    public function a_channel_wide_row_and_a_type_row_are_separate(): void
    {
        // Two partial unique indexes, because NULL is not equal to itself and
        // one plain index would let channel-wide rows pile up.
        $this->notifications->savePreference(new NotificationPreference(
            null,
            $this->tenantA,
            $this->learner,
            null,
            NotificationChannel::Email,
            false,
        ));
        $this->notifications->savePreference(new NotificationPreference(
            null,
            $this->tenantA,
            $this->learner,
            NotificationType::SessionReminder,
            NotificationChannel::Email,
            true,
        ));

        self::assertCount(2, $this->notifications->preferencesFor($this->tenantA, $this->learner));
    }

    #[Test]
    public function saving_a_channel_wide_row_twice_updates_rather_than_duplicates(): void
    {
        foreach ([false, true] as $enabled) {
            $this->notifications->savePreference(new NotificationPreference(
                null,
                $this->tenantA,
                $this->learner,
                null,
                NotificationChannel::Email,
                $enabled,
            ));
        }

        $stored = $this->notifications->preferencesFor($this->tenantA, $this->learner);

        self::assertCount(1, $stored);
        self::assertTrue($stored[0]->enabled);
    }

    #[Test]
    public function quiet_hours_survive_the_round_trip(): void
    {
        $this->notifications->saveQuietHours(
            $this->tenantA,
            $this->learner,
            QuietHours::between('22:00', '07:00'),
        );

        $recipient = $this->notifications->recipients($this->tenantA, [$this->learner])[$this->learner];

        self::assertNotNull($recipient->quietHours);
        self::assertSame('22:00', $recipient->quietHours->start());
        self::assertTrue($recipient->quietHours->wrapsMidnight());
    }

    #[Test]
    public function quiet_hours_can_be_cleared(): void
    {
        $this->notifications->saveQuietHours($this->tenantA, $this->learner, QuietHours::between('22:00', '07:00'));
        $this->notifications->saveQuietHours($this->tenantA, $this->learner, null);

        $recipient = $this->notifications->recipients($this->tenantA, [$this->learner])[$this->learner];

        self::assertNull($recipient->quietHours);
    }

    #[Test]
    public function recipients_carry_their_preferences(): void
    {
        $this->notifications->savePreference(new NotificationPreference(
            null,
            $this->tenantA,
            $this->learner,
            NotificationType::CourseCompleted,
            NotificationChannel::Email,
            false,
        ));

        $recipient = $this->notifications->recipients($this->tenantA, [$this->learner])[$this->learner];

        self::assertFalse($recipient->allows(NotificationType::CourseCompleted, NotificationChannel::Email));
        self::assertTrue($recipient->allows(NotificationType::Welcome, NotificationChannel::Email));
    }

    #[Test]
    public function another_tenants_user_is_not_a_recipient(): void
    {
        $stranger = $this->createUser($this->tenantB, 'stranger');

        self::assertSame([], $this->notifications->recipients($this->tenantA, [$stranger]));
    }

    // --- entitlements -----------------------------------------------------

    #[Test]
    public function billable_channels_are_off_until_bought(): void
    {
        // Absent means not entitled. Defaulting the other way puts a tenant
        // into per-message billing because a column was missing.
        self::assertSame([], $this->notifications->entitledChannels($this->tenantA));
    }

    #[Test]
    public function an_entitled_channel_is_reported(): void
    {
        self::$connection?->executeStatement(
            "UPDATE tenants SET entitlements = '{\"notifications.sms\": true}'::jsonb WHERE id = :id",
            ['id' => $this->tenantA],
        );

        self::assertSame(
            [NotificationChannel::Sms],
            $this->notifications->entitledChannels($this->tenantA),
        );
    }

    // --- schedules --------------------------------------------------------

    #[Test]
    public function schedules_are_created_from_a_programmes_offsets(): void
    {
        $schedules = $this->sync(Uuid::uuid4()->toString(), [1440, 60]);

        self::assertCount(2, $schedules);
        self::assertSame([1440, 60], array_map(
            static fn (NotificationSchedule $s): int => $s->offsetMinutes,
            $schedules,
        ));
    }

    #[Test]
    public function syncing_twice_does_not_double_book(): void
    {
        // Called on every save of a session; a second insert would send the
        // reminder twice.
        $sessionId = Uuid::uuid4()->toString();

        $this->sync($sessionId, [1440, 60]);
        $again = $this->sync($sessionId, [1440, 60]);

        self::assertCount(2, $again);
    }

    #[Test]
    public function a_schedule_that_already_fired_is_not_reopened_by_saving_again(): void
    {
        $sessionId = Uuid::uuid4()->toString();
        $schedules = $this->sync($sessionId, [60]);

        $this->notifications->markScheduleExpanded((string) $schedules[0]->id, new \DateTimeImmutable(), 12);

        $again = $this->sync($sessionId, [60]);

        self::assertSame(ScheduleStatus::Expanded, $again[0]->status);
        self::assertSame(12, $again[0]->recipients);
    }

    #[Test]
    public function an_offset_no_longer_configured_is_cancelled(): void
    {
        $sessionId = Uuid::uuid4()->toString();

        $this->sync($sessionId, [1440, 60]);
        $this->sync($sessionId, [60]);

        $statuses = [];

        foreach ($this->notifications->schedulesFor($this->tenantA, 'training_session', $sessionId) as $schedule) {
            $statuses[$schedule->offsetMinutes] = $schedule->status;
        }

        self::assertSame(ScheduleStatus::Cancelled, $statuses[1440]);
        self::assertSame(ScheduleStatus::Pending, $statuses[60]);
    }

    #[Test]
    public function cancelling_a_session_stops_its_pending_reminders(): void
    {
        $sessionId = Uuid::uuid4()->toString();
        $this->sync($sessionId, [1440, 60]);

        self::assertSame(2, $this->notifications->cancelSchedules($this->tenantA, 'training_session', $sessionId));

        foreach ($this->notifications->schedulesFor($this->tenantA, 'training_session', $sessionId) as $schedule) {
            self::assertSame(ScheduleStatus::Cancelled, $schedule->status);
        }
    }

    #[Test]
    public function a_skipped_schedule_records_why(): void
    {
        // "Why did nobody get a reminder" needs an answer in the data.
        $schedules = $this->sync(Uuid::uuid4()->toString(), [60]);

        $this->notifications->markScheduleSkipped((string) $schedules[0]->id, 'The session had already started.');

        $read = $this->notifications->schedulesFor(
            $this->tenantA,
            'training_session',
            $schedules[0]->subjectId,
        )[0];

        self::assertSame(ScheduleStatus::Skipped, $read->status);
        self::assertSame('The session had already started.', $read->error);
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    private function raise(
        NotificationType $type,
        array $data = [],
        ?string $userId = null,
        ?string $at = null,
    ): Notification {
        $notification = Notification::raise(
            $this->tenantA,
            $userId ?? $this->learner,
            $type,
            $data,
            $this->composer,
            $at === null ? new \DateTimeImmutable() : new \DateTimeImmutable($at),
        );

        $recipient = $this->notifications->recipients(
            $this->tenantA,
            [$userId ?? $this->learner],
        )[$userId ?? $this->learner];

        $deliveries = array_map(
            fn ($decision): NotificationDelivery => NotificationDelivery::from(
                $this->tenantA,
                null,
                $decision,
                $recipient,
                new \DateTimeImmutable(),
            ),
            (new ChannelRouter())->route($type, $recipient, new \DateTimeImmutable()),
        );

        return $this->notifications->raise($notification, $deliveries);
    }

    /**
     * @param list<NotificationDelivery> $deliveries
     */
    private function raiseWithDeliveries(NotificationType $type, array $deliveries): Notification
    {
        return $this->notifications->raise(
            Notification::raise(
                $this->tenantA,
                $this->learner,
                $type,
                ['subjectTitle' => 'Python Q&A'],
                $this->composer,
                new \DateTimeImmutable(),
            ),
            $deliveries,
        );
    }

    private function delivery(
        NotificationChannel $channel,
        DeliveryStatus $status = DeliveryStatus::Pending,
    ): NotificationDelivery {
        return new NotificationDelivery(
            id: null,
            tenantId: $this->tenantA,
            notificationId: null,
            channel: $channel,
            address: $channel === NotificationChannel::InApp ? null : 'learner@ntf.test',
            status: $status,
            error: $status === DeliveryStatus::Skipped ? 'No mobile number on record.' : null,
        );
    }

    /**
     * @param  list<int> $offsets
     * @return list<NotificationSchedule>
     */
    private function sync(string $sessionId, array $offsets): array
    {
        return $this->notifications->syncSchedules(
            $this->tenantA,
            'training_session',
            $sessionId,
            NotificationType::SessionReminder,
            NotificationSchedule::forSubject(
                $this->tenantA,
                NotificationType::SessionReminder,
                'training_session',
                $sessionId,
                $offsets,
                [NotificationChannel::InApp, NotificationChannel::Email],
            ),
        );
    }

    private function deliveryOn(Notification $notification, NotificationChannel $channel): NotificationDelivery
    {
        foreach ($notification->deliveries as $delivery) {
            if ($delivery->channel === $channel) {
                return $delivery;
            }
        }

        self::fail(sprintf('The notification has no %s delivery.', $channel->value));
    }
    /**
     * @return array<string, mixed>
     */
    private function deliveryRow(string $id): array
    {
        $row = self::$connection?->fetchAssociative(
            'SELECT * FROM notification_deliveries WHERE id = :id',
            ['id' => $id],
        );

        self::assertIsArray($row);

        return $row;
    }

    private function expire(string $notificationId): void
    {
        self::$connection?->executeStatement(
            // created_at moves too: the check constraint insists an expiry
            // is after the creation, which is the point of it.
            "UPDATE notifications
                SET created_at = NOW() - interval '2 hours', expires_at = NOW() - interval '1 hour'
              WHERE id = :id",
            ['id' => $notificationId],
        );
    }

    private function defer(string $notificationId, string $by): void
    {
        self::$connection?->executeStatement(
            'UPDATE notification_deliveries SET scheduled_for = :when WHERE notification_id = :id',
            [
                'id'   => $notificationId,
                'when' => (new \DateTimeImmutable($by))->format(\DateTimeInterface::ATOM),
            ],
        );
    }

    /**
     * Claims, and keeps only what this test raised.
     *
     * `claimDue()` is table-wide on purpose — a worker drains everything
     * that is due, whoever it belongs to. This database is shared with the
     * end-to-end suites, which leave real deliveries behind, so filtering
     * belongs here rather than in the method under test.
     *
     * @return list<PendingDelivery>
     */
    private function claimMine(string $worker, string $at = 'now'): array
    {
        return array_values(array_filter(
            $this->notifications->claimDue($worker, new \DateTimeImmutable($at), 50),
            fn ($delivery): bool => $delivery->tenantId === $this->tenantA,
        ));
    }

    /**
     * Pulls the backoff forward so the retry path can be walked without
     * waiting minutes for it.
     *
     * Scoped to this test's tenant. Unscoped, it dragged every pending
     * delivery in the database into the past — the end-to-end suites leave
     * some there — and the retry test, the only one that claims with a
     * future clock, would then walk somebody else's row through the three
     * attempts and assert on that instead. It passed or failed depending on
     * how many attempts that row had left, which is what made it flake.
     */
    private function undefer(): void
    {
        self::$connection?->executeStatement(
            "UPDATE notification_deliveries SET scheduled_for = NOW() - interval '1 minute'
             WHERE status = 'pending' AND tenant_id = :tenant",
            ['tenant' => $this->tenantA],
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

    private function createUser(string $tenantId, string $label): string
    {
        $id = Uuid::uuid4()->toString();

        self::$connection?->executeStatement(
            "INSERT INTO users (id, tenant_id, email, password_hash, first_name, last_name, status)
             VALUES (:id, :tenant, :email, 'x', :first, 'Test', 'active')",
            [
                'id'     => $id,
                'tenant' => $tenantId,
                'email'  => $label . '-' . substr($id, 0, 8) . '@ntf.test',
                'first'  => ucfirst($label),
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
