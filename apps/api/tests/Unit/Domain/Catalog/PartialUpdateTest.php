<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Catalog;

use Central\Api\Domain\Catalog\Course;
use Central\Api\Domain\Catalog\CourseModule;
use Central\Api\Domain\Catalog\Lesson;
use Central\Api\Domain\Catalog\LessonContentType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A PUT that sends one field must not reset the others.
 *
 * The update actions rebuild the entity from `[...$existing->toArray(),
 * ...$body]`. That only works while every field `fromInput()` reads is also
 * emitted by `toArray()` under the same key — and it was not: a hand-written
 * carry-over list dropped `durationMinutes` and `description` on lessons, and
 * the courses action reset the pass mark, the level and every boolean flag on
 * any partial edit.
 *
 * These tests exercise that round trip directly, so the guarantee is checked
 * per entity rather than only through one HTTP call per field.
 */
#[CoversClass(Course::class)]
#[CoversClass(CourseModule::class)]
#[CoversClass(Lesson::class)]
final class PartialUpdateTest extends TestCase
{
    #[Test]
    public function editing_a_courses_summary_leaves_everything_else_alone(): void
    {
        $original = Course::fromInput('c1', 't1', [
            'title'               => 'Fire Safety',
            'summary'             => 'original',
            'description'         => 'the description',
            'objectives'          => 'the objectives',
            'kind'                => 'onboarding',
            'level'               => 'professional',
            'passPercentage'      => 85,
            'durationMinutes'     => 120,
            'enrollmentLimit'     => 30,
            'isMandatory'         => true,
            'isFeatured'          => true,
            'isOnboarding'        => true,
            'allowSelfEnrollment' => true,
            'startsOn'            => '2026-06-01',
            'endsOn'              => '2026-06-30',
        ]);

        $updated = Course::fromInput('c1', 't1', [
            ...$original->toArray(),
            'summary' => 'revised',
        ]);

        self::assertSame('revised', $updated->summary);

        self::assertSame($original->title, $updated->title);
        self::assertSame($original->description, $updated->description);
        self::assertSame($original->objectives, $updated->objectives);
        self::assertSame($original->kind, $updated->kind);
        self::assertSame($original->level, $updated->level);
        self::assertSame($original->passPercentage, $updated->passPercentage);
        self::assertSame($original->durationMinutes, $updated->durationMinutes);
        self::assertSame($original->enrollmentLimit, $updated->enrollmentLimit);
        self::assertSame($original->isMandatory, $updated->isMandatory);
        self::assertSame($original->isFeatured, $updated->isFeatured);
        self::assertSame($original->isOnboarding, $updated->isOnboarding);
        self::assertSame($original->allowSelfEnrollment, $updated->allowSelfEnrollment);
        self::assertEquals($original->startsOn, $updated->startsOn);
        self::assertEquals($original->endsOn, $updated->endsOn);
    }

    #[Test]
    public function editing_a_modules_title_keeps_its_description_and_position(): void
    {
        $original = CourseModule::fromInput('m1', 't1', 'c1', [
            'title'       => 'Week 1',
            'description' => 'the description',
            'sortOrder'   => 4,
            'isActive'    => false,
        ]);

        $updated = CourseModule::fromInput('m1', 't1', 'c1', [
            ...$original->toArray(),
            'title' => 'Week One',
        ]);

        self::assertSame('Week One', $updated->title);
        self::assertSame('the description', $updated->description);
        self::assertSame(4, $updated->sortOrder);
        self::assertFalse($updated->isActive);
    }

    #[Test]
    public function editing_a_lessons_title_keeps_its_duration_and_description(): void
    {
        // The two fields the hand-written carry-over list forgot.
        $original = Lesson::fromInput('l1', 't1', 'c1', 'm1', [
            'title'           => 'Intro',
            'description'     => 'the description',
            'contentType'     => 'video',
            'contentUrl'      => 'https://example.test/v.mp4',
            'durationMinutes' => 10,
            'sortOrder'       => 3,
            'isMandatory'     => false,
            'isPreview'       => true,
            'isActive'        => false,
        ]);

        $updated = Lesson::fromInput('l1', 't1', 'c1', 'm1', [
            ...$original->toArray(),
            'title' => 'Intro, revised',
        ]);

        self::assertSame('Intro, revised', $updated->title);
        self::assertSame(10, $updated->durationMinutes);
        self::assertSame('the description', $updated->description);
        self::assertSame(LessonContentType::Video, $updated->contentType);
        self::assertSame('https://example.test/v.mp4', $updated->contentUrl);
        self::assertSame(3, $updated->sortOrder);
        self::assertFalse($updated->isMandatory);
        self::assertTrue($updated->isPreview);
        self::assertFalse($updated->isActive);
    }

    #[Test]
    public function inline_lesson_content_survives_a_partial_update(): void
    {
        $original = Lesson::fromInput('l1', 't1', 'c1', 'm1', [
            'title'       => 'Reading',
            'contentType' => 'html',
            'contentText' => '<p>Body</p>',
        ]);

        $updated = Lesson::fromInput('l1', 't1', 'c1', 'm1', [
            ...$original->toArray(),
            'title' => 'Reading, revised',
        ]);

        self::assertSame('<p>Body</p>', $updated->contentText);
    }

    #[Test]
    public function a_round_trip_with_no_changes_at_all_is_a_no_op(): void
    {
        // The strongest form of the guarantee: toArray() must round-trip
        // through fromInput() unchanged, or some field is being dropped.
        $course = Course::fromInput('c1', 't1', [
            'title'          => 'Fire Safety',
            'level'          => 'intermediate',
            'passCentage'    => 0, // ignored, and must not upset anything
            'passPercentage' => 55,
            'isFeatured'     => true,
        ]);

        $again = Course::fromInput('c1', 't1', $course->toArray());

        self::assertEquals($course->toArray(), $again->toArray());
    }

    #[Test]
    public function a_lesson_round_trips_through_its_own_payload(): void
    {
        $lesson = Lesson::fromInput('l1', 't1', 'c1', 'm1', [
            'title'           => 'Intro',
            'contentType'     => 'pdf',
            'contentUrl'      => 'https://example.test/f.pdf',
            'durationMinutes' => 7,
            'isPreview'       => true,
        ]);

        $again = Lesson::fromInput('l1', 't1', 'c1', 'm1', $lesson->toArray());

        self::assertEquals($lesson->toArray(), $again->toArray());
    }

    #[Test]
    public function a_module_round_trips_through_its_own_payload(): void
    {
        $module = CourseModule::fromInput('m1', 't1', 'c1', [
            'title'       => 'Week 1',
            'description' => 'Something',
            'sortOrder'   => 2,
        ]);

        $again = CourseModule::fromInput('m1', 't1', 'c1', $module->toArray());

        self::assertEquals($module->toArray(), $again->toArray());
    }
}
