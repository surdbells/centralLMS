<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Catalog\Course;
use Central\Api\Domain\Catalog\CourseStatus;
use Central\Api\Infrastructure\Persistence\DbalCourseRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Tenant isolation and catalogue visibility, against a real PostgreSQL.
 *
 * The catalogue cases matter as much as the tenant ones: a draft course
 * leaking into the learner-facing list is the same class of bug as another
 * tenant's course doing so, and neither shows up in a single-tenant fixture.
 *
 * Skipped when the database is unreachable, so a checkout without the Docker
 * stack still gets a green suite.
 */
#[CoversClass(DbalCourseRepository::class)]
final class DbalCourseRepositoryTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalCourseRepository $courses;
    private string $tenantA;
    private string $tenantB;

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

        // Two throwaway tenants. The whole point is that a second one exists.
        $this->tenantA = $this->createTenant('crs-a');
        $this->tenantB = $this->createTenant('crs-b');
    }

    protected function tearDown(): void
    {
        if (self::$connection === null) {
            return;
        }

        foreach ([$this->tenantA ?? null, $this->tenantB ?? null] as $tenantId) {
            if ($tenantId !== null) {
                // courses cascade from tenants.
                self::$connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $tenantId]);
            }
        }
    }

    #[Test]
    public function a_list_never_includes_another_tenants_courses(): void
    {
        $this->courses->create($this->course($this->tenantA, 'Alpha Safety'));
        $this->courses->create($this->course($this->tenantB, 'Beta Safety'));

        $page = $this->courses->paginate($this->tenantA, 1, 50);

        self::assertSame(1, $page['total']);
        self::assertSame('Alpha Safety', $page['items'][0]->title);
    }

    #[Test]
    public function finding_another_tenants_course_by_id_returns_nothing(): void
    {
        $theirs = $this->courses->create($this->course($this->tenantB, 'Theirs'));

        self::assertNull($this->courses->find($this->tenantA, $theirs->id));
        self::assertNotNull($this->courses->find($this->tenantB, $theirs->id));
    }

    #[Test]
    public function updating_another_tenants_course_changes_nothing(): void
    {
        $theirs = $this->courses->create($this->course($this->tenantB, 'Untouched'));

        $attempt = new Course(
            id: $theirs->id,
            // The attacker's own tenant, the victim's course id.
            tenantId: $this->tenantA,
            title: 'Hijacked',
        );

        try {
            $this->courses->update($attempt);
            self::fail('Expected the update to affect no rows and then fail to read back.');
        } catch (\RuntimeException) {
            // update() reads the row back through find(), which is itself
            // tenant-scoped, so a no-op update surfaces rather than silently
            // reporting success.
        }

        self::assertSame('Untouched', $this->courses->find($this->tenantB, $theirs->id)?->title);
    }

    #[Test]
    public function publishing_another_tenants_course_does_nothing(): void
    {
        $theirs = $this->courses->create($this->course($this->tenantB, 'Still Draft'));

        self::assertNull($this->courses->setStatus($this->tenantA, $theirs->id, CourseStatus::Published));
        self::assertSame(CourseStatus::Draft, $this->courses->find($this->tenantB, $theirs->id)?->status);
    }

    #[Test]
    public function soft_deleting_another_tenants_course_does_nothing(): void
    {
        $theirs = $this->courses->create($this->course($this->tenantB, 'Survivor'));

        self::assertFalse($this->courses->softDelete($this->tenantA, $theirs->id));
        self::assertNotNull($this->courses->find($this->tenantB, $theirs->id));
    }

    #[Test]
    public function the_same_title_is_allowed_in_two_different_tenants(): void
    {
        $mine = $this->courses->create($this->course($this->tenantA, 'Fire Safety'));

        self::assertFalse($this->courses->existsWithTitle($this->tenantB, 'Fire Safety'));

        $theirs = $this->courses->create($this->course($this->tenantB, 'Fire Safety'));

        self::assertNotSame($mine->id, $theirs->id);
        self::assertSame(1, $this->courses->paginate($this->tenantA, 1, 50)['total']);
        self::assertSame(1, $this->courses->paginate($this->tenantB, 1, 50)['total']);
    }

    #[Test]
    public function a_title_clash_within_one_tenant_is_detected_case_insensitively(): void
    {
        $created = $this->courses->create($this->course($this->tenantA, 'Fire Safety'));

        self::assertTrue($this->courses->existsWithTitle($this->tenantA, 'fire safety'));
        // ...but the course does not clash with itself when being renamed.
        self::assertFalse($this->courses->existsWithTitle($this->tenantA, 'fire safety', $created->id));
    }

    #[Test]
    public function the_catalogue_excludes_drafts_and_archived_courses(): void
    {
        $draft = $this->courses->create($this->course($this->tenantA, 'Draft One'));
        $published = $this->courses->create($this->course($this->tenantA, 'Published One'));
        $archived = $this->courses->create($this->course($this->tenantA, 'Archived One'));

        $this->courses->setStatus($this->tenantA, $published->id, CourseStatus::Published);
        $this->courses->setStatus($this->tenantA, $archived->id, CourseStatus::Archived);

        $catalogue = $this->courses->catalogue($this->tenantA, 1, 50);

        self::assertSame(1, $catalogue['total']);
        self::assertSame('Published One', $catalogue['items'][0]->title);
        self::assertNotSame($draft->id, $catalogue['items'][0]->id);
    }

    #[Test]
    public function the_catalogue_is_scoped_to_the_tenant(): void
    {
        $theirs = $this->courses->create($this->course($this->tenantB, 'Their Published'));
        $this->courses->setStatus($this->tenantB, $theirs->id, CourseStatus::Published);

        self::assertSame(0, $this->courses->catalogue($this->tenantA, 1, 50)['total']);
    }

    #[Test]
    public function published_at_is_stamped_once_and_survives_unpublishing(): void
    {
        $course = $this->courses->create($this->course($this->tenantA, 'Stamped'));
        self::assertNull($course->publishedAt);

        $published = $this->courses->setStatus($this->tenantA, $course->id, CourseStatus::Published);
        self::assertNotNull($published?->publishedAt);
        $first = $published->publishedAt;

        $this->courses->setStatus($this->tenantA, $course->id, CourseStatus::Draft);
        $again = $this->courses->setStatus($this->tenantA, $course->id, CourseStatus::Published);

        // "First published on" is a fact about the course, not its state.
        self::assertEquals($first, $again?->publishedAt);
    }

    #[Test]
    public function a_soft_deleted_course_disappears_from_lists_and_lookups(): void
    {
        $course = $this->courses->create($this->course($this->tenantA, 'Gone'));

        self::assertTrue($this->courses->softDelete($this->tenantA, $course->id));
        self::assertNull($this->courses->find($this->tenantA, $course->id));
        self::assertSame(0, $this->courses->paginate($this->tenantA, 1, 50)['total']);
        // ...and the title is free again.
        self::assertFalse($this->courses->existsWithTitle($this->tenantA, 'Gone'));
    }

    #[Test]
    public function search_is_scoped_to_the_tenant(): void
    {
        $this->courses->create($this->course($this->tenantB, 'Shared Keyword'));

        self::assertSame(0, $this->courses->paginate($this->tenantA, 1, 50, 'Keyword')['total']);
    }

    #[Test]
    public function an_unknown_sort_column_falls_back_instead_of_reaching_the_query(): void
    {
        $this->courses->create($this->course($this->tenantA, 'Sortable'));

        // ORDER BY cannot be parameterised, so the allow-list is the defence.
        $page = $this->courses->paginate($this->tenantA, 1, 50, null, null, null, 'title; DROP TABLE courses', 'asc');

        self::assertSame(1, $page['total']);
    }

    #[Test]
    public function a_course_has_no_active_lessons_until_one_is_added(): void
    {
        $course = $this->courses->create($this->course($this->tenantA, 'Empty'));

        self::assertFalse($this->courses->hasActiveLessons($this->tenantA, $course->id));

        $this->addLesson($course->id, $this->tenantA);

        self::assertTrue($this->courses->hasActiveLessons($this->tenantA, $course->id));
        // ...and the other tenant still sees nothing.
        self::assertFalse($this->courses->hasActiveLessons($this->tenantB, $course->id));
    }

    #[Test]
    public function an_inactive_lesson_does_not_count_as_publishable_content(): void
    {
        $course = $this->courses->create($this->course($this->tenantA, 'Hidden Content'));
        $lessonId = $this->addLesson($course->id, $this->tenantA);

        self::$connection?->executeStatement(
            'UPDATE lessons SET is_active = false WHERE id = :id',
            ['id' => $lessonId],
        );

        self::assertFalse($this->courses->hasActiveLessons($this->tenantA, $course->id));
    }

    private function course(string $tenantId, string $title): Course
    {
        return new Course(id: '', tenantId: $tenantId, title: $title);
    }

    /**
     * Inserts a module and a lesson under it, so the composite foreign key on
     * (module_id, course_id) is satisfied.
     */
    private function addLesson(string $courseId, string $tenantId): string
    {
        $connection = self::$connection;

        if ($connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable.');
        }

        $moduleId = (string) $connection->fetchOne(
            'INSERT INTO course_modules (tenant_id, course_id, title, sort_order)
             VALUES (:tenant, :course, :title, 1) RETURNING id',
            ['tenant' => $tenantId, 'course' => $courseId, 'title' => 'Module 1'],
        );

        return (string) $connection->fetchOne(
            'INSERT INTO lessons (tenant_id, course_id, module_id, title, content_type, sort_order)
             VALUES (:tenant, :course, :module, :title, :contentType, 1) RETURNING id',
            [
                'tenant'      => $tenantId,
                'course'      => $courseId,
                'module'      => $moduleId,
                'title'       => 'Lesson 1',
                'contentType' => 'text',
            ],
        );
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
