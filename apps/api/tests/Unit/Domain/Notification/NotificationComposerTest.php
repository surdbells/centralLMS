<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Notification;

use Central\Api\Domain\Notification\Notification;
use Central\Api\Domain\Notification\NotificationComposer;
use Central\Api\Domain\Notification\NotificationPriority;
use Central\Api\Domain\Notification\NotificationType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The words people read.
 *
 * Composed once, at raise time, and stored — so a course renamed next year
 * does not rewrite what somebody was told today.
 */
#[CoversClass(NotificationComposer::class)]
#[CoversClass(Notification::class)]
#[CoversClass(NotificationType::class)]
final class NotificationComposerTest extends TestCase
{
    private NotificationComposer $composer;

    protected function setUp(): void
    {
        $this->composer = new NotificationComposer();
    }

    #[Test]
    public function every_type_composes_without_blowing_up(): void
    {
        // The match is exhaustive, so a new case that nobody wrote wording
        // for fails here rather than in production.
        foreach (NotificationType::cases() as $type) {
            $words = $this->composer->compose($type, [
                'subjectTitle' => 'Fire Safety',
                'title'        => 'Office closed',
                'body'         => 'The office is closed on Monday.',
            ]);

            self::assertNotSame('', trim($words['title']), $type->value . ' has no title');
        }
    }

    #[Test]
    public function the_subject_title_appears_in_the_wording(): void
    {
        $words = $this->composer->compose(
            NotificationType::EnrollmentAssigned,
            ['subjectTitle' => 'Manual Handling'],
        );

        self::assertStringContainsString('Manual Handling', $words['title']);
    }

    #[Test]
    public function a_due_date_is_included_when_there_is_one(): void
    {
        $words = $this->composer->compose(NotificationType::EnrollmentAssigned, [
            'subjectTitle' => 'Manual Handling',
            'dueDate'      => '1 Apr 2026',
        ]);

        self::assertStringContainsString('due on 1 Apr 2026', $words['body']);
    }

    #[Test]
    public function a_whole_number_score_reads_without_a_trailing_zero(): void
    {
        $words = $this->composer->compose(NotificationType::AssessmentGraded, [
            'subjectTitle' => 'GDPR',
            'percentage'   => 80.0,
        ]);

        self::assertStringContainsString('You scored 80%.', $words['body']);
        self::assertStringNotContainsString('80.0', $words['body']);
    }

    #[Test]
    public function a_fractional_score_keeps_its_decimal(): void
    {
        $words = $this->composer->compose(NotificationType::AssessmentGraded, [
            'subjectTitle' => 'GDPR',
            'percentage'   => 66.5,
        ]);

        self::assertStringContainsString('66.5%', $words['body']);
    }

    #[Test]
    public function passing_is_said_plainly_in_the_title(): void
    {
        $passed = $this->composer->compose(NotificationType::AssessmentGraded, [
            'subjectTitle' => 'GDPR',
            'passed'       => true,
        ]);

        $marked = $this->composer->compose(NotificationType::AssessmentGraded, [
            'subjectTitle' => 'GDPR',
            'passed'       => false,
        ]);

        self::assertStringContainsString('You passed', $passed['title']);
        self::assertStringNotContainsString('You passed', $marked['title']);
    }

    #[Test]
    public function a_reminders_lead_time_is_described_in_the_right_unit(): void
    {
        $cases = [
            1440 => 'starts in 1 day',
            2880 => 'starts in 2 days',
            60   => 'starts in 1 hour',
            120  => 'starts in 2 hours',
            15   => 'starts in 15 minutes',
            0    => 'is starting now',
        ];

        foreach ($cases as $minutes => $expected) {
            $words = $this->composer->compose(NotificationType::SessionReminder, [
                'subjectTitle'  => 'Python Q&A',
                'offsetMinutes' => $minutes,
            ]);

            self::assertStringContainsString($expected, $words['title'], (string) $minutes);
        }
    }

