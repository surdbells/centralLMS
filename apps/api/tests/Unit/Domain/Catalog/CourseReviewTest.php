<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Catalog;

use Central\Api\Domain\Catalog\CourseReview;
use Central\Api\Domain\Exception\ValidationFailed;
use PHPUnit\Framework\TestCase;

final class CourseReviewTest extends TestCase
{
    private const T = '11111111-1111-1111-1111-111111111111';
    private const C = '22222222-2222-2222-2222-222222222222';
    private const U = '33333333-3333-3333-3333-333333333333';
    private const E = '44444444-4444-4444-4444-444444444444';

    private function make(array $input): CourseReview
    {
        return CourseReview::fromInput('', self::T, self::C, self::U, self::E, $input);
    }

    public function testBuildsFromAValidRating(): void
    {
        $review = $this->make(['rating' => 4, 'reviewText' => '  great course  ', 'title' => ' Solid ']);

        self::assertSame(4, $review->rating);
        self::assertSame('great course', $review->reviewText);
        self::assertSame('Solid', $review->title);
        self::assertSame(self::E, $review->enrollmentId);
        self::assertTrue($review->isPublished);
        self::assertSame('approved', $review->moderationStatus);
    }

    /**
     * @dataProvider badRatings
     */
    public function testRejectsRatingsOutOfRange(mixed $rating): void
    {
        $this->expectException(ValidationFailed::class);
        $this->make(['rating' => $rating]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badRatings(): iterable
    {
        yield 'zero'    => [0];
        yield 'six'     => [6];
        yield 'missing' => [null];
        yield 'negative' => [-3];
    }

    public function testEmptySubScoresBecomeNullNotZero(): void
    {
        $review = $this->make(['rating' => 5, 'contentRating' => '', 'difficultyRating' => null]);

        self::assertNull($review->contentRating);
        self::assertNull($review->difficultyRating);
        self::assertNull($review->practicalValueRating);
    }

    public function testKeepsValidSubScores(): void
    {
        $review = $this->make([
            'rating' => 5,
            'contentRating' => 4,
            'difficultyRating' => 2,
            'practicalValueRating' => 5,
        ]);

        self::assertSame(4, $review->contentRating);
        self::assertSame(2, $review->difficultyRating);
        self::assertSame(5, $review->practicalValueRating);
    }

    public function testRejectsSubScoresOutOfRange(): void
    {
        $this->expectException(ValidationFailed::class);
        $this->make(['rating' => 5, 'contentRating' => 9]);
    }

    public function testBlankTextIsNull(): void
    {
        $review = $this->make(['rating' => 3, 'reviewText' => '   ', 'title' => '']);

        self::assertNull($review->reviewText);
        self::assertNull($review->title);
    }

    public function testToArrayCarriesTheRatingAndModeration(): void
    {
        $array = $this->make(['rating' => 4])->toArray();

        self::assertSame(4, $array['rating']);
        self::assertSame('approved', $array['moderationStatus']);
        self::assertTrue($array['isPublished']);
        self::assertSame(self::C, $array['courseId']);
    }
}
