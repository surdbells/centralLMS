<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Catalog\CourseStatus;
use Central\Api\Domain\LearningPath\CompletionRule;
use Central\Api\Domain\LearningPath\LearningPath;
use Central\Api\Domain\LearningPath\PathFilters;
use Central\Api\Domain\LearningPath\PathItem;
use Central\Api\Domain\LearningPath\PathItemKind;
use Central\Api\Domain\LearningPath\PathPacing;
use Central\Api\Domain\LearningPath\PathProgress;
use Central\Api\Domain\LearningPath\PathSection;
use Central\Api\Infrastructure\Persistence\DbalLearningPathRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Learning paths against a real PostgreSQL.
 *
 * The substance is {@see DbalLearningPathRepository::completionsFor()}: an
 * item's progress is the progress of the thing it points at. There is no
 * progress table to keep in step, which is why the numbers cannot drift the way
 * the legacy's did — two claimed items, three rows, none active, and
 * `items_total = 4` on all thirty-three enrolments.
 */
#[CoversClass(DbalLearningPathRepository::class)]
#[CoversClass(LearningPath::class)]
final class DbalLearningPathRepositoryTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalLearningPathRepository $paths;

    private string $tenantA;
    private string $tenantB;
    private string $ada;
    private string $grace;
    private string $fireCourse;
    private string $gdprCourse;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable — start it with: pnpm up');
        }

        $this->paths = new DbalLearningPathRepository(self::$connection);

        $this->tenantA = $this->createTenant('lp-a');
        $this->tenantB = $this->createTenant('lp-b');
        $this->ada     = $this->createUser($this->tenantA, 'Ada');
        $this->grace   = $this->createUser($this->tenantA, 'Grace');

        $this->fireCourse = $this->createCourse('Fire Safety');
        $this->gdprCourse = $this->createCourse('GDPR Basics');
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

            foreach (
                [
                'learning_path_item_completions',
                'learning_path_enrollments',
                'learning_path_items',
                'learning_path_sections',
                'learning_paths',
                'enrollments',
                'courses',
                'users',
                ] as $table
            ) {
                self::$connection->executeStatement(
                    sprintf('DELETE FROM %s WHERE tenant_id = :id', $table),
                    ['id' => $tenantId],
                );
            }

            self::$connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $tenantId]);
        }
    }

    // --- authoring --------------------------------------------------------

    #[Test]
    public function a_new_path_starts_as_a_draft_with_nothing_in_it(): void
    {
        $path = $this->paths->save($this->path('Onboarding'));

        self::assertSame(CourseStatus::Draft, $path->status);
        self::assertNull($path->publishedAt);
        self::assertSame(0, $path->itemCount);
        self::assertSame(0, $path->enrolmentCount);
    }

    #[Test]
    public function publishing_stamps_the_date(): void
    {
        // The legacy let `status = 'published'` sit beside a null
        // `published_at`, so "when did this go live" had no answer.
        $path = $this->paths->save($this->path('Onboarding'));

        $published = $this->paths->setStatus($this->tenantA, (string) $path->id, 'published');

        self::assertSame(CourseStatus::Published, $published?->status);
        self::assertNotNull($published->publishedAt);
    }

    #[Test]
    public function unpublishing_keeps_the_original_date(): void
    {
        $path = $this->paths->save($this->path('Onboarding'));
        $this->paths->setStatus($this->tenantA, (string) $path->id, 'published');
        $first = $this->paths->find($this->tenantA, (string) $path->id)?->publishedAt;

        $this->paths->setStatus($this->tenantA, (string) $path->id, 'draft');
        $republished = $this->paths->setStatus($this->tenantA, (string) $path->id, 'published');

        self::assertEquals($first, $republished?->publishedAt);
    }

    #[Test]
    public function two_paths_cannot_share_an_address(): void
    {
        // A slug is an address. Two sharing one means a URL that resolves to
        // whichever the database returns first.
        $this->paths->save($this->path('Onboarding', 'onboarding'));

        self::assertTrue($this->paths->slugExists($this->tenantA, 'onboarding'));
        self::assertTrue($this->paths->slugExists($this->tenantA, 'ONBOARDING'));
        self::assertFalse($this->paths->slugExists($this->tenantB, 'onboarding'));
    }

    #[Test]
    public function a_path_can_be_found_by_its_address(): void
    {
        $path = $this->paths->save($this->path('Onboarding', 'onboarding'));

        self::assertSame($path->id, $this->paths->findBySlug($this->tenantA, 'Onboarding')?->id);
        self::assertNull($this->paths->findBySlug($this->tenantB, 'onboarding'));
    }

    #[Test]
    public function a_path_is_invisible_to_another_tenant(): void
    {
        $path = $this->paths->save($this->path('Onboarding'));

        self::assertNull($this->paths->find($this->tenantB, (string) $path->id));
        self::assertFalse($this->paths->softDelete($this->tenantB, (string) $path->id));
        self::assertNull($this->paths->setStatus($this->tenantB, (string) $path->id, 'published'));
    }

    #[Test]
    public function the_catalogue_holds_only_what_a_learner_may_see(): void
    {
        $draft = $this->paths->save($this->path('Draft'));

        $private = $this->paths->save($this->path('Private', 'private-one'));
        $this->paths->setStatus($this->tenantA, (string) $private->id, 'published');

        $public = $this->paths->save(new LearningPath(
            id: null,
            tenantId: $this->tenantA,
            title: 'Public',
            slug: 'public-one',
            visibility: 'public',
        ));
        $this->paths->setStatus($this->tenantA, (string) $public->id, 'published');

        self::assertSame(3, $this->paths->paginate($this->tenantA, PathFilters::all(), 1, 25)['total']);

        $catalogue = $this->paths->paginate($this->tenantA, PathFilters::catalogue(), 1, 25);

        self::assertSame(1, $catalogue['total']);
        self::assertSame('Public', $catalogue['items'][0]->title);
        self::assertNotSame($draft->id, $catalogue['items'][0]->id);
    }

    // --- contents ---------------------------------------------------------

    #[Test]
    public function the_item_count_is_counted_rather_than_stored(): void
    {
        // The legacy path says two items, has three rows and none active.
        $path = $this->paths->save($this->path('Onboarding'));

        $this->paths->saveItem($this->courseItem($path, $this->fireCourse, 1));
        $second = $this->paths->saveItem($this->courseItem($path, $this->gdprCourse, 2));

        $withBoth = $this->paths->find($this->tenantA, (string) $path->id);
        self::assertNotNull($withBoth);
        self::assertSame(2, $withBoth->itemCount);

        $this->paths->deleteItem($this->tenantA, (string) $second->id);

        $withOne = $this->paths->find($this->tenantA, (string) $path->id);
        self::assertNotNull($withOne);
        self::assertSame(1, $withOne->itemCount);
    }

    #[Test]
    public function an_item_is_named_by_the_course_it_points_at(): void
    {
        $path = $this->paths->save($this->path('Onboarding'));
        $item = $this->paths->saveItem($this->courseItem($path, $this->fireCourse, 1));

        self::assertSame('Fire Safety', $item->subjectTitle);
        self::assertSame('Fire Safety', $item->displayTitle());
    }

    #[Test]
    public function renaming_a_course_renames_it_inside_the_path(): void
    {
        // Which is why the title is not copied into the item.
        $path = $this->paths->save($this->path('Onboarding'));
        $item = $this->paths->saveItem($this->courseItem($path, $this->fireCourse, 1));

        self::$connection?->executeStatement(
            "UPDATE courses SET title = 'Fire Safety 2026' WHERE id = :id",
            ['id' => $this->fireCourse],
        );

        $renamed = $this->paths->findItem($this->tenantA, (string) $item->id);

        self::assertSame('Fire Safety 2026', $renamed?->displayTitle());
    }

    #[Test]
    public function the_same_course_cannot_be_in_a_path_twice(): void
    {
        // It would be counted twice in every derived figure.
        $path = $this->paths->save($this->path('Onboarding'));
        $this->paths->saveItem($this->courseItem($path, $this->fireCourse, 1));

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

        $this->paths->saveItem($this->courseItem($path, $this->fireCourse, 2));
    }

    #[Test]
    public function an_item_cannot_point_at_two_things(): void
    {
        $path = $this->paths->save($this->path('Onboarding'));

        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);

        $this->paths->saveItem(new PathItem(
            id: null,
            tenantId: $this->tenantA,
            pathId: (string) $path->id,
            kind: PathItemKind::Course,
            courseId: $this->fireCourse,
            externalUrl: 'https://example.test',
        ));
    }

    #[Test]
    public function items_can_be_reordered_in_one_statement(): void
    {
        $path  = $this->paths->save($this->path('Onboarding'));
        $first = $this->paths->saveItem($this->courseItem($path, $this->fireCourse, 1));
        $next  = $this->paths->saveItem($this->courseItem($path, $this->gdprCourse, 2));

        $this->paths->reorderItems($this->tenantA, (string) $path->id, [(string) $next->id, (string) $first->id]);

        $ordered = array_map(
            static fn (PathItem $i): string => (string) $i->id,
            $this->paths->items($this->tenantA, (string) $path->id),
        );

        self::assertSame([(string) $next->id, (string) $first->id], $ordered);
    }

    #[Test]
    public function removing_a_section_keeps_the_items_in_it(): void
    {
        $path    = $this->paths->save($this->path('Onboarding'));
        $section = $this->paths->saveSection($this->section($path, 'January'));

        $item = $this->paths->saveItem(new PathItem(
            id: null,
            tenantId: $this->tenantA,
            pathId: (string) $path->id,
            kind: PathItemKind::Course,
            sectionId: $section->id,
            courseId: $this->fireCourse,
        ));

        self::assertTrue($this->paths->deleteSection($this->tenantA, (string) $section->id));

        $after = $this->paths->findItem($this->tenantA, (string) $item->id);

        self::assertNotNull($after);
        self::assertNull($after->sectionId);
    }

    #[Test]
    public function a_section_counts_its_own_items(): void
    {
        $path    = $this->paths->save($this->path('Onboarding'));
        $section = $this->paths->saveSection($this->section($path, 'January'));

        $this->paths->saveItem(new PathItem(
            id: null,
            tenantId: $this->tenantA,
            pathId: (string) $path->id,
            kind: PathItemKind::Course,
            sectionId: $section->id,
            courseId: $this->fireCourse,
        ));

        self::assertSame(1, $this->paths->sections($this->tenantA, (string) $path->id)[0]->itemCount);
    }

    // --- enrolment --------------------------------------------------------

    #[Test]
    public function enrolling_a_cohort_twice_adds_nobody_twice(): void
    {
        // The legacy had no unique index, so enrolling in bulk twice put the
        // same person on twice.
        $path = $this->paths->save($this->path('Onboarding'));

        $first = $this->enrol($path, [$this->ada, $this->grace]);
        $again = $this->enrol($path, [$this->ada, $this->grace]);

        self::assertSame(['enrolled' => 2, 'skipped' => 0], $first);
        self::assertSame(['enrolled' => 0, 'skipped' => 2], $again);
        self::assertSame(2, $this->paths->enrolments($this->tenantA, (string) $path->id, 1, 25)['total']);
    }

    #[Test]
    public function somebody_from_another_tenant_cannot_be_enrolled(): void
    {
        $path      = $this->paths->save($this->path('Onboarding'));
        $outsider  = $this->createUser($this->tenantB, 'Outsider');

        $result = $this->enrol($path, [$this->ada, $outsider]);

        self::assertSame(1, $result['enrolled']);
        self::assertNull($this->paths->enrolmentFor($this->tenantA, (string) $path->id, $outsider));
    }

    #[Test]
    public function withdrawing_keeps_the_row(): void
    {
        // They were on it, and a report about who dropped out needs the row.
        $path = $this->paths->save($this->path('Onboarding'));
        $this->enrol($path, [$this->ada]);

        self::assertTrue($this->paths->withdraw($this->tenantA, (string) $path->id, $this->ada));

        $enrolment = $this->paths->enrolmentFor($this->tenantA, (string) $path->id, $this->ada);

        self::assertSame('withdrawn', $enrolment?->status);
        // And a withdrawn enrolment is not counted as somebody on the path.
        self::assertSame(0, $this->paths->find($this->tenantA, (string) $path->id)?->enrolmentCount);
    }

    #[Test]
    public function a_finished_path_cannot_be_withdrawn_from(): void
    {
        $path = $this->paths->save($this->path('Onboarding'));
        $this->enrol($path, [$this->ada]);

        $enrolment = $this->paths->enrolmentFor($this->tenantA, (string) $path->id, $this->ada);
        $this->paths->markComplete($this->tenantA, (string) $enrolment?->id, null);

        self::assertFalse($this->paths->withdraw($this->tenantA, (string) $path->id, $this->ada));
    }

    #[Test]
    public function completing_is_recorded_once(): void
    {
        $path = $this->paths->save($this->path('Onboarding'));
        $this->enrol($path, [$this->ada]);

        $enrolment = $this->paths->enrolmentFor($this->tenantA, (string) $path->id, $this->ada);
        $first     = $this->paths->markComplete($this->tenantA, (string) $enrolment?->id, null);

        self::assertTrue($first?->isComplete());
        // Already complete, so nothing to do — and the timestamp does not move.
        self::assertNull($this->paths->markComplete($this->tenantA, (string) $enrolment?->id, null));
    }

    // --- progress, derived ------------------------------------------------

    #[Test]
    public function an_item_is_finished_when_the_course_behind_it_is(): void
    {
        $path = $this->paths->save($this->path('Onboarding'));
        $item = $this->paths->saveItem($this->courseItem($path, $this->fireCourse, 1));
        $this->enrol($path, [$this->ada]);

        self::assertSame([], $this->paths->completionsFor($this->tenantA, (string) $path->id, $this->ada));

        $this->completeCourse($this->ada, $this->fireCourse);

        $completions = $this->paths->completionsFor($this->tenantA, (string) $path->id, $this->ada);

        self::assertArrayHasKey((string) $item->id, $completions);
    }

    #[Test]
    public function a_path_credits_work_finished_before_somebody_joined_it(): void
    {
        // Which the legacy could not express: its item progress began at
        // `locked` when the enrolment was written.
        $this->completeCourse($this->ada, $this->fireCourse);

        $path = $this->paths->save($this->path('Onboarding'));
        $item = $this->paths->saveItem($this->courseItem($path, $this->fireCourse, 1));
        $this->enrol($path, [$this->ada]);

        self::assertArrayHasKey(
            (string) $item->id,
            $this->paths->completionsFor($this->tenantA, (string) $path->id, $this->ada),
        );
    }

    #[Test]
    public function an_unfinished_course_does_not_count(): void
    {
        $path = $this->paths->save($this->path('Onboarding'));
        $this->paths->saveItem($this->courseItem($path, $this->fireCourse, 1));
        $this->enrol($path, [$this->ada]);

        $this->enrolOnCourse($this->ada, $this->fireCourse, 'in_progress');

        self::assertSame([], $this->paths->completionsFor($this->tenantA, (string) $path->id, $this->ada));
    }

    #[Test]
    public function one_learners_progress_is_not_anothers(): void
    {
        $path = $this->paths->save($this->path('Onboarding'));
        $item = $this->paths->saveItem($this->courseItem($path, $this->fireCourse, 1));
        $this->enrol($path, [$this->ada, $this->grace]);

        $this->completeCourse($this->ada, $this->fireCourse);

        self::assertArrayHasKey(
            (string) $item->id,
            $this->paths->completionsFor($this->tenantA, (string) $path->id, $this->ada),
        );
        self::assertSame([], $this->paths->completionsFor($this->tenantA, (string) $path->id, $this->grace));
    }

    #[Test]
    public function a_link_is_finished_by_being_marked(): void
    {
        $path = $this->paths->save($this->path('Onboarding'));
        $item = $this->paths->saveItem(new PathItem(
            id: null,
            tenantId: $this->tenantA,
            pathId: (string) $path->id,
            kind: PathItemKind::Link,
            externalUrl: 'https://example.test/handbook',
            title: 'The handbook',
        ));

        $this->enrol($path, [$this->ada]);
        $enrolment = $this->paths->enrolmentFor($this->tenantA, (string) $path->id, $this->ada);

        self::assertTrue($this->paths->markItem(
            $this->tenantA,
            (string) $enrolment?->id,
            (string) $item->id,
            'viewed',
            $this->ada,
            null,
        ));

        self::assertArrayHasKey(
            (string) $item->id,
            $this->paths->completionsFor($this->tenantA, (string) $path->id, $this->ada),
        );

        // And marking it again changes nothing.
        self::assertFalse($this->paths->markItem(
            $this->tenantA,
            (string) $enrolment?->id,
            (string) $item->id,
            'viewed',
            $this->ada,
            null,
        ));
    }

    #[Test]
    public function a_mark_can_be_taken_back(): void
    {
        $path = $this->paths->save($this->path('Onboarding'));
        $item = $this->paths->saveItem(new PathItem(
            id: null,
            tenantId: $this->tenantA,
            pathId: (string) $path->id,
            kind: PathItemKind::Link,
            externalUrl: 'https://example.test/handbook',
            title: 'The handbook',
        ));

        $this->enrol($path, [$this->ada]);
        $enrolment = $this->paths->enrolmentFor($this->tenantA, (string) $path->id, $this->ada);

        $this->paths->markItem(
            $this->tenantA,
            (string) $enrolment?->id,
            (string) $item->id,
            'viewed',
            $this->ada,
            null,
        );

        self::assertTrue($this->paths->unmarkItem($this->tenantA, (string) $enrolment?->id, (string) $item->id));
        self::assertSame([], $this->paths->completionsFor($this->tenantA, (string) $path->id, $this->ada));
    }

    #[Test]
    public function the_derived_progress_drives_the_whole_path(): void
    {
        // End to end: two courses, one finished, and every figure the legacy
        // stored computed from what is actually true.
        $path  = $this->paths->save($this->path('Onboarding'));
        $first = $this->paths->saveItem($this->courseItem($path, $this->fireCourse, 1));
        $this->paths->saveItem($this->courseItem($path, $this->gdprCourse, 2));
        $this->enrol($path, [$this->ada]);

        $this->completeCourse($this->ada, $this->fireCourse);

        $progress = PathProgress::of(
            $this->paths->items($this->tenantA, (string) $path->id),
            $this->paths->completionsFor($this->tenantA, (string) $path->id, $this->ada),
            PathPacing::Sequential,
            CompletionRule::AllRequiredItems,
        );

        self::assertSame(2, $progress->total);
        self::assertSame(1, $progress->completed);
        self::assertSame(50.0, $progress->percent);
        self::assertFalse($progress->isComplete);
        self::assertNotSame((string) $first->id, $progress->currentItemId);

        $this->completeCourse($this->ada, $this->gdprCourse);

        $after = PathProgress::of(
            $this->paths->items($this->tenantA, (string) $path->id),
            $this->paths->completionsFor($this->tenantA, (string) $path->id, $this->ada),
            PathPacing::Sequential,
            CompletionRule::AllRequiredItems,
        );

        self::assertTrue($after->isComplete);
        self::assertSame(100.0, $after->percent);
    }

    #[Test]
    public function the_statistics_count_what_is_there(): void
    {
        $path = $this->paths->save($this->path('Onboarding'));
        $this->paths->saveItem($this->courseItem($path, $this->fireCourse, 1));
        $this->paths->setStatus($this->tenantA, (string) $path->id, 'published');
        $this->enrol($path, [$this->ada, $this->grace]);

        $enrolment = $this->paths->enrolmentFor($this->tenantA, (string) $path->id, $this->ada);
        $this->paths->markComplete($this->tenantA, (string) $enrolment?->id, null);

        $statistics = $this->paths->statistics($this->tenantA);

        self::assertSame(1, $statistics['paths']);
        self::assertSame(1, $statistics['published']);
        self::assertSame(2, $statistics['enrolments']);
        self::assertSame(1, $statistics['completions']);
        self::assertSame(0, $this->paths->statistics($this->tenantB)['paths']);
    }

    // --- helpers ----------------------------------------------------------

    private function path(string $title, ?string $slug = null): LearningPath
    {
        return new LearningPath(
            id: null,
            tenantId: $this->tenantA,
            title: $title,
            slug: $slug ?? strtolower(str_replace(' ', '-', $title)) . '-' . substr(Uuid::uuid4()->toString(), 0, 8),
        );
    }

    private function section(LearningPath $path, string $title): PathSection
    {
        return new PathSection(
            id: null,
            tenantId: $this->tenantA,
            pathId: (string) $path->id,
            title: $title,
            isMilestone: true,
        );
    }

    private function courseItem(LearningPath $path, string $courseId, int $order): PathItem
    {
        return new PathItem(
            id: null,
            tenantId: $this->tenantA,
            pathId: (string) $path->id,
            kind: PathItemKind::Course,
            courseId: $courseId,
            sortOrder: $order,
        );
    }

    /**
     * @param  list<string> $userIds
     * @return array{enrolled: int, skipped: int}
     */
    private function enrol(LearningPath $path, array $userIds): array
    {
        return $this->paths->enrol(
            $this->tenantA,
            (string) $path->id,
            $userIds,
            'direct',
            null,
            null,
            false,
        );
    }

    private function completeCourse(string $userId, string $courseId): void
    {
        $this->enrolOnCourse($userId, $courseId, 'completed');
    }

    /**
     * Upserts, because enrolling on the path already created the row — which
     * is the point: the learner finishes the enrolment they were given.
     */
    private function enrolOnCourse(string $userId, string $courseId, string $status): void
    {
        self::$connection?->executeStatement(
            "INSERT INTO enrollments
                 (tenant_id, course_id, user_id, status, source, progress_percentage, started_at, completed_at)
             VALUES (:t, :c, :u, :s, 'direct',
                     CASE WHEN :s2 = 'completed' THEN 100 ELSE 10 END,
                     now() - interval '1 day',
                     CASE WHEN :s3 = 'completed' THEN now() ELSE NULL END)
             ON CONFLICT (course_id, user_id) WHERE deleted_at IS NULL DO UPDATE
             SET status = EXCLUDED.status,
                 progress_percentage = EXCLUDED.progress_percentage,
                 started_at = EXCLUDED.started_at,
                 completed_at = EXCLUDED.completed_at",
            ['t' => $this->tenantA, 'c' => $courseId, 'u' => $userId, 's' => $status, 's2' => $status, 's3' => $status],
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

    private function createUser(string $tenantId, string $first): string
    {
        $id = Uuid::uuid4()->toString();

        self::$connection?->executeStatement(
            "INSERT INTO users (id, tenant_id, email, password_hash, first_name, last_name, status)
             VALUES (:id, :t, :e, 'x', :f, 'Tester', 'active')",
            [
                'id' => $id,
                't'  => $tenantId,
                'e'  => strtolower($first) . '-' . substr($id, 0, 8) . '@lp.test',
                'f'  => $first,
            ],
        );

        return $id;
    }

    private function createCourse(string $title): string
    {
        $id = Uuid::uuid4()->toString();

        self::$connection?->executeStatement(
            "INSERT INTO courses (id, tenant_id, title, status) VALUES (:id, :t, :ti, 'published')",
            ['id' => $id, 't' => $this->tenantA, 'ti' => $title],
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