    #[Test]
    public function a_cancellation_carries_its_reason(): void
    {
        $words = $this->composer->compose(NotificationType::SessionCancelled, [
            'subjectTitle' => 'Python Q&A',
            'reason'       => 'The instructor is unwell.',
        ]);

        self::assertStringContainsString('The instructor is unwell.', $words['body']);
        self::assertStringContainsString('Do not travel to it.', $words['body']);
    }

    #[Test]
    public function a_cancellation_without_a_reason_still_says_the_useful_part(): void
    {
        $words = $this->composer->compose(
            NotificationType::SessionCancelled,
            ['subjectTitle' => 'Python Q&A'],
        );

        self::assertStringContainsString('Do not travel to it.', $words['body']);
    }

    #[Test]
    public function missing_wording_data_is_reported_rather_than_papered_over(): void
    {
        // "You have been assigned the course" is worse than no notification:
        // the learner cannot tell which course is meant.
        self::assertSame(
            ['subjectTitle'],
            $this->composer->missingData(NotificationType::EnrollmentAssigned, []),
        );

        self::assertSame(
            [],
            $this->composer->missingData(NotificationType::EnrollmentAssigned, ['subjectTitle' => 'X']),
        );
    }

    #[Test]
    public function a_blank_subject_title_counts_as_missing(): void
    {
        self::assertSame(
            ['subjectTitle'],
            $this->composer->missingData(NotificationType::CourseCompleted, ['subjectTitle' => '   ']),
        );
    }

    #[Test]
    public function welcome_needs_nothing(): void
    {
        self::assertSame([], $this->composer->missingData(NotificationType::Welcome, []));
    }

    // --- raising ----------------------------------------------------------

    #[Test]
    public function raising_stores_the_composed_wording(): void
    {
        $notification = $this->raise(NotificationType::CourseCompleted, ['subjectTitle' => 'Fire Safety']);

        self::assertStringContainsString('Fire Safety', $notification->title);
        self::assertSame(NotificationPriority::Low, $notification->priority);
    }

    #[Test]
    public function a_reminder_expires_so_it_does_not_clutter_the_bell(): void
    {
        $notification = $this->raise(
            NotificationType::SessionReminder,
            ['subjectTitle' => 'Python Q&A'],
        );

        self::assertNotNull($notification->expiresAt);
        self::assertSame('2026-03-03', $notification->expiresAt->format('Y-m-d'));
    }

    #[Test]
    public function a_certificate_notification_does_not_expire(): void
    {
        $notification = $this->raise(
            NotificationType::CertificateIssued,
            ['subjectTitle' => 'Fire Safety'],
        );

        self::assertNull($notification->expiresAt);
    }

    #[Test]
    public function an_expired_notification_stops_being_shown(): void
    {
        $notification = $this->raise(
            NotificationType::SessionReminder,
            ['subjectTitle' => 'Python Q&A'],
        );

        self::assertFalse($notification->hasExpired(new \DateTimeImmutable('2026-03-02 12:00')));
        self::assertTrue($notification->hasExpired(new \DateTimeImmutable('2026-03-04 12:00')));
    }

    #[Test]
    public function a_subject_id_without_a_matching_subject_type_is_dropped(): void
    {
        // The schema rejects half a reference, so the pair is resolved
        // together rather than trusting the caller to match them.
        $notification = Notification::raise(
            'tenant-1',
            'user-1',
            NotificationType::Welcome,
            [],
            $this->composer,
            new \DateTimeImmutable('2026-03-01 12:00'),
            subjectId: 'course-1',
        );

        self::assertNull($notification->subjectType);
        self::assertNull($notification->subjectId);
    }

    #[Test]
    public function a_subject_id_is_kept_when_the_type_has_a_subject(): void
    {
        $notification = $this->raise(
            NotificationType::CourseCompleted,
            ['subjectTitle' => 'Fire Safety'],
            'course-1',
        );

        self::assertSame('course', $notification->subjectType);
        self::assertSame('course-1', $notification->subjectId);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function raise(NotificationType $type, array $data, ?string $subjectId = null): Notification
    {
        return Notification::raise(
            'tenant-1',
            'user-1',
            $type,
            $data,
            $this->composer,
            new \DateTimeImmutable('2026-03-01 12:00'),
            $subjectId,
        );
    }
}
