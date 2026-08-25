<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Catalog;

use Central\Api\Domain\Catalog\CourseModule;
use Central\Api\Domain\Catalog\Lesson;
use Central\Api\Domain\Catalog\LessonContentType;
use Central\Api\Domain\Exception\ValidationFailed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CourseModule::class)]
final class CourseModuleTest extends TestCase
{
    #[Test]
    public function a_minimal_module_gets_the_documented_defaults(): void
    {
        $module = CourseModule::fromInput('m1', 't1', 'c1', ['title' => 'Week 1']);

        self::assertSame('Week 1', $module->title);
        self::assertSame(0, $module->sortOrder);
        self::assertTrue($module->isActive);
        self::assertNull($module->description);
        self::assertSame([], $module->lessons);
    }

    #[Test]
    public function a_blank_title_is_rejected(): void
    {
        $this->expectException(ValidationFailed::class);

        CourseModule::fromInput('m1', 't1', 'c1', ['title' => '   ']);
    }

    #[Test]
    public function a_title_over_255_characters_is_rejected(): void
    {
        $this->expectException(ValidationFailed::class);

        CourseModule::fromInput('m1', 't1', 'c1', ['title' => str_repeat('a', 256)]);
    }

    #[Test]
    public function a_negative_position_is_rejected(): void
    {
        $this->expectException(ValidationFailed::class);

        CourseModule::fromInput('m1', 't1', 'c1', ['title' => 'X', 'sortOrder' => -1]);
    }

    #[Test]
    public function a_whitespace_only_description_is_stored_as_absent(): void
    {
        $module = CourseModule::fromInput('m1', 't1', 'c1', ['title' => 'X', 'description' => '   ']);

        self::assertNull($module->description);
    }

    #[Test]
    public function the_course_comes_from_the_caller_not_the_body(): void
    {
        $module = CourseModule::fromInput('m1', 't1', 'c1', [
            'title'    => 'X',
            'courseId' => 'attacker-course',
            'tenantId' => 'attacker-tenant',
        ]);

        self::assertSame('c1', $module->courseId);
        self::assertSame('t1', $module->tenantId);
    }

    #[Test]
    public function lessons_are_absent_from_the_payload_unless_asked_for(): void
    {
        // The module list must not carry lesson bodies it never loaded.
        $module = CourseModule::fromInput('m1', 't1', 'c1', ['title' => 'X']);

        self::assertArrayNotHasKey('lessons', $module->toArray());
        self::assertArrayHasKey('lessons', $module->toArray(withLessons: true));
    }

    #[Test]
    public function with_lessons_leaves_the_original_untouched(): void
    {
        $module = CourseModule::fromInput('m1', 't1', 'c1', ['title' => 'X']);

        $lesson = new Lesson(
            id: 'l1',
            tenantId: 't1',
            courseId: 'c1',
            moduleId: 'm1',
            title: 'Intro',
            contentType: LessonContentType::Text,
            contentText: 'Hello',
        );

        $withLessons = $module->withLessons([$lesson]);

        self::assertSame([], $module->lessons);
        self::assertCount(1, $withLessons->lessons);
        // Everything else survives the copy.
        self::assertSame($module->title, $withLessons->title);
        self::assertSame($module->id, $withLessons->id);
        self::assertSame($module->sortOrder, $withLessons->sortOrder);
    }
}
