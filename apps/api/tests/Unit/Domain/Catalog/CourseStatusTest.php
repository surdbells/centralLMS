<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Catalog;

use Central\Api\Domain\Catalog\CourseStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CourseStatus::class)]
final class CourseStatusTest extends TestCase
{
    #[Test]
    #[DataProvider('legacyValues')]
    public function legacy_values_map_onto_the_enum(?string $input, CourseStatus $expected): void
    {
        self::assertSame($expected, CourseStatus::fromLegacy($input));
    }

    /**
     * @return iterable<string, array{?string, CourseStatus}>
     */
    public static function legacyValues(): iterable
    {
        yield 'draft' => ['draft', CourseStatus::Draft];
        yield 'published' => ['published', CourseStatus::Published];
        yield 'archived' => ['archived', CourseStatus::Archived];
        yield 'mixed case' => ['Published', CourseStatus::Published];
        yield 'padded' => ['  published  ', CourseStatus::Published];

        // The legacy frontend also sent 'delete'. Removal is `deleted_at`, so
        // it is not a status; falling back to draft keeps it out of the
        // catalogue, which is the safe direction.
        yield 'delete is not a status' => ['delete', CourseStatus::Draft];
        yield 'unknown' => ['whatever', CourseStatus::Draft];
        yield 'null' => [null, CourseStatus::Draft];
        yield 'empty' => ['', CourseStatus::Draft];
    }

    #[Test]
    public function only_published_is_visible_to_learners(): void
    {
        self::assertTrue(CourseStatus::Published->isVisibleToLearners());
        self::assertFalse(CourseStatus::Draft->isVisibleToLearners());
        self::assertFalse(CourseStatus::Archived->isVisibleToLearners());
    }

    #[Test]
    public function a_published_course_is_still_editable(): void
    {
        // Correcting a typo must not require unpublishing and re-enrolling.
        self::assertTrue(CourseStatus::Published->isEditable());
        self::assertTrue(CourseStatus::Draft->isEditable());
        self::assertFalse(CourseStatus::Archived->isEditable());
    }

    #[Test]
    public function archiving_does_not_cut_off_learners_already_partway_through(): void
    {
        self::assertTrue(CourseStatus::Archived->allowsExistingLearnersToContinue());
        self::assertTrue(CourseStatus::Published->allowsExistingLearnersToContinue());
        self::assertFalse(CourseStatus::Draft->allowsExistingLearnersToContinue());
    }
}
