<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\LearningPlan;

use Central\Api\Domain\LearningPlan\LearningPlan;
use Central\Api\Domain\Exception\ValidationFailed;
use PHPUnit\Framework\TestCase;

final class LearningPlanTest extends TestCase
{
    private const T = '11111111-1111-1111-1111-111111111111';

    private function make(array $input): LearningPlan
    {
        return LearningPlan::fromInput('', self::T, $input);
    }

    public function testBuildsAValidPlan(): void
    {
        $plan = $this->make(['year' => 2026, 'title' => '  2026 Learning Plan  ', 'description' => ' yearly ']);

        self::assertSame(2026, $plan->year);
        self::assertSame('2026 Learning Plan', $plan->title);
        self::assertSame('yearly', $plan->description);
        self::assertSame('draft', $plan->status);
    }

    public function testRejectsAYearOutOfRange(): void
    {
        $this->expectException(ValidationFailed::class);
        $this->make(['year' => 1999, 'title' => 'Plan']);
    }

    public function testRejectsAMissingTitle(): void
    {
        $this->expectException(ValidationFailed::class);
        $this->make(['year' => 2026, 'title' => '   ']);
    }

    public function testRejectsAnUnknownStatus(): void
    {
        $this->expectException(ValidationFailed::class);
        $this->make(['year' => 2026, 'title' => 'Plan', 'status' => 'live']);
    }

    public function testAcceptsPublishedStatusAndBlankDescription(): void
    {
        $plan = $this->make(['year' => 2027, 'title' => 'Plan', 'status' => 'published', 'description' => '']);

        self::assertSame('published', $plan->status);
        self::assertNull($plan->description);
        self::assertSame('Plan', $plan->toArray()['title']);
    }
}
