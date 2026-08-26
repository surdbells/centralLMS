<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Enrollment;

use Central\Api\Domain\Enrollment\Enrollment;
use Central\Api\Domain\Enrollment\EnrollmentSource;
use Central\Api\Domain\Enrollment\EnrollmentStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Enrollment::class)]
#[CoversClass(EnrollmentStatus::class)]
#[CoversClass(EnrollmentSource::class)]
final class EnrollmentTest extends TestCase
{
    private static function make(
        EnrollmentStatus $status = EnrollmentStatus::InProgress,
        ?string $dueDate = '2026-06-10',
    ): Enrollment {
        return new Enrollment(
            id: 'e1',
            tenantId: 't1',
            courseId: 'c1',
            userId: 'u1',
            status: $status,
            dueDate: $dueDate === null ? null : new \DateTimeImmutable($dueDate),
        );
    }

    // --- overdue ----------------------------------------------------------

    #[Test]
    public function an_unfinished_enrolment_past_its_date_is_overdue(): void
    {
        self::assertTrue(self::make()->isOverdue(new \DateTimeImmutable('2026-06-11')));
    }

    #[Test]
    public function a_completed_enrolment_is_never_overdue(): void
    {
        // Reporting someone who finished as late is how a compliance
        // dashboard loses its audience.
        self::assertFalse(
            self::make(EnrollmentStatus::Completed)->isOverdue(new \DateTimeImmutable('2027-01-01')),
        );
    }

    #[Test]
    public function a_dropped_enrolment_is_never_overdue(): void
    {
        self::assertFalse(
            self::make(EnrollmentStatus::Dropped)->isOverdue(new \DateTimeImmutable('2027-01-01')),
        );
    }

    #[Test]
    public function an_enrolment_with_no_due_date_is_never_overdue(): void
    {
        self::assertFalse(self::make(dueDate: null)->isOverdue(new \DateTimeImmutable('2099-01-01')));
    }

    #[Test]
    public function the_due_date_is_a_day_not_an_instant(): void
    {
        // Something due today is not overdue at nine in the morning.
        $enrollment = self::make(dueDate: '2026-06-10');

        self::assertFalse($enrollment->isOverdue(new \DateTimeImmutable('2026-06-10 09:00:00')));
        self::assertSame(0, $enrollment->daysRemaining(new \DateTimeImmutable('2026-06-10 23:00:00')));
    }

    #[Test]
    public function days_remaining_goes_negative_once_the_date_has_passed(): void
    {
        $enrollment = self::make();

        self::assertSame(5, $enrollment->daysRemaining(new \DateTimeImmutable('2026-06-05')));
        self::assertSame(-3, $enrollment->daysRemaining(new \DateTimeImmutable('2026-06-13')));
    }

    #[Test]
    public function days_remaining_is_null_when_no_deadline_can_apply(): void
    {
        // Not 0 — a client rendering "0 days left" for a finished course is
        // exactly the bug this prevents.
        self::assertNull(self::make(EnrollmentStatus::Completed)->daysRemaining(new \DateTimeImmutable()));
        self::assertNull(self::make(dueDate: null)->daysRemaining(new \DateTimeImmutable()));
    }

    // --- status semantics -------------------------------------------------

    #[Test]
    public function only_a_dropped_learner_loses_access(): void
    {
        // A completed learner may revisit the material; that is the point of
        // keeping the enrolment.
        self::assertTrue(EnrollmentStatus::Completed->grantsAccess());
        self::assertTrue(EnrollmentStatus::InProgress->grantsAccess());
        self::assertTrue(EnrollmentStatus::NotStarted->grantsAccess());
        self::assertFalse(EnrollmentStatus::Dropped->grantsAccess());
    }

    #[Test]
    public function an_unknown_status_reads_as_not_started(): void
    {
        self::assertSame(EnrollmentStatus::NotStarted, EnrollmentStatus::fromLegacy('whatever'));
        self::assertSame(EnrollmentStatus::NotStarted, EnrollmentStatus::fromLegacy(null));
        self::assertSame(EnrollmentStatus::Completed, EnrollmentStatus::fromLegacy('COMPLETED'));
    }

    // --- source -----------------------------------------------------------

    #[Test]
    #[DataProvider('legacySources')]
    public function the_four_legacy_source_spellings_fold_onto_the_enum(
        string $input,
        EnrollmentSource $expected,
    ): void {
        self::assertSame($expected, EnrollmentSource::fromLegacy($input));
    }

    /**
     * @return iterable<string, array{string, EnrollmentSource}>
     */
    public static function legacySources(): iterable
    {
        // All four appear verbatim in the live data, across two conventions.
        yield 'Bulk Assignment' => ['Bulk Assignment', EnrollmentSource::Bulk];
        yield 'Team Assignment' => ['Team Assignment', EnrollmentSource::Team];
        yield 'Branch Assignment' => ['Branch Assignment', EnrollmentSource::Branch];
        yield 'self_enrollment' => ['self_enrollment', EnrollmentSource::SelfEnrolled];

        yield 'Direct Assignment' => ['Direct Assignment', EnrollmentSource::Direct];
        yield 'Department Assignment' => ['Department Assignment', EnrollmentSource::Department];
        yield 'already canonical' => ['branch', EnrollmentSource::Branch];
        yield 'unknown falls back to direct' => ['carrier pigeon', EnrollmentSource::Direct];
    }

    #[Test]
    public function only_group_sources_name_a_group(): void
    {
        self::assertSame('team', EnrollmentSource::Team->groupKind());
        self::assertSame('branch', EnrollmentSource::Branch->groupKind());
        self::assertSame('department', EnrollmentSource::Department->groupKind());
        self::assertNull(EnrollmentSource::Bulk->groupKind());
        self::assertNull(EnrollmentSource::Direct->groupKind());
        self::assertNull(EnrollmentSource::SelfEnrolled->groupKind());
    }

    #[Test]
    public function every_group_kind_is_a_real_lookup_kind(): void
    {
        // These are interpolated into a column name in the repository, so the
        // set must stay closed.
        foreach (EnrollmentSource::cases() as $source) {
            $kind = $source->groupKind();

            if ($kind !== null) {
                self::assertContains($kind, ['team', 'branch', 'department']);
            }
        }
    }

    #[Test]
    public function the_payload_reports_overdue_state_it_did_not_have_to_be_asked_for(): void
    {
        $payload = self::make(dueDate: '2000-01-01')->toArray();

        self::assertTrue($payload['isOverdue']);
        self::assertSame('2000-01-01', $payload['dueDate']);
        self::assertSame('In progress', $payload['statusLabel']);
    }
}
