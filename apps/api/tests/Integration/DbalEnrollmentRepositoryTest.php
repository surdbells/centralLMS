<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Catalog\Course;
use Central\Api\Domain\Catalog\CourseModule;
use Central\Api\Domain\Catalog\CourseStatus;
use Central\Api\Domain\Catalog\Lesson;
use Central\Api\Domain\Catalog\LessonContentType;
use Central\Api\Domain\Enrollment\AssignmentRequest;
use Central\Api\Domain\Enrollment\Enrollment;
use Central\Api\Domain\Enrollment\EnrollmentSource;
use Central\Api\Domain\Enrollment\EnrollmentStatus;
use Central\Api\Domain\Enrollment\LessonProgress;
use Central\Api\Domain\Enrollment\ProgressStatus;
use Central\Api\Infrastructure\Persistence\DbalCourseRepository;
use Central\Api\Infrastructure\Persistence\DbalEnrollmentRepository;
use Central\Api\Infrastructure\Persistence\DbalLessonProgressRepository;
use Central\Api\Infrastructure\Persistence\DbalLessonRepository;
use Central\Api\Infrastructure\Persistence\DbalModuleRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Enrolment and progress against a real PostgreSQL.
 *
 * The recalculation is the reason this file exists. Enrolment percentage is
 * derived from lesson rows inside the same transaction that writes them, and
 * the whole point is that the two can never be seen disagreeing — which is
 * precisely what the legacy data does, in 16 of its 159 rows.
 *
 * Skipped when the database is unreachable, so a checkout without the Docker
 * stack still gets a green suite.
 */
