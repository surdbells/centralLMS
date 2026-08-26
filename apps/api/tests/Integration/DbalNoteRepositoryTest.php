<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Note\Note;
use Central\Api\Domain\Note\NoteFilters;
use Central\Api\Domain\Note\NoteInput;
use Central\Api\Infrastructure\Persistence\DbalNoteRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Learner notes against a real PostgreSQL.
 *
 * Two things need the database. The first is that no query here can reach
 * another person's notes — the legacy took `user_id` from the request body in
 * all eleven of its endpoints. The second is that an edit touches only what it
 * mentioned, which is a CASE in the UPDATE rather than something a value object
 * can enforce.
 */
#[CoversClass(DbalNoteRepository::class)]
#[CoversClass(Note::class)]
final class DbalNoteRepositoryTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalNoteRepository $notes;

    private string $tenantA;
    private string $tenantB;
    private string $ada;
    private string $grace;
    private string $courseId;
    private string $lessonOne;
    private string $lessonTwo;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable — start it with: pnpm up');
        }

        $this->notes = new DbalNoteRepository(self::$connection);

        $this->tenantA = $this->createTenant('note-a');
        $this->tenantB = $this->createTenant('note-b');
        $this->ada     = $this->createUser($this->tenantA, 'Ada');
        $this->grace   = $this->createUser($this->tenantA, 'Grace');

        $this->courseId  = $this->createCourse('Fire Safety');
        $moduleId        = $this->createModule($this->courseId);
        $this->lessonOne = $this->createLesson($this->courseId, $moduleId, 'Extinguishers');
        $this->lessonTwo = $this->createLesson($this->courseId, $moduleId, 'Evacuation');

        $this->enrol($this->ada, $this->courseId);
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

            foreach (['notes', 'enrollments', 'lessons', 'course_modules', 'courses', 'users'] as $table) {
                self::$connection->executeStatement(
                    sprintf('DELETE FROM %s WHERE tenant_id = :id', $table),
                    ['id' => $tenantId],
                );
            }

            self::$connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $tenantId]);
        }
    }

    // --- reachability -----------------------------------------------------

    #[Test]
    public function a_lesson_on_a_course_you_are_taking_is_reachable(): void
    {
        self::assertSame(
            $this->courseId,
            $this->notes->courseForReachableLesson($this->tenantA, $this->ada, $this->lessonOne),
        );
    }

    #[Test]
    public function a_lesson_on_a_course_you_are_not_taking_is_not(): void
    {
        // Grace is not enrolled. Writing a note against a lesson she cannot
        // open would be a way of asking which lesson ids exist, one at a time.
        self::assertNull(
            $this->notes->courseForReachableLesson($this->tenantA, $this->grace, $this->lessonOne),
        );
    }

    #[Test]
    public function a_dropped_enrolment_closes_the_lesson_again(): void
    {
        self::$connection?->executeStatement(
            "UPDATE enrollments SET status = 'dropped' WHERE user_id = :u",
            ['u' => $this->ada],
        );

        self::assertNull(
            $this->notes->courseForReachableLesson($this->tenantA, $this->ada, $this->lessonOne),
        );
    }

    #[Test]
    public function another_tenants_lesson_is_not_reachable(): void
    {
        self::assertNull(
            $this->notes->courseForReachableLesson($this->tenantB, $this->ada, $this->lessonOne),
        );
    }

    // --- writing ----------------------------------------------------------

    #[Test]
    public function a_note_comes_back_with_its_lesson_and_course_named(): void
    {
        $note = $this->notes->create($this->note('Water, foam, CO2.', positionSeconds: 92));

        self::assertSame('Extinguishers', $note->lessonTitle);
        self::assertSame('Module 1', $note->moduleTitle);
        self::assertSame('Fire Safety', $note->courseTitle);
        self::assertSame(92, $note->positionSeconds);
        self::assertFalse($note->isPinned);
    }

    #[Test]
    public function a_note_is_invisible_to_everybody_else(): void
    {
        $note = $this->notes->create($this->note('Private.'));

        self::assertNotNull($this->notes->find($this->tenantA, $this->ada, (string) $note->id));
        // A colleague in the same tenant.
        self::assertNull($this->notes->find($this->tenantA, $this->grace, (string) $note->id));
        // And another tenant, with the same person id, which cannot happen but
        // is what the query would have to rely on if the tenant were dropped.
        self::assertNull($this->notes->find($this->tenantB, $this->ada, (string) $note->id));
    }

    #[Test]
    public function nobody_else_can_edit_or_delete_it(): void
    {
        $note = $this->notes->create($this->note('Mine.'));

        self::assertNull(
            $this->notes->update($this->tenantA, $this->grace, (string) $note->id, $this->input('Yours.')),
        );
        self::assertFalse($this->notes->softDelete($this->tenantA, $this->grace, (string) $note->id));
        self::assertSame('Mine.', $this->notes->find($this->tenantA, $this->ada, (string) $note->id)?->content);
    }

    // --- editing ----------------------------------------------------------

    #[Test]
    public function an_edit_touches_only_what_it_mentioned(): void
    {
        // The bug this exists to stop: a form that sends the one field it
        // changed, wiping the title and the position on its way past.
        $note = $this->notes->create(
            $this->note('Water, foam, CO2.', title: 'Extinguishers', positionSeconds: 92, pinned: true),
        );

        $updated = $this->notes->update(
            $this->tenantA,
            $this->ada,
            (string) $note->id,
            $this->input('Water, foam, CO2, wet chemical.'),
        );

        self::assertNotNull($updated);
        self::assertSame('Water, foam, CO2, wet chemical.', $updated->content);
        self::assertSame('Extinguishers', $updated->title);
        self::assertSame(92, $updated->positionSeconds);
        self::assertTrue($updated->isPinned);
    }

    #[Test]
    public function a_field_sent_empty_is_cleared(): void
    {
        $note = $this->notes->create($this->note('Text.', title: 'Extinguishers', positionSeconds: 92));

        $violations = [];
        $input      = NoteInput::fromBody(
            ['content' => 'Text.', 'title' => '', 'positionSeconds' => null],
            $violations,
            false,
        );

        self::assertNotNull($input);

        $updated = $this->notes->update($this->tenantA, $this->ada, (string) $note->id, $input);

        self::assertNotNull($updated);
        self::assertNull($updated->title);
        self::assertNull($updated->positionSeconds);
    }

    #[Test]
    public function the_pin_is_set_rather_than_flipped(): void
    {
        // `toggle_pin.php` ran `SET is_pinned = NOT is_pinned`, so two taps
        // racing each other land wherever the second write computed and
        // neither client is told which.
        $note = $this->notes->create($this->note('Text.'));

        $pin = fn (bool $pinned): ?Note => $this->notes->setPinned(
            $this->tenantA,
            $this->ada,
            (string) $note->id,
            $pinned,
        );

        $first  = $pin(true);
        $again  = $pin(true);
        $undone = $pin(false);

        self::assertNotNull($first);
        self::assertNotNull($again);
        self::assertNotNull($undone);

        self::assertTrue($first->isPinned);
        self::assertTrue($again->isPinned);
        self::assertFalse($undone->isPinned);
    }

    #[Test]
    public function a_deleted_note_stays_deleted(): void
    {
        $note = $this->notes->create($this->note('Text.'));

        self::assertTrue($this->notes->softDelete($this->tenantA, $this->ada, (string) $note->id));
        self::assertFalse($this->notes->softDelete($this->tenantA, $this->ada, (string) $note->id));
        self::assertNull($this->notes->find($this->tenantA, $this->ada, (string) $note->id));
    }

    // --- finding ----------------------------------------------------------

    #[Test]
    public function pinned_notes_come_first_and_then_by_where_they_were_written(): void
    {
        $this->notes->create($this->note('Third.', positionSeconds: 300));
        $this->notes->create($this->note('First.', positionSeconds: 10));
        $pinned = $this->notes->create($this->note('Pinned.', positionSeconds: 900));

        $this->notes->setPinned($this->tenantA, $this->ada, (string) $pinned->id, true);

        $page = $this->notes->paginate($this->tenantA, $this->ada, NoteFilters::all(), 1, 25);

        self::assertSame(
            ['Pinned.', 'First.', 'Third.'],
            array_map(static fn (Note $n): string => $n->content, $page['items']),
        );
    }

    #[Test]
    public function a_note_with_no_position_sorts_after_the_ones_that_have_one(): void
    {
        // NULLS LAST, or a note written on a text lesson jumps to the top of
        // a video's list.
        $this->notes->create($this->note('No position.'));
        $this->notes->create($this->note('At ten seconds.', positionSeconds: 10));

        $page = $this->notes->paginate($this->tenantA, $this->ada, NoteFilters::all(), 1, 25);

        self::assertSame('At ten seconds.', $page['items'][0]->content);
    }

    #[Test]
    public function recent_first_ignores_the_pin(): void
    {
        $pinned = $this->notes->create($this->note('Older, pinned.'));
        $this->notes->setPinned($this->tenantA, $this->ada, (string) $pinned->id, true);
        $newer = $this->notes->create($this->note('Newer.'));

        $page = $this->notes->paginate(
            $this->tenantA,
            $this->ada,
            NoteFilters::fromQuery(['recent' => 'true']),
            1,
            25,
        );

        self::assertSame($newer->id, $page['items'][0]->id);
    }

    #[Test]
    public function the_filters_narrow_to_one_lesson_one_course_and_the_pinned(): void
    {
        $this->notes->create($this->note('On extinguishers.', lessonId: $this->lessonOne));
        $this->notes->create($this->note('On evacuation.', lessonId: $this->lessonTwo));
        $pinned = $this->notes->create($this->note('Important.', lessonId: $this->lessonTwo));

        $this->notes->setPinned($this->tenantA, $this->ada, (string) $pinned->id, true);

        $count = fn (NoteFilters $f): int => $this->notes->paginate($this->tenantA, $this->ada, $f, 1, 25)['total'];

        self::assertSame(3, $count(NoteFilters::all()));
        self::assertSame(1, $count(NoteFilters::fromQuery(['lessonId' => $this->lessonOne])));
        self::assertSame(3, $count(NoteFilters::fromQuery(['courseId' => $this->courseId])));
        self::assertSame(1, $count(NoteFilters::fromQuery(['pinned' => 'true'])));
        self::assertSame(2, $count(NoteFilters::fromQuery(['pinned' => 'false'])));
    }

    #[Test]
    public function the_search_covers_the_title_as_well_as_the_text(): void
    {
        $this->notes->create($this->note('Water, foam, CO2.', title: 'Extinguishers'));
        $this->notes->create($this->note('Routes are on every floor.'));

        $count = fn (string $term): int => $this->notes->paginate(
            $this->tenantA,
            $this->ada,
            NoteFilters::fromQuery(['search' => $term]),
            1,
            25,
        )['total'];

        self::assertSame(1, $count('extinguish'));
        self::assertSame(1, $count('routes'));
        self::assertSame(0, $count('nothing here'));
    }

    #[Test]
    public function a_listing_holds_only_the_callers_own_notes(): void
    {
        $this->enrol($this->grace, $this->courseId);

        $this->notes->create($this->note('Ada wrote this.'));
        $this->notes->create($this->note('Grace wrote this.', userId: $this->grace));

        self::assertSame(1, $this->notes->paginate($this->tenantA, $this->ada, NoteFilters::all(), 1, 25)['total']);
        self::assertSame(1, $this->notes->paginate($this->tenantA, $this->grace, NoteFilters::all(), 1, 25)['total']);
    }

    #[Test]
    public function the_totals_are_the_callers_own(): void
    {
        $this->enrol($this->grace, $this->courseId);

        $pinned = $this->notes->create($this->note('Ada, pinned.'));
        $this->notes->setPinned($this->tenantA, $this->ada, (string) $pinned->id, true);
        $this->notes->create($this->note('Ada, plain.'));
        $this->notes->create($this->note('Grace.', userId: $this->grace));

        self::assertSame(
            ['total' => 2, 'pinned' => 1, 'courses' => 1],
            $this->notes->statistics($this->tenantA, $this->ada),
        );
    }

    // --- helpers ----------------------------------------------------------

    private function note(
        string $content,
        ?string $title = null,
        ?int $positionSeconds = null,
        bool $pinned = false,
        ?string $lessonId = null,
        ?string $userId = null,
    ): Note {
        return new Note(
            id: null,
            tenantId: $this->tenantA,
            userId: $userId ?? $this->ada,
            lessonId: $lessonId ?? $this->lessonOne,
            courseId: $this->courseId,
            content: $content,
            title: $title,
            positionSeconds: $positionSeconds,
            isPinned: $pinned,
        );
    }

    private function input(string $content): NoteInput
    {
        $violations = [];
        $input      = NoteInput::fromBody(['content' => $content], $violations, false);

        self::assertNotNull($input, (string) json_encode($violations));

        return $input;
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
                'e'  => strtolower($first) . '-' . substr($id, 0, 8) . '@note.test',
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

    private function createModule(string $courseId): string
    {
        return (string) self::$connection?->fetchOne(
            "INSERT INTO course_modules (tenant_id, course_id, title, sort_order)
             VALUES (:t, :c, 'Module 1', 1) RETURNING id",
            ['t' => $this->tenantA, 'c' => $courseId],
        );
    }

    private function createLesson(string $courseId, string $moduleId, string $title): string
    {
        return (string) self::$connection?->fetchOne(
            "INSERT INTO lessons (tenant_id, course_id, module_id, title, content_type, sort_order)
             VALUES (:t, :c, :m, :ti, 'video', 1)
             RETURNING id",
            ['t' => $this->tenantA, 'c' => $courseId, 'm' => $moduleId, 'ti' => $title],
        );
    }

    private function enrol(string $userId, string $courseId): void
    {
        self::$connection?->executeStatement(
            // `started_at` is not optional for an in-progress enrolment —
            // `enrollments_started_consistent` ties the two together.
            "INSERT INTO enrollments (tenant_id, course_id, user_id, status, source, started_at)
             VALUES (:t, :c, :u, 'in_progress', 'direct', now())",
            ['t' => $this->tenantA, 'c' => $courseId, 'u' => $userId],
        );
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
