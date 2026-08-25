<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Catalog;

use Central\Api\Domain\Catalog\Lesson;
use Central\Api\Domain\Catalog\LessonContentType;
use Central\Api\Domain\Exception\ValidationFailed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Lesson::class)]
final class LessonTest extends TestCase
{
    /**
     * @param array<string, mixed> $extra
     */
    private static function make(array $extra = []): Lesson
    {
        return Lesson::fromInput('l1', 't1', 'c1', 'm1', [
            'title'       => 'Intro',
            'contentType' => 'text',
            'contentText' => 'Hello',
            ...$extra,
        ]);
    }

    #[Test]
    public function a_minimal_lesson_gets_the_documented_defaults(): void
    {
        $lesson = self::make();

        self::assertSame('Intro', $lesson->title);
        self::assertSame(LessonContentType::Text, $lesson->contentType);
        self::assertSame(0, $lesson->sortOrder);
        self::assertTrue($lesson->isActive);
        self::assertFalse($lesson->isPreview);
        // Mandatory by default: an optional lesson is the deliberate choice.
        self::assertTrue($lesson->isMandatory);
        self::assertNull($lesson->durationMinutes);
    }

    #[Test]
    public function the_course_and_module_come_from_the_caller_not_the_body(): void
    {
        // The action takes both from the resolved module, so a client cannot
        // write a lesson into someone else's course by sending ids.
        $lesson = Lesson::fromInput('l1', 't1', 'c1', 'm1', [
            'title'       => 'Intro',
            'contentType' => 'text',
            'contentText' => 'Hello',
            'courseId'    => 'attacker-course',
            'moduleId'    => 'attacker-module',
            'tenantId'    => 'attacker-tenant',
        ]);

        self::assertSame('c1', $lesson->courseId);
        self::assertSame('m1', $lesson->moduleId);
        self::assertSame('t1', $lesson->tenantId);
    }

    #[Test]
    public function a_blank_title_is_rejected(): void
    {
        $this->expectException(ValidationFailed::class);

        self::make(['title' => '  ']);
    }

    #[Test]
    public function a_missing_content_type_is_rejected(): void
    {
        $this->expectException(ValidationFailed::class);

        Lesson::fromInput('l1', 't1', 'c1', 'm1', ['title' => 'Intro']);
    }

    #[Test]
    public function an_unknown_content_type_is_rejected(): void
    {
        $this->expectException(ValidationFailed::class);

        self::make(['contentType' => 'hologram']);
    }

    #[Test]
    #[DataProvider('typesNeedingAUrl')]
    public function content_that_lives_at_a_url_must_have_one(string $type): void
    {
        try {
            Lesson::fromInput('l1', 't1', 'c1', 'm1', ['title' => 'X', 'contentType' => $type]);
            self::fail(sprintf('A %s lesson with no URL should be rejected.', $type));
        } catch (ValidationFailed $e) {
            self::assertArrayHasKey('contentUrl', $e->details()['violations']);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function typesNeedingAUrl(): iterable
    {
        yield 'video' => ['video'];
        yield 'audio' => ['audio'];
        yield 'pdf' => ['pdf'];
        yield 'scorm' => ['scorm'];
    }

    #[Test]
    public function inline_content_must_not_be_empty(): void
    {
        try {
            Lesson::fromInput('l1', 't1', 'c1', 'm1', ['title' => 'X', 'contentType' => 'html']);
            self::fail('An HTML lesson with no content should be rejected.');
        } catch (ValidationFailed $e) {
            self::assertArrayHasKey('contentText', $e->details()['violations']);
        }
    }

    #[Test]
    public function a_quiz_needs_neither_a_url_nor_text(): void
    {
        // A quiz points at an assessment, which the assessments module owns.
        $lesson = Lesson::fromInput('l1', 't1', 'c1', 'm1', ['title' => 'Test', 'contentType' => 'quiz']);

        self::assertSame(LessonContentType::Quiz, $lesson->contentType);
        self::assertNull($lesson->contentUrl);
        self::assertNull($lesson->contentText);
    }

    #[Test]
    public function whitespace_only_content_counts_as_missing(): void
    {
        // '   ' passing as a URL is how an empty lesson reaches a learner.
        $this->expectException(ValidationFailed::class);

        self::make(['contentType' => 'video', 'contentUrl' => '    ']);
    }

    #[Test]
    public function a_negative_duration_is_rejected(): void
    {
        $this->expectException(ValidationFailed::class);

        self::make(['durationMinutes' => -1]);
    }

    #[Test]
    public function an_empty_duration_means_unknown_rather_than_zero(): void
    {
        self::assertNull(self::make(['durationMinutes' => ''])->durationMinutes);
    }

    #[Test]
    public function a_negative_position_is_rejected(): void
    {
        $this->expectException(ValidationFailed::class);

        self::make(['sortOrder' => -1]);
    }

    #[Test]
    public function a_deactivated_preview_lesson_is_not_freely_viewable(): void
    {
        self::assertTrue(self::make(['isPreview' => true])->isFreelyViewable());
        self::assertFalse(self::make(['isPreview' => true, 'isActive' => false])->isFreelyViewable());
        self::assertFalse(self::make(['isPreview' => false])->isFreelyViewable());
    }

    #[Test]
    public function every_violation_is_reported_at_once(): void
    {
        try {
            Lesson::fromInput('l1', 't1', 'c1', 'm1', [
                'title'           => '',
                'contentType'     => 'video',
                'durationMinutes' => -5,
                'sortOrder'       => -2,
            ]);
            self::fail('Expected ValidationFailed.');
        } catch (ValidationFailed $e) {
            self::assertSame(
                ['title', 'contentUrl', 'durationMinutes', 'sortOrder'],
                array_keys($e->details()['violations']),
            );
        }
    }
}
