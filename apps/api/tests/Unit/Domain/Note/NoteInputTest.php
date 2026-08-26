<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Note;

use Central\Api\Domain\Note\NoteInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What may be saved as a note.
 *
 * The rules are few, which is the point: `user/notes/create.php` checked that
 * `content` was non-empty and then handed everything to a statement that could
 * not run, because `class/Note.php` inserts `title` and `lesson_timestamp` and
 * the table has neither column.
 *
 * The case that earns its keep here is the distinction between a field left out
 * of an edit and a field cleared by one.
 */
#[CoversClass(NoteInput::class)]
final class NoteInputTest extends TestCase
{
    private const LESSON = '11111111-1111-4111-8111-111111111111';

    #[Test]
    public function an_ordinary_note_is_accepted(): void
    {
        $violations = [];

        $input = NoteInput::fromBody(
            ['lessonId' => self::LESSON, 'content' => 'Water, foam, CO2.', 'positionSeconds' => 92],
            $violations,
            requireLesson: true,
        );

        self::assertSame([], $violations);
        self::assertNotNull($input);
        self::assertSame('Water, foam, CO2.', $input->content);
        self::assertSame(92, $input->positionSeconds);
        self::assertSame(self::LESSON, $input->lessonId);
        self::assertNull($input->title);
    }

    #[Test]
    public function an_empty_note_is_refused(): void
    {
        // Whitespace is empty. A note of three spaces is a mis-click.
        $violations = [];

        self::assertNull(
            NoteInput::fromBody(['lessonId' => self::LESSON, 'content' => "  \n "], $violations, true),
        );
        self::assertArrayHasKey('content', $violations);
    }

    #[Test]
    public function a_new_note_must_say_which_lesson_it_is_about(): void
    {
        $violations = [];

        self::assertNull(NoteInput::fromBody(['content' => 'Floating'], $violations, requireLesson: true));
        self::assertArrayHasKey('lessonId', $violations);
    }

    #[Test]
    public function an_edit_does_not_need_one_because_a_note_never_moves(): void
    {
        $violations = [];

        $input = NoteInput::fromBody(['content' => 'Corrected.'], $violations, requireLesson: false);

        self::assertSame([], $violations);
        self::assertNull($input?->lessonId);
    }

    #[Test]
    public function a_malformed_lesson_id_is_not_a_lesson_id(): void
    {
        // Caught here rather than at the database, where a non-UUID raises
        // SQLSTATE[22P02] and surfaces as a 500.
        $violations = [];

        self::assertNull(
            NoteInput::fromBody(['lessonId' => 'lesson-one', 'content' => 'x'], $violations, true),
        );
        self::assertArrayHasKey('lessonId', $violations);
    }

    #[Test]
    public function a_position_before_the_start_of_a_recording_is_refused(): void
    {
        $violations = [];

        self::assertNull(
            NoteInput::fromBody(
                ['lessonId' => self::LESSON, 'content' => 'x', 'positionSeconds' => -5],
                $violations,
                true,
            ),
        );
        self::assertArrayHasKey('positionSeconds', $violations);
    }

    #[Test]
    public function a_note_on_a_lesson_with_no_recording_has_no_position(): void
    {
        $violations = [];

        $input = NoteInput::fromBody(['lessonId' => self::LESSON, 'content' => 'x'], $violations, true);

        self::assertNotNull($input);
        self::assertNull($input->positionSeconds);
        self::assertFalse($input->touchesPosition);
    }

    #[Test]
    public function a_field_left_out_is_not_a_field_cleared(): void
    {
        // The distinction the whole edit path rests on. An edit form that
        // sends only the changed field must not wipe the title, the position
        // and the pin on its way past.
        $violations = [];

        $input = NoteInput::fromBody(['content' => 'Just the text.'], $violations, false);

        self::assertNotNull($input);
        self::assertFalse($input->touchesTitle);
        self::assertFalse($input->touchesPosition);
        self::assertNull($input->isPinned);
    }

    #[Test]
    public function a_field_sent_empty_is_a_field_cleared(): void
    {
        $violations = [];

        $input = NoteInput::fromBody(
            ['content' => 'Text.', 'title' => '', 'positionSeconds' => null],
            $violations,
            false,
        );

        self::assertNotNull($input);
        self::assertTrue($input->touchesTitle);
        self::assertNull($input->title);
        self::assertTrue($input->touchesPosition);
        self::assertNull($input->positionSeconds);
    }

    #[Test]
    public function the_pin_is_carried_only_when_it_is_mentioned(): void
    {
        $violations = [];
        $pinned     = NoteInput::fromBody(['content' => 'x', 'isPinned' => true], $violations, false);

        $violations = [];
        $unpinned   = NoteInput::fromBody(['content' => 'x', 'isPinned' => 'false'], $violations, false);

        self::assertTrue($pinned?->isPinned);
        self::assertFalse($unpinned?->isPinned);
    }

    #[Test]
    public function a_novel_is_not_a_note(): void
    {
        $violations = [];

        self::assertNull(
            NoteInput::fromBody(
                ['lessonId' => self::LESSON, 'content' => str_repeat('a', 20_001)],
                $violations,
                true,
            ),
        );
        self::assertArrayHasKey('content', $violations);
    }

    #[Test]
    public function a_long_title_is_trimmed_rather_than_refused(): void
    {
        // Refusing a title somebody pasted is worse than shortening it: the
        // note itself is the thing they wrote.
        $violations = [];

        $input = NoteInput::fromBody(
            ['lessonId' => self::LESSON, 'content' => 'x', 'title' => str_repeat('t', 500)],
            $violations,
            true,
        );

        self::assertSame([], $violations);
        self::assertSame(200, mb_strlen((string) $input?->title));
    }
}