#[CoversClass(DbalEnrollmentRepository::class)]
#[CoversClass(DbalLessonProgressRepository::class)]
final class DbalEnrollmentRepositoryTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalEnrollmentRepository $enrollments;
    private DbalLessonProgressRepository $progress;
    private DbalCourseRepository $courses;
    private DbalModuleRepository $modules;
    private DbalLessonRepository $lessons;

    private string $tenantA;
    private string $tenantB;
    private Course $course;
    private Course $courseB;
    private string $moduleId;
    private string $learner;
    private string $otherLearner;
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

        $this->enrollments = new DbalEnrollmentRepository(self::$connection);
        $this->progress = new DbalLessonProgressRepository(self::$connection, $this->enrollments);
        $this->courses = new DbalCourseRepository(self::$connection);
        $this->modules = new DbalModuleRepository(self::$connection);
        $this->lessons = new DbalLessonRepository(self::$connection);

        $this->tenantA = $this->createTenant('enr-a');
        $this->tenantB = $this->createTenant('enr-b');

        $this->course = $this->courses->create(new Course(
            id: '',
            tenantId: $this->tenantA,
            title: 'Fire Safety',
            status: CourseStatus::Published,
        ));
        $this->courseB = $this->courses->create(new Course(
            id: '',
            tenantId: $this->tenantB,
            title: 'Their Course',
            status: CourseStatus::Published,
        ));

        $this->moduleId = $this->modules->create(new CourseModule(
            id: '',
            tenantId: $this->tenantA,
            courseId: $this->course->id,
            title: 'Module 1',
        ))->id;

        $this->learner = $this->createUser($this->tenantA, 'learner');
        $this->otherLearner = $this->createUser($this->tenantA, 'other');
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

            // users.tenant_id does not cascade from tenants, unlike courses,
            // lookups and enrollments, so a tenant with users in it cannot be
            // deleted. Noted in PROGRESS.md as an inconsistency to decide on;
            // until then the users go first.
            self::$connection->executeStatement(
                'DELETE FROM users WHERE tenant_id = :id',
                ['id' => $tenantId],
            );
            self::$connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $tenantId]);
        }
    }

    // --- assignment -------------------------------------------------------

    #[Test]
    public function assigning_a_list_enrols_everyone_on_it(): void
    {
        $outcome = $this->assign([$this->learner, $this->otherLearner]);

        self::assertCount(2, $outcome['enrolled']);
        self::assertSame(2, $this->enrollments->statsForCourse($this->tenantA, $this->course->id)['total']);
    }

    #[Test]
    public function assigning_twice_does_not_double_enrol_anyone(): void
    {
        $this->assign([$this->learner]);
        $second = $this->assign([$this->learner, $this->otherLearner]);

        // The already-enrolled learner is skipped, not an error — a 200-person
        // assignment where 30 are on it already must enrol the other 170.
        self::assertSame([$this->otherLearner], $second['enrolled']);
        self::assertSame([$this->learner], $second['alreadyEnrolled']);
        self::assertSame(2, $this->enrollments->statsForCourse($this->tenantA, $this->course->id)['total']);
    }

    #[Test]
    public function ids_that_are_not_users_in_this_tenant_are_reported_not_fatal(): void
    {
        $stranger = $this->createUser($this->tenantB, 'stranger');
        $ghost = Uuid::uuid4()->toString();

        $outcome = $this->assign([$this->learner, $stranger, $ghost]);

        // One stale id from a client must not lose the other assignments.
        self::assertSame([$this->learner], $outcome['enrolled']);
        self::assertEqualsCanonicalizing([$stranger, $ghost], $outcome['notFound']);
    }

    #[Test]
    public function assigning_a_whole_department_enrols_its_members_only(): void
    {
        $departmentId = $this->createLookup($this->tenantA, 'department', 'Operations');
        $this->putUserIn($this->learner, 'department_id', $departmentId);
        $this->putUserIn($this->otherLearner, 'department_id', $departmentId);
        // The admin is deliberately left out of the department.

        $outcome = $this->enrollments->assign(
            $this->tenantA,
            $this->course->id,
            AssignmentRequest::fromInput(['departmentId' => $departmentId]),
            $this->admin,
        );

        self::assertCount(2, $outcome['enrolled']);
        self::assertEqualsCanonicalizing([$this->learner, $this->otherLearner], $outcome['enrolled']);
    }

    #[Test]
    public function a_group_assignment_records_where_it_came_from(): void
    {
        $branchId = $this->createLookup($this->tenantA, 'branch', 'HQ');
        $this->putUserIn($this->learner, 'branch_id', $branchId);

        $this->enrollments->assign(
            $this->tenantA,
            $this->course->id,
            AssignmentRequest::fromInput(['branchId' => $branchId]),
            $this->admin,
        );

        $enrollment = $this->enrollment();

        self::assertSame(EnrollmentSource::Branch, $enrollment->source);
        self::assertSame($this->admin, $enrollment->assignedBy);
    }

    #[Test]
    public function an_empty_group_enrols_nobody_rather_than_everybody(): void
    {
        $emptyTeam = $this->createLookup($this->tenantA, 'team', 'Nobody');

        $outcome = $this->enrollments->assign(
            $this->tenantA,
            $this->course->id,
            AssignmentRequest::fromInput(['teamId' => $emptyTeam]),
            $this->admin,
        );

        self::assertSame([], $outcome['enrolled']);
        self::assertSame(0, $this->enrollments->statsForCourse($this->tenantA, $this->course->id)['total']);
    }

    #[Test]
    public function a_group_from_another_tenant_matches_nobody(): void
    {
        $theirBranch = $this->createLookup($this->tenantB, 'branch', 'Theirs');

        $outcome = $this->enrollments->assign(
            $this->tenantA,
            $this->course->id,
            AssignmentRequest::fromInput(['branchId' => $theirBranch]),
            $this->admin,
        );

        self::assertSame([], $outcome['enrolled']);
    }

    #[Test]
    public function the_due_date_and_mandatory_flag_are_applied(): void
    {
        $this->enrollments->assign(
            $this->tenantA,
            $this->course->id,
            AssignmentRequest::fromInput([
                'userIds'     => [$this->learner],
                'dueDate'     => '2026-12-01',
                'isMandatory' => false,
            ]),
            $this->admin,
        );

        $enrollment = $this->enrollment();

        self::assertSame('2026-12-01', $enrollment->dueDate?->format('Y-m-d'));
        self::assertFalse($enrollment->isMandatory);
    }

    // --- unenrolment and reinstatement -----------------------------------

    #[Test]
    public function unenrolling_keeps_the_row_and_its_progress(): void
    {
        $this->assign([$this->learner]);
        $lesson = $this->lesson('Only', mandatory: true);
        $this->record($lesson->id, 100.0);

        $removed = $this->enrollments->unenroll(
            $this->tenantA,
            $this->course->id,
            [$this->learner],
            $this->admin,
            'Left the company',
        );

        self::assertSame([$this->learner], $removed);

        $enrollment = $this->enrollment();

        self::assertSame(EnrollmentStatus::Dropped, $enrollment->status);
        // The work they did is still there.
        self::assertSame(100.0, $enrollment->progressPercentage);
        // ...but a dropped learner has not completed, and the check
        // constraint would reject the row otherwise.
        self::assertNull($enrollment->completedAt);
    }

    #[Test]
    public function reinstating_restores_where_they_had_got_to(): void
    {
        $this->assign([$this->learner]);
        $a = $this->lesson('A');
        $this->lesson('B');
        $this->record($a->id, 100.0);

        $this->enrollments->unenroll($this->tenantA, $this->course->id, [$this->learner], $this->admin);

        $outcome = $this->assign([$this->learner]);

        self::assertSame([$this->learner], $outcome['reinstated']);
        self::assertSame([], $outcome['enrolled']);

        $enrollment = $this->enrollment();

        // Halfway, not back to zero.
        self::assertSame(EnrollmentStatus::InProgress, $enrollment->status);
        self::assertSame(50.0, $enrollment->progressPercentage);
    }

    #[Test]
    public function a_dropped_course_leaves_the_learners_own_list(): void
    {
        $this->assign([$this->learner]);
        self::assertSame(1, $this->enrollments->forUser($this->tenantA, $this->learner, 1, 50)['total']);

        $this->enrollments->unenroll($this->tenantA, $this->course->id, [$this->learner], $this->admin);

        // Being taken off a course should remove it from "my courses"; the
        // row survives so the progress does, but it is not theirs to open.
        self::assertSame(0, $this->enrollments->forUser($this->tenantA, $this->learner, 1, 50)['total']);

        // ...and is still reachable when asked for by name, which is how an
        // administrator sees what happened.
        self::assertSame(
            1,
            $this->enrollments->forUser($this->tenantA, $this->learner, 1, 50, EnrollmentStatus::Dropped)['total'],
        );
    }

    #[Test]
    public function unenrolling_someone_who_is_not_on_the_course_reports_nothing_removed(): void
    {
        $this->assign([$this->learner]);

        self::assertSame(
            [],
            $this->enrollments->unenroll($this->tenantA, $this->course->id, [$this->otherLearner], $this->admin),
        );
    }

    #[Test]
    public function the_audit_trail_records_the_whole_sequence(): void
    {
        $this->assign([$this->learner]);
        $this->enrollments->unenroll($this->tenantA, $this->course->id, [$this->learner], $this->admin, 'Left');
        $this->assign([$this->learner]);

        $history = $this->enrollments->history($this->tenantA, $this->course->id, $this->learner);

        self::assertSame(
            ['reinstated', 'unenrolled', 'enrolled'],
            array_column($history, 'action'),
        );
        self::assertSame('Left', $history[1]['notes']);
    }

    // --- progress and recalculation ---------------------------------------

    #[Test]
    public function completing_every_mandatory_lesson_completes_the_course(): void
    {
        $this->assign([$this->learner]);
        $a = $this->lesson('A');
        $b = $this->lesson('B');

        $afterFirst = $this->record($a->id, 100.0);
        self::assertSame(50.0, $afterFirst->progressPercentage);
        self::assertSame(EnrollmentStatus::InProgress, $afterFirst->status);
        self::assertNotNull($afterFirst->startedAt);

        $afterSecond = $this->record($b->id, 100.0);
        self::assertSame(100.0, $afterSecond->progressPercentage);
        self::assertSame(EnrollmentStatus::Completed, $afterSecond->status);
        self::assertNotNull($afterSecond->completedAt);
    }

    #[Test]
    public function partial_progress_on_a_lesson_does_not_count_towards_the_course(): void
    {
        // The course percentage counts completed lessons, not an average of
        // part-finished ones: 90% of one lesson is not 45% of two.
        $this->assign([$this->learner]);
        $a = $this->lesson('A');
        $this->lesson('B');

        $enrollment = $this->record($a->id, 90.0);

        self::assertSame(0.0, $enrollment->progressPercentage);
        self::assertSame(EnrollmentStatus::NotStarted, $enrollment->status);
    }

    #[Test]
    public function optional_lessons_do_not_hold_completion_hostage(): void
    {
        $this->assign([$this->learner]);
        $required = $this->lesson('Required', mandatory: true);
        $this->lesson('Optional', mandatory: false);

        $enrollment = $this->record($required->id, 100.0);

        self::assertSame(100.0, $enrollment->progressPercentage);
        self::assertSame(EnrollmentStatus::Completed, $enrollment->status);
    }

    #[Test]
    public function a_course_of_only_optional_lessons_still_has_a_denominator(): void
    {
        // Otherwise it divides by zero and reads as complete before anyone
        // has opened it.
        $this->assign([$this->learner]);
        $a = $this->lesson('A', mandatory: false);
        $this->lesson('B', mandatory: false);

        self::assertSame(0.0, $this->enrollment()->progressPercentage);
        self::assertSame(50.0, $this->record($a->id, 100.0)->progressPercentage);
    }

    #[Test]
    public function progress_never_goes_backwards(): void
    {
        // A player reporting 30% after the learner reached 80% is reporting a
        // re-watch, not a regression.
        $this->assign([$this->learner]);
        $a = $this->lesson('A');

        $this->record($a->id, 80.0);
        $this->record($a->id, 30.0);

        $progress = $this->progress->find($this->tenantA, $this->enrollment()->id, $a->id);

        self::assertSame(80.0, $progress?->progressPercentage);
    }

    #[Test]
    public function re_watching_a_finished_lesson_does_not_move_its_completion_date(): void
    {
        $this->assign([$this->learner]);
        $a = $this->lesson('A');

        $first = $this->record($a->id, 100.0);
        $completedAt = $this->progress->find($this->tenantA, $first->id, $a->id)?->completedAt;

        $this->record($a->id, 50.0);

        self::assertEquals(
            $completedAt,
            $this->progress->find($this->tenantA, $this->enrollment()->id, $a->id)?->completedAt,
        );
    }

    #[Test]
    public function time_accumulates_across_reports(): void
    {
        $this->assign([$this->learner]);
        $a = $this->lesson('A');

        $this->record($a->id, 20.0, seconds: 60);
        $enrollment = $this->record($a->id, 40.0, seconds: 90);

        $progress = $this->progress->find($this->tenantA, $enrollment->id, $a->id);

        self::assertSame(150, $progress?->timeSpentSeconds);
        // ...and rolls up to the enrolment.
        self::assertSame(150, $enrollment->timeSpentSeconds);
    }

    #[Test]
    public function the_best_score_stands(): void
    {
        // Re-taking a quiz badly must not erase a pass a certificate cites.
        $this->assign([$this->learner]);
        $a = $this->lesson('Quiz');

        $this->record($a->id, 100.0, score: 90.0);
        $this->record($a->id, 100.0, score: 40.0);

        self::assertSame(90.0, $this->progress->find($this->tenantA, $this->enrollment()->id, $a->id)?->score);
    }

    #[Test]
    public function a_report_without_a_position_does_not_rewind_the_video(): void
    {
        $this->assign([$this->learner]);
        $a = $this->lesson('Video');

        $this->record($a->id, 20.0, position: 120);
        $this->record($a->id, 30.0);

        self::assertSame(
            120,
            $this->progress->find($this->tenantA, $this->enrollment()->id, $a->id)?->lastPositionSeconds,
        );
    }

    #[Test]
    public function recording_twice_never_creates_a_second_row(): void
    {
        $this->assign([$this->learner]);
        $a = $this->lesson('A');

        $this->record($a->id, 50.0);
        $this->record($a->id, 60.0);

        self::assertCount(1, $this->progress->forEnrollment($this->tenantA, $this->enrollment()->id));
    }

    #[Test]
    public function recording_progress_does_not_put_a_dropped_learner_back_on_the_course(): void
    {
        $this->assign([$this->learner]);
        $a = $this->lesson('A');
        $enrollment = $this->enrollment();

        $this->enrollments->unenroll($this->tenantA, $this->course->id, [$this->learner], $this->admin);

        $this->progress->record(
            $this->tenantA,
            $enrollment,
            $a->id,
            LessonProgress::reportFromInput(['completed' => true]),
        );

        // Only an assignment reinstates someone; progress does not.
        self::assertSame(EnrollmentStatus::Dropped, $this->enrollment()->status);
    }

    #[Test]
    public function the_enrolment_and_its_lessons_never_disagree(): void
    {
        // The invariant the legacy is_completed flag broke in 16 of 159 rows.
        $this->assign([$this->learner]);
        $a = $this->lesson('A');
        $b = $this->lesson('B');
        $c = $this->lesson('C');

        foreach ([[$a, 100.0], [$b, 100.0], [$c, 40.0]] as [$lesson, $percentage]) {
            $enrollment = $this->record($lesson->id, $percentage);

            $rows = $this->progress->forEnrollment($this->tenantA, $enrollment->id);
            $done = count(array_filter(
                $rows,
                static fn (LessonProgress $p): bool => $p->status === ProgressStatus::Completed,
            ));

            self::assertSame(
                round($done * 100 / 3, 2),
                $enrollment->progressPercentage,
                'The course percentage must always equal its completed lessons.',
            );
            self::assertSame(
                $enrollment->progressPercentage >= 100.0,
                $enrollment->status === EnrollmentStatus::Completed,
            );
        }
    }

    #[Test]
    public function deactivating_a_lesson_moves_everyone_who_was_stuck_on_it(): void
    {
        $this->assign([$this->learner]);
        $a = $this->lesson('A');
        $b = $this->lesson('B');

        $this->record($a->id, 100.0);
        self::assertSame(50.0, $this->enrollment()->progressPercentage);

        // The remaining lesson is withdrawn, so the course is now one lesson
        // long and the learner has finished it.
        $this->lessons->setActive($this->tenantA, $this->moduleId, $b->id, false);
        $this->progress->recalculateCourse($this->tenantA, $this->course->id);

        $enrollment = $this->enrollment();

        self::assertSame(100.0, $enrollment->progressPercentage);
        self::assertSame(EnrollmentStatus::Completed, $enrollment->status);
        self::assertNotNull($enrollment->completedAt);
    }

    #[Test]
    public function adding_a_lesson_moves_everyone_back_below_complete(): void
    {
        $this->assign([$this->learner]);
        $a = $this->lesson('A');
        $this->record($a->id, 100.0);

        self::assertSame(EnrollmentStatus::Completed, $this->enrollment()->status);

        $this->lesson('B');
        $this->progress->recalculateCourse($this->tenantA, $this->course->id);

        $enrollment = $this->enrollment();

        self::assertSame(50.0, $enrollment->progressPercentage);
        self::assertSame(EnrollmentStatus::InProgress, $enrollment->status);
        // The constraint requires this to be cleared, and it is the honest
        // answer: they have not finished the course as it now stands.
        self::assertNull($enrollment->completedAt);
    }

    #[Test]
    public function completion_is_logged_once_however_many_times_progress_is_recorded(): void
    {
        $this->assign([$this->learner]);
        $a = $this->lesson('A');

        $this->record($a->id, 100.0);
        $this->record($a->id, 100.0);
        $this->record($a->id, 100.0);

        $completions = array_filter(
            $this->enrollments->history($this->tenantA, $this->course->id, $this->learner),
            static fn (array $e): bool => $e['action'] === 'completed',
        );

        self::assertCount(1, $completions);
    }

    // --- tenant isolation -------------------------------------------------

    #[Test]
    public function another_tenant_cannot_see_the_enrolment(): void
    {
        $this->assign([$this->learner]);
        $enrollment = $this->enrollment();

        self::assertNull($this->enrollments->find($this->tenantB, $enrollment->id));
        self::assertNull(
            $this->enrollments->findForUserAndCourse($this->tenantB, $this->learner, $this->course->id),
        );
    }

    #[Test]
    public function another_tenants_course_shows_no_enrolments(): void
    {
        $this->assign([$this->learner]);

        self::assertSame(
            0,
            $this->enrollments->forCourse($this->tenantB, $this->course->id, 1, 50)['total'],
        );
        self::assertSame(0, $this->enrollments->statsForCourse($this->tenantB, $this->course->id)['total']);
    }

    #[Test]
    public function the_learner_list_is_scoped_to_the_tenant(): void
    {
        $this->assign([$this->learner]);

        self::assertSame(1, $this->enrollments->forUser($this->tenantA, $this->learner, 1, 50)['total']);
        self::assertSame(0, $this->enrollments->forUser($this->tenantB, $this->learner, 1, 50)['total']);
    }

    #[Test]
    public function a_tenant_cannot_assign_its_own_people_to_another_tenants_course(): void
    {
        // The course id is real and the learner is real; only the pairing is
        // wrong. The API resolves the course within the caller's tenant first
        // so this cannot arrive over HTTP, but the composite foreign key is
        // what makes the row unrepresentable rather than merely unreachable —
        // an import script bypassing the action layer hits the same wall.
        $this->expectException(\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException::class);

        $this->enrollments->assign(
            $this->tenantA,
            $this->courseB->id,
            AssignmentRequest::fromInput(['userIds' => [$this->learner]]),
            $this->admin,
        );
    }

    #[Test]
    public function progress_cannot_be_written_against_another_tenants_lesson(): void
    {
        $this->assign([$this->learner]);
        $enrollment = $this->enrollment();

        $theirModule = $this->modules->create(new CourseModule(
            id: '',
            tenantId: $this->tenantB,
            courseId: $this->courseB->id,
            title: 'Theirs',
        ));

        $theirLesson = $this->lessons->create(new Lesson(
            id: '',
            tenantId: $this->tenantB,
            courseId: $this->courseB->id,
            moduleId: $theirModule->id,
            title: 'Their Lesson',
            contentType: LessonContentType::Text,
            contentText: 'Body',
        ));

        $this->expectException(\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException::class);

        $this->progress->record(
            $this->tenantA,
            $enrollment,
            $theirLesson->id,
            LessonProgress::reportFromInput(['completed' => true]),
        );
    }

    #[Test]
    public function recalculating_another_tenants_enrolment_does_nothing(): void
    {
        $this->assign([$this->learner]);
        $enrollment = $this->enrollment();

        self::assertNull($this->progress->recalculate($this->tenantB, $enrollment->id));
    }

    // --- listing and statistics -------------------------------------------

    #[Test]
    public function the_course_list_carries_names_without_a_second_query(): void
    {
        $this->assign([$this->learner]);

        $row = $this->enrollments->forCourse($this->tenantA, $this->course->id, 1, 50)['items'][0];

        self::assertNotNull($row->userEmail);
        self::assertSame('Fire Safety', $row->courseTitle);
    }

    #[Test]
    public function the_course_list_can_be_searched_by_name_or_email(): void
    {
        $this->assign([$this->learner, $this->otherLearner]);

        self::assertSame(
            1,
            $this->enrollments->forCourse($this->tenantA, $this->course->id, 1, 50, null, 'other')['total'],
        );
    }

    #[Test]
    public function the_overdue_filter_excludes_those_who_finished_late(): void
    {
        $this->enrollments->assign(
            $this->tenantA,
            $this->course->id,
            AssignmentRequest::fromInput([
                'userIds' => [$this->learner, $this->otherLearner],
                'dueDate' => '2000-01-01',
            ]),
            $this->admin,
        );

        $lesson = $this->lesson('A');
        $this->record($lesson->id, 100.0);

        $overdue = $this->enrollments->forCourse(
            $this->tenantA,
            $this->course->id,
            1,
            50,
            null,
            null,
            overdueOnly: true,
        );

        // Only the one who has not finished.
        self::assertSame(1, $overdue['total']);
        self::assertSame($this->otherLearner, $overdue['items'][0]->userId);
    }

    #[Test]
    public function the_average_excludes_dropped_learners(): void
    {
        // Leaving them in makes a course look worse the more people are taken
        // off it, which reads backwards on a dashboard.
        $this->assign([$this->learner, $this->otherLearner]);
        $lesson = $this->lesson('A');
        $this->record($lesson->id, 100.0);

        self::assertSame(50.0, $this->enrollments->statsForCourse($this->tenantA, $this->course->id)['averageProgress']);

        $this->enrollments->unenroll($this->tenantA, $this->course->id, [$this->otherLearner], $this->admin);

        $stats = $this->enrollments->statsForCourse($this->tenantA, $this->course->id);

        self::assertSame(100.0, $stats['averageProgress']);
        self::assertSame(1, $stats['dropped']);
        self::assertSame(2, $stats['total']);
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @param list<string> $userIds
     *
     * @return array{enrolled: list<string>, reinstated: list<string>, alreadyEnrolled: list<string>, notFound: list<string>}
     */
    private function assign(array $userIds): array
    {
        return $this->enrollments->assign(
            $this->tenantA,
            $this->course->id,
            AssignmentRequest::fromInput(['userIds' => $userIds]),
            $this->admin,
        );
    }

    private function enrollment(): Enrollment
    {
        return $this->enrollments->findForUserAndCourse($this->tenantA, $this->learner, $this->course->id)
            ?? self::fail('The learner is not enrolled.');
    }

    private function lesson(string $title, bool $mandatory = true): Lesson
    {
        return $this->lessons->create(new Lesson(
            id: '',
            tenantId: $this->tenantA,
            courseId: $this->course->id,
            moduleId: $this->moduleId,
            title: $title,
            contentType: LessonContentType::Text,
            contentText: 'Body',
            isMandatory: $mandatory,
        ));
    }

    private function record(
        string $lessonId,
        float $percentage,
        int $seconds = 0,
        ?float $score = null,
        ?int $position = null,
    ): Enrollment {
        $input = ['progressPercentage' => $percentage, 'secondsSpent' => $seconds];

        if ($score !== null) {
            $input['score'] = $score;
        }

        if ($position !== null) {
            $input['positionSeconds'] = $position;
        }

        return $this->progress->record(
            $this->tenantA,
            $this->enrollment(),
            $lessonId,
            LessonProgress::reportFromInput($input),
        )['enrollment'];
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
                'email'  => $who . '-' . Uuid::uuid4()->toString() . '@enr.test',
                'first'  => ucfirst($who),
            ],
        );
    }

    private function createLookup(string $tenantId, string $kind, string $name): string
    {
        return (string) $this->db()->fetchOne(
            'INSERT INTO lookups (tenant_id, kind, name) VALUES (:tenant, :kind, :name) RETURNING id',
            ['tenant' => $tenantId, 'kind' => $kind, 'name' => $name . ' ' . substr(Uuid::uuid4()->toString(), 0, 8)],
        );
    }

    private function putUserIn(string $userId, string $column, string $lookupId): void
    {
        // The column name comes from this test, never from input.
        $this->db()->executeStatement(
            sprintf('UPDATE users SET %s = :lookup WHERE id = :id', $column),
            ['lookup' => $lookupId, 'id' => $userId],
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
