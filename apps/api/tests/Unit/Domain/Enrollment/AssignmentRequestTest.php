<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Enrollment;

use Central\Api\Domain\Enrollment\AssignmentRequest;
use Central\Api\Domain\Enrollment\EnrollmentSource;
use Central\Api\Domain\Exception\ValidationFailed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AssignmentRequest::class)]
final class AssignmentRequestTest extends TestCase
{
    #[Test]
    public function one_named_learner_is_a_direct_assignment(): void
    {
        $request = AssignmentRequest::fromInput(['userIds' => ['u1']]);

        self::assertSame(EnrollmentSource::Direct, $request->source);
        self::assertSame(['u1'], $request->userIds);
    }

    #[Test]
    public function several_named_learners_is_a_bulk_assignment(): void
    {
        // The distinction the legacy reports were already drawing.
        $request = AssignmentRequest::fromInput(['userIds' => ['u1', 'u2']]);

        self::assertSame(EnrollmentSource::Bulk, $request->source);
    }

    #[Test]
    #[DataProvider('groupTargets')]
    public function a_group_target_carries_its_kind(string $field, EnrollmentSource $expected): void
    {
        $request = AssignmentRequest::fromInput([$field => 'g1']);

        self::assertSame($expected, $request->source);
        self::assertSame('g1', $request->groupId);
        self::assertSame([], $request->userIds);
    }

    /**
     * @return iterable<string, array{string, EnrollmentSource}>
     */
    public static function groupTargets(): iterable
    {
        yield 'department' => ['departmentId', EnrollmentSource::Department];
        yield 'team' => ['teamId', EnrollmentSource::Team];
        yield 'branch' => ['branchId', EnrollmentSource::Branch];
    }

    #[Test]
    public function naming_nobody_is_rejected(): void
    {
        $this->expectException(ValidationFailed::class);

        AssignmentRequest::fromInput(['isMandatory' => true]);
    }

    #[Test]
    public function an_empty_user_list_counts_as_naming_nobody(): void
    {
        $this->expectException(ValidationFailed::class);

        AssignmentRequest::fromInput(['userIds' => []]);
    }

    #[Test]
    public function naming_two_kinds_of_target_is_rejected(): void
    {
        // Accepting both would leave "which won?" to whichever branch ran
        // first, which is how the legacy version behaved.
        try {
            AssignmentRequest::fromInput(['userIds' => ['u1'], 'departmentId' => 'd1']);
            self::fail('Expected ValidationFailed.');
        } catch (ValidationFailed $e) {
            self::assertArrayHasKey('target', $e->details()['violations']);
        }
    }

    #[Test]
    public function duplicate_ids_are_collapsed(): void
    {
        $request = AssignmentRequest::fromInput(['userIds' => ['u1', 'u1', 'u2']]);

        self::assertSame(['u1', 'u2'], $request->userIds);
    }

    #[Test]
    public function a_list_that_is_duplicates_of_one_person_is_still_a_direct_assignment(): void
    {
        self::assertSame(
            EnrollmentSource::Direct,
            AssignmentRequest::fromInput(['userIds' => ['u1', 'u1']])->source,
        );
    }

    #[Test]
    public function blank_and_non_scalar_ids_are_dropped(): void
    {
        $request = AssignmentRequest::fromInput(['userIds' => ['u1', '', '   ', ['nested']]]);

        self::assertSame(['u1'], $request->userIds);
    }

    #[Test]
    public function an_unparseable_due_date_is_rejected_rather_than_ignored(): void
    {
        // Silently dropping it would produce an assignment with no deadline
        // that the administrator believes has one.
        try {
            AssignmentRequest::fromInput(['userIds' => ['u1'], 'dueDate' => 'next Thursdayish']);
            self::fail('Expected ValidationFailed.');
        } catch (ValidationFailed $e) {
            self::assertArrayHasKey('dueDate', $e->details()['violations']);
        }
    }

    #[Test]
    public function an_absent_due_date_is_allowed(): void
    {
        self::assertNull(AssignmentRequest::fromInput(['userIds' => ['u1']])->dueDate);
        self::assertNull(AssignmentRequest::fromInput(['userIds' => ['u1'], 'dueDate' => ''])->dueDate);
    }

    #[Test]
    public function a_due_date_is_parsed(): void
    {
        $request = AssignmentRequest::fromInput(['userIds' => ['u1'], 'dueDate' => '2026-09-01']);

        self::assertSame('2026-09-01', $request->dueDate?->format('Y-m-d'));
    }

    #[Test]
    public function assignments_are_mandatory_unless_said_otherwise(): void
    {
        self::assertTrue(AssignmentRequest::fromInput(['userIds' => ['u1']])->isMandatory);
        self::assertFalse(
            AssignmentRequest::fromInput(['userIds' => ['u1'], 'isMandatory' => false])->isMandatory,
        );
    }

    #[Test]
    public function self_enrolment_is_never_mandatory(): void
    {
        // Nobody makes a course compulsory for themselves, and marking it so
        // would put it on their own compliance list.
        $request = AssignmentRequest::selfEnrolment('u1');

        self::assertSame(EnrollmentSource::SelfEnrolled, $request->source);
        self::assertSame(['u1'], $request->userIds);
        self::assertFalse($request->isMandatory);
        self::assertNull($request->dueDate);
    }
}
