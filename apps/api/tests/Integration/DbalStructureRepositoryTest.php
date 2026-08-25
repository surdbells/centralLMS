<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Catalog\Course;
use Central\Api\Domain\Catalog\CourseModule;
use Central\Api\Domain\Catalog\Lesson;
use Central\Api\Domain\Catalog\LessonContentType;
use Central\Api\Infrastructure\Persistence\DbalCourseRepository;
use Central\Api\Infrastructure\Persistence\DbalLessonRepository;
use Central\Api\Infrastructure\Persistence\DbalModuleRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Modules and lessons against a real PostgreSQL.
 *
 * Two things here cannot be checked with a fake: that a module id from
 * another course is unreachable even inside the right tenant, and that
 * reordering lands in one statement rather than leaving two rows sharing a
 * position.
 *
 * Skipped when the database is unreachable, so a checkout without the Docker
 * stack still gets a green suite.
 */
#[CoversClass(DbalModuleRepository::class)]
#[CoversClass(DbalLessonRepository::class)]
final class DbalStructureRepositoryTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalCourseRepository $courses;
    private DbalModuleRepository $modules;
    private DbalLessonRepository $lessons;

    private string $tenantA;
    private string $tenantB;
    private Course $courseA;
    private Course $otherCourseA;
    private Course $courseB;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable — start it with: pnpm up');
        }

        $this->courses = new DbalCourseRepository(self::$connection);
        $this->modules = new DbalModuleRepository(self::$connection);
        $this->lessons = new DbalLessonRepository(self::$connection);

        $this->tenantA = $this->createTenant('str-a');
        $this->tenantB = $this->createTenant('str-b');

        $this->courseA = $this->courses->create(new Course(id: '', tenantId: $this->tenantA, title: 'Course A'));
        $this->otherCourseA = $this->courses->create(
            new Course(id: '', tenantId: $this->tenantA, title: 'Other Course A'),
        );
        $this->courseB = $this->courses->create(new Course(id: '', tenantId: $this->tenantB, title: 'Course B'));
    }

    protected function tearDown(): void
    {
        if (self::$connection === null) {
            return;
        }

        foreach ([$this->tenantA ?? null, $this->tenantB ?? null] as $tenantId) {
            if ($tenantId !== null) {
                self::$connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $tenantId]);
            }
        }
    }

    // --- tenant and course scoping ---------------------------------------

    #[Test]
    public function a_module_from_another_tenant_is_unreachable(): void
    {
        $theirs = $this->module($this->tenantB, $this->courseB->id, 'Theirs');

        self::assertNull($this->modules->find($this->tenantA, $this->courseB->id, $theirs->id));
        self::assertNotNull($this->modules->find($this->tenantB, $this->courseB->id, $theirs->id));
    }

    #[Test]
    public function a_module_from_another_course_in_the_same_tenant_is_unreachable(): void
    {
        // The interesting case: right tenant, wrong parent. Scoping only by
        // tenant would let an author edit a module of a course they had
        // navigated away from.
        $module = $this->module($this->tenantA, $this->otherCourseA->id, 'Elsewhere');

        self::assertNull($this->modules->find($this->tenantA, $this->courseA->id, $module->id));
        self::assertNotNull($this->modules->find($this->tenantA, $this->otherCourseA->id, $module->id));
    }

    #[Test]
    public function a_lesson_from_another_module_is_unreachable(): void
    {
        $mine = $this->module($this->tenantA, $this->courseA->id, 'Mine');
        $other = $this->module($this->tenantA, $this->courseA->id, 'Other');
        $lesson = $this->lesson($this->tenantA, $this->courseA->id, $other->id, 'Hidden');

        self::assertNull($this->lessons->find($this->tenantA, $mine->id, $lesson->id));
        self::assertNotNull($this->lessons->find($this->tenantA, $other->id, $lesson->id));
    }

    #[Test]
    public function updating_a_module_through_the_wrong_course_changes_nothing(): void
    {
        $module = $this->module($this->tenantA, $this->otherCourseA->id, 'Untouched');

        $attempt = new CourseModule(
            id: $module->id,
            tenantId: $this->tenantA,
            // The course the caller is looking at, not the one that owns it.
            courseId: $this->courseA->id,
            title: 'Hijacked',
        );

        try {
            $this->modules->update($attempt);
            self::fail('Expected the update to affect no rows and then fail to read back.');
        } catch (\RuntimeException) {
            // update() reads back through the scoped find(), so a no-op
            // surfaces rather than reporting success.
        }

        self::assertSame(
            'Untouched',
            $this->modules->find($this->tenantA, $this->otherCourseA->id, $module->id)?->title,
        );
    }

    #[Test]
    public function deactivating_a_module_through_the_wrong_course_does_nothing(): void
    {
        $module = $this->module($this->tenantA, $this->otherCourseA->id, 'Still Active');

        self::assertNull($this->modules->setActive($this->tenantA, $this->courseA->id, $module->id, false));
        self::assertTrue($this->modules->find($this->tenantA, $this->otherCourseA->id, $module->id)?->isActive);
    }

    // --- listing and filtering -------------------------------------------

    #[Test]
    public function modules_come_back_in_position_order(): void
    {
        $this->module($this->tenantA, $this->courseA->id, 'Third', 3);
        $this->module($this->tenantA, $this->courseA->id, 'First', 1);
        $this->module($this->tenantA, $this->courseA->id, 'Second', 2);

        self::assertSame(
            ['First', 'Second', 'Third'],
            array_map(
                static fn (CourseModule $m): string => $m->title,
                $this->modules->forCourse($this->tenantA, $this->courseA->id),
            ),
        );
    }

    #[Test]
    public function the_author_view_includes_deactivated_modules_and_the_learner_view_does_not(): void
    {
        $this->module($this->tenantA, $this->courseA->id, 'Visible', 1);
        $hidden = $this->module($this->tenantA, $this->courseA->id, 'Hidden', 2);
        $this->modules->setActive($this->tenantA, $this->courseA->id, $hidden->id, false);

        self::assertCount(2, $this->modules->forCourse($this->tenantA, $this->courseA->id));
        self::assertCount(1, $this->modules->forCourse($this->tenantA, $this->courseA->id, activeOnly: true));
    }

    #[Test]
    public function the_outline_read_groups_every_lesson_by_module_in_one_query(): void
    {
        $one = $this->module($this->tenantA, $this->courseA->id, 'One', 1);
        $two = $this->module($this->tenantA, $this->courseA->id, 'Two', 2);

        $this->lesson($this->tenantA, $this->courseA->id, $one->id, 'A', 1);
        $this->lesson($this->tenantA, $this->courseA->id, $one->id, 'B', 2);
        $this->lesson($this->tenantA, $this->courseA->id, $two->id, 'C', 1);

        $grouped = $this->lessons->forCourseByModule($this->tenantA, $this->courseA->id);

        self::assertCount(2, $grouped[$one->id]);
        self::assertCount(1, $grouped[$two->id]);
        self::assertSame(['A', 'B'], array_map(static fn (Lesson $l): string => $l->title, $grouped[$one->id]));
    }

    #[Test]
    public function the_outline_read_is_scoped_to_the_tenant(): void
    {
        $module = $this->module($this->tenantB, $this->courseB->id, 'Theirs');
        $this->lesson($this->tenantB, $this->courseB->id, $module->id, 'Secret');

        self::assertSame([], $this->lessons->forCourseByModule($this->tenantA, $this->courseB->id));
    }

    // --- reordering -------------------------------------------------------

    #[Test]
    public function reordering_applies_the_whole_order_in_one_statement(): void
    {
        $a = $this->module($this->tenantA, $this->courseA->id, 'A', 1);
        $b = $this->module($this->tenantA, $this->courseA->id, 'B', 2);
        $c = $this->module($this->tenantA, $this->courseA->id, 'C', 3);

        $moved = $this->modules->reorder($this->tenantA, $this->courseA->id, [$c->id, $a->id, $b->id]);

        self::assertSame(3, $moved);
        self::assertSame(
            ['C', 'A', 'B'],
            array_map(
                static fn (CourseModule $m): string => $m->title,
                $this->modules->forCourse($this->tenantA, $this->courseA->id),
            ),
        );
    }

    #[Test]
    public function reordering_leaves_no_two_modules_sharing_a_position(): void
    {
        $a = $this->module($this->tenantA, $this->courseA->id, 'A', 0);
        $b = $this->module($this->tenantA, $this->courseA->id, 'B', 0);
        $c = $this->module($this->tenantA, $this->courseA->id, 'C', 0);

        $this->modules->reorder($this->tenantA, $this->courseA->id, [$b->id, $c->id, $a->id]);

        $positions = array_map(
            static fn (CourseModule $m): int => $m->sortOrder,
            $this->modules->forCourse($this->tenantA, $this->courseA->id),
        );

        self::assertSame($positions, array_unique($positions));
        self::assertSame([1, 2, 3], $positions);
    }

    #[Test]
    public function reordering_cannot_touch_another_courses_modules(): void
    {
        $mine = $this->module($this->tenantA, $this->courseA->id, 'Mine', 1);
        $theirs = $this->module($this->tenantA, $this->otherCourseA->id, 'Theirs', 1);

        // Both ids sent, but only one belongs to this course.
        $moved = $this->modules->reorder($this->tenantA, $this->courseA->id, [$theirs->id, $mine->id]);

        self::assertSame(1, $moved);
        self::assertSame(
            1,
            $this->modules->find($this->tenantA, $this->otherCourseA->id, $theirs->id)?->sortOrder,
        );
    }

    #[Test]
    public function existing_ids_reports_only_what_belongs_to_the_parent(): void
    {
        $mine = $this->module($this->tenantA, $this->courseA->id, 'Mine');
        $theirs = $this->module($this->tenantA, $this->otherCourseA->id, 'Theirs');

        self::assertSame(
            [$mine->id],
            $this->modules->existingIds($this->tenantA, $this->courseA->id, [$mine->id, $theirs->id]),
        );
    }

    #[Test]
    public function lessons_reorder_within_their_module_only(): void
    {
        $one = $this->module($this->tenantA, $this->courseA->id, 'One');
        $two = $this->module($this->tenantA, $this->courseA->id, 'Two');

        $a = $this->lesson($this->tenantA, $this->courseA->id, $one->id, 'A', 1);
        $b = $this->lesson($this->tenantA, $this->courseA->id, $one->id, 'B', 2);
        $elsewhere = $this->lesson($this->tenantA, $this->courseA->id, $two->id, 'Elsewhere', 1);

        $moved = $this->lessons->reorder($this->tenantA, $one->id, [$b->id, $a->id, $elsewhere->id]);

        self::assertSame(2, $moved);
        self::assertSame(1, $this->lessons->find($this->tenantA, $two->id, $elsewhere->id)?->sortOrder);
    }

    // --- positions --------------------------------------------------------

    #[Test]
    public function the_next_position_starts_at_zero_and_then_follows_the_last(): void
    {
        self::assertSame(0, $this->modules->nextSortOrder($this->tenantA, $this->courseA->id));

        $this->module($this->tenantA, $this->courseA->id, 'First', 0);
        self::assertSame(1, $this->modules->nextSortOrder($this->tenantA, $this->courseA->id));

        $this->module($this->tenantA, $this->courseA->id, 'Jumped', 9);
        self::assertSame(10, $this->modules->nextSortOrder($this->tenantA, $this->courseA->id));
    }

    #[Test]
    public function the_next_position_ignores_another_courses_modules(): void
    {
        $this->module($this->tenantA, $this->otherCourseA->id, 'Elsewhere', 7);

        self::assertSame(0, $this->modules->nextSortOrder($this->tenantA, $this->courseA->id));
    }

    // --- deletion ---------------------------------------------------------

    #[Test]
    public function deleting_a_module_takes_its_lessons_with_it(): void
    {
        $module = $this->module($this->tenantA, $this->courseA->id, 'Doomed');
        $lesson = $this->lesson($this->tenantA, $this->courseA->id, $module->id, 'Also Doomed');

        self::assertTrue($this->modules->softDelete($this->tenantA, $this->courseA->id, $module->id));

        self::assertNull($this->modules->find($this->tenantA, $this->courseA->id, $module->id));
        // A lesson whose module is gone must not survive as an orphan that
        // still counts towards completion.
        self::assertNull($this->lessons->find($this->tenantA, $module->id, $lesson->id));
        self::assertSame([], $this->lessons->forCourseByModule($this->tenantA, $this->courseA->id));
    }

    #[Test]
    public function deleting_a_module_leaves_the_other_modules_alone(): void
    {
        $doomed = $this->module($this->tenantA, $this->courseA->id, 'Doomed');
        $survivor = $this->module($this->tenantA, $this->courseA->id, 'Survivor');
        $keptLesson = $this->lesson($this->tenantA, $this->courseA->id, $survivor->id, 'Kept');

        $this->modules->softDelete($this->tenantA, $this->courseA->id, $doomed->id);

        self::assertNotNull($this->modules->find($this->tenantA, $this->courseA->id, $survivor->id));
        self::assertNotNull($this->lessons->find($this->tenantA, $survivor->id, $keptLesson->id));
    }

    #[Test]
    public function deleting_a_module_through_the_wrong_course_does_nothing(): void
    {
        $module = $this->module($this->tenantA, $this->otherCourseA->id, 'Survivor');

        self::assertFalse($this->modules->softDelete($this->tenantA, $this->courseA->id, $module->id));
        self::assertNotNull($this->modules->find($this->tenantA, $this->otherCourseA->id, $module->id));
    }

    #[Test]
    public function a_deleted_lesson_disappears_from_every_read(): void
    {
        $module = $this->module($this->tenantA, $this->courseA->id, 'M');
        $lesson = $this->lesson($this->tenantA, $this->courseA->id, $module->id, 'Gone');

        self::assertTrue($this->lessons->softDelete($this->tenantA, $module->id, $lesson->id));
        self::assertNull($this->lessons->find($this->tenantA, $module->id, $lesson->id));
        self::assertSame([], $this->lessons->forModule($this->tenantA, $module->id));
        self::assertSame([], $this->lessons->forCourseByModule($this->tenantA, $this->courseA->id));
    }

    // --- content ----------------------------------------------------------

    #[Test]
    public function a_lesson_round_trips_every_field(): void
    {
        $module = $this->module($this->tenantA, $this->courseA->id, 'M');

        $created = $this->lessons->create(new Lesson(
            id: '',
            tenantId: $this->tenantA,
            courseId: $this->courseA->id,
            moduleId: $module->id,
            title: 'Full',
            contentType: LessonContentType::Video,
            description: 'A description',
            contentUrl: 'https://example.test/v.mp4',
            durationMinutes: 42,
            sortOrder: 3,
            isMandatory: false,
            isPreview: true,
        ));

        $read = $this->lessons->find($this->tenantA, $module->id, $created->id);

        self::assertNotNull($read);
        self::assertSame('Full', $read->title);
        self::assertSame(LessonContentType::Video, $read->contentType);
        self::assertSame('https://example.test/v.mp4', $read->contentUrl);
        self::assertSame('A description', $read->description);
        self::assertSame(42, $read->durationMinutes);
        self::assertSame(3, $read->sortOrder);
        self::assertFalse($read->isMandatory);
        self::assertTrue($read->isPreview);
        self::assertTrue($read->isActive);
    }

    #[Test]
    public function updating_a_lesson_cannot_move_it_to_another_course(): void
    {
        $module = $this->module($this->tenantA, $this->courseA->id, 'M');
        $lesson = $this->lesson($this->tenantA, $this->courseA->id, $module->id, 'Stays');

        $this->lessons->update(new Lesson(
            id: $lesson->id,
            tenantId: $this->tenantA,
            // A different course, which update() must ignore.
            courseId: $this->otherCourseA->id,
            moduleId: $module->id,
            title: 'Renamed',
            contentType: LessonContentType::Text,
            contentText: 'Body',
        ));

        $read = $this->lessons->find($this->tenantA, $module->id, $lesson->id);

        self::assertNotNull($read);
        self::assertSame('Renamed', $read->title);
        self::assertSame($this->courseA->id, $read->courseId);
    }

    // --- helpers ----------------------------------------------------------

    private function module(string $tenantId, string $courseId, string $title, int $sortOrder = 0): CourseModule
    {
        return $this->modules->create(new CourseModule(
            id: '',
            tenantId: $tenantId,
            courseId: $courseId,
            title: $title,
            sortOrder: $sortOrder,
        ));
    }

    private function lesson(
        string $tenantId,
        string $courseId,
        string $moduleId,
        string $title,
        int $sortOrder = 0,
    ): Lesson {
        return $this->lessons->create(new Lesson(
            id: '',
            tenantId: $tenantId,
            courseId: $courseId,
            moduleId: $moduleId,
            title: $title,
            contentType: LessonContentType::Text,
            contentText: 'Body',
            sortOrder: $sortOrder,
        ));
    }

    private function createTenant(string $prefix): string
    {
        $id = Uuid::uuid4()->toString();
        $connection = self::$connection;

        if ($connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable.');
        }

        $connection->executeStatement(
            'INSERT INTO tenants (id, name, code, status) VALUES (:id, :name, :code, \'active\')',
            ['id' => $id, 'name' => $prefix . ' test tenant', 'code' => $prefix . '-' . substr($id, 0, 8)],
        );

        return $id;
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
