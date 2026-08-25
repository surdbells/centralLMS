<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Catalog;

use Central\Api\Domain\Catalog\Course;
use Central\Api\Domain\Catalog\CourseStatus;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Shared\Difficulty;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Course::class)]
final class CourseTest extends TestCase
{
    #[Test]
    public function a_minimal_course_gets_the_documented_defaults(): void
    {
        $course = Course::fromInput('c1', 't1', ['title' => 'Fire Safety']);

        self::assertSame('Fire Safety', $course->title);
        self::assertSame(CourseStatus::Draft, $course->status);
        self::assertSame('regular', $course->kind);
        self::assertSame(70, $course->passPercentage);
        self::assertNull($course->level);
        self::assertFalse($course->allowSelfEnrollment);
        self::assertFalse($course->isMandatory);
    }

    #[Test]
    public function the_title_is_trimmed(): void
    {
        $course = Course::fromInput('c1', 't1', ['title' => "  Fire Safety\t"]);

        self::assertSame('Fire Safety', $course->title);
    }

    #[Test]
    public function a_blank_title_is_rejected(): void
    {
        $this->expectException(ValidationFailed::class);

        Course::fromInput('c1', 't1', ['title' => '   ']);
    }

    #[Test]
    public function a_title_over_255_characters_is_rejected(): void
    {
        $this->expectException(ValidationFailed::class);

        Course::fromInput('c1', 't1', ['title' => str_repeat('a', 256)]);
    }

    #[Test]
    public function a_title_of_exactly_255_characters_is_accepted(): void
    {
        // Boundary: 255 is the column width, so it must be allowed.
        $title = str_repeat('a', 255);

        self::assertSame($title, Course::fromInput('c1', 't1', ['title' => $title])->title);
    }

    #[Test]
    #[DataProvider('outOfRangePassMarks')]
    public function a_pass_mark_outside_0_to_100_is_rejected(int $value): void
    {
        $this->expectException(ValidationFailed::class);

        Course::fromInput('c1', 't1', ['title' => 'X', 'passPercentage' => $value]);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function outOfRangePassMarks(): iterable
    {
        yield 'negative' => [-1];
        yield 'over one hundred' => [101];
    }

    #[Test]
    public function the_pass_mark_boundaries_are_inclusive(): void
    {
        self::assertSame(0, Course::fromInput('c1', 't1', ['title' => 'X', 'passPercentage' => 0])->passPercentage);
        self::assertSame(100, Course::fromInput('c1', 't1', ['title' => 'X', 'passPercentage' => 100])->passPercentage);
    }

    #[Test]
    public function level_accepts_the_spellings_the_legacy_column_drifted_across(): void
    {
        foreach (['Beginner', 'beginner', 'easy'] as $spelling) {
            self::assertSame(
                Difficulty::Beginner,
                Course::fromInput('c1', 't1', ['title' => 'X', 'level' => $spelling])->level,
                sprintf('"%s" should resolve to beginner.', $spelling),
            );
        }
    }

    #[Test]
    public function an_empty_level_is_not_a_validation_error(): void
    {
        // The frontend sends '' for "not chosen" — that is absence, not garbage.
        self::assertNull(Course::fromInput('c1', 't1', ['title' => 'X', 'level' => ''])->level);
    }

    #[Test]
    public function an_unknown_level_is_rejected(): void
    {
        $this->expectException(ValidationFailed::class);

        Course::fromInput('c1', 't1', ['title' => 'X', 'level' => 'wizard']);
    }

    #[Test]
    public function an_end_date_before_the_start_date_is_rejected(): void
    {
        $this->expectException(ValidationFailed::class);

        Course::fromInput('c1', 't1', [
            'title'    => 'X',
            'startsOn' => '2026-03-01',
            'endsOn'   => '2026-02-01',
        ]);
    }

    #[Test]
    public function the_same_start_and_end_date_is_allowed(): void
    {
        // A one-day course is a real thing.
        $course = Course::fromInput('c1', 't1', [
            'title'    => 'X',
            'startsOn' => '2026-03-01',
            'endsOn'   => '2026-03-01',
        ]);

        self::assertNotNull($course->endsOn);
        self::assertSame('2026-03-01', $course->endsOn->format('Y-m-d'));
    }

    #[Test]
    public function a_negative_duration_is_rejected(): void
    {
        $this->expectException(ValidationFailed::class);

        Course::fromInput('c1', 't1', ['title' => 'X', 'durationMinutes' => -5]);
    }

    #[Test]
    public function an_enrolment_limit_below_one_is_rejected(): void
    {
        $this->expectException(ValidationFailed::class);

        Course::fromInput('c1', 't1', ['title' => 'X', 'enrollmentLimit' => 0]);
    }

    #[Test]
    public function an_empty_enrolment_limit_means_no_limit_rather_than_zero(): void
    {
        // '' must not become (int) 0 and then read as "nobody may enrol".
        self::assertNull(Course::fromInput('c1', 't1', ['title' => 'X', 'enrollmentLimit' => ''])->enrollmentLimit);
    }

    #[Test]
    public function every_violation_is_reported_at_once(): void
    {
        // Surfacing one error per submit is the legacy behaviour this replaces.
        try {
            Course::fromInput('c1', 't1', [
                'title'           => '',
                'passPercentage'  => 400,
                'durationMinutes' => -1,
            ]);
            self::fail('Expected ValidationFailed.');
        } catch (ValidationFailed $e) {
            self::assertSame(
                ['title', 'passPercentage', 'durationMinutes'],
                array_keys($e->details()['violations']),
            );
        }
    }

    #[Test]
    public function self_enrolment_is_closed_before_the_start_date(): void
    {
        $course = Course::fromInput('c1', 't1', [
            'title'               => 'X',
            'status'              => 'published',
            'allowSelfEnrollment' => true,
            'startsOn'            => '2026-06-01',
        ]);

        self::assertFalse($course->isOpenForSelfEnrollment(new \DateTimeImmutable('2026-05-31')));
        self::assertTrue($course->isOpenForSelfEnrollment(new \DateTimeImmutable('2026-06-02')));
    }

    #[Test]
    public function a_draft_is_never_open_for_self_enrolment(): void
    {
        $course = Course::fromInput('c1', 't1', [
            'title'               => 'X',
            'status'              => 'draft',
            'allowSelfEnrollment' => true,
        ]);

        self::assertFalse($course->isOpenForSelfEnrollment(new \DateTimeImmutable('2026-06-02')));
    }

    #[Test]
    public function boolean_flags_accept_the_strings_a_form_post_sends(): void
    {
        $course = Course::fromInput('c1', 't1', [
            'title'        => 'X',
            'isMandatory'  => 'true',
            'isFeatured'   => '1',
            'isOnboarding' => 'false',
        ]);

        self::assertTrue($course->isMandatory);
        self::assertTrue($course->isFeatured);
        self::assertFalse($course->isOnboarding);
    }
}
