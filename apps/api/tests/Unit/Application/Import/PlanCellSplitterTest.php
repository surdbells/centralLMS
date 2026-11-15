<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Application\Import;

use Central\Api\Application\Import\PlanCellSplitter;
use PHPUnit\Framework\TestCase;

final class PlanCellSplitterTest extends TestCase
{
    public function testSplitsOnASlashWithWhitespaceOrNewline(): void
    {
        self::assertSame(['Course A', 'Course B', 'Course C'], PlanCellSplitter::split("Course A /\nCourse B / Course C\n\n"));
        self::assertSame(['Course A', 'Course B'], PlanCellSplitter::split("Course A/\nCourse B"));
    }

    public function testKeepsABareSlashInsideAWord(): void
    {
        self::assertSame(['Technical/Operations Skills'], PlanCellSplitter::split('Technical/Operations Skills'));
    }

    public function testKeepsASlashInsideParentheses(): void
    {
        self::assertSame(['Acme (August / December 2025)'], PlanCellSplitter::split('Acme (August / December 2025)'));
    }

    public function testJoinsAParenthesisedNoteOntoThePreviousItem(): void
    {
        self::assertSame(
            ['Joint Ventures (On-the-job exposure)', 'Finance'],
            PlanCellSplitter::split("Joint Ventures /\n\n(On-the-job exposure)\n/ Finance"),
        );
    }

    public function testASlashOnALineBreakSplitsEvenAfterAnUnclosedParenthesis(): void
    {
        self::assertSame(
            ['A (Starting X; note', 'B (April 30, 2026)'],
            PlanCellSplitter::split("A (Starting X; note /\nB (April 30, 2026)"),
        );
    }

    public function testSplitsLinesAtDepthZero(): void
    {
        self::assertSame(
            ['Course A (CBAP)', 'Seminar B'],
            PlanCellSplitter::splitLines("Course A (CBAP); \nSeminar B"),
        );
        self::assertSame(['Acme (May 4)'], PlanCellSplitter::splitLines("Acme (May\n4)"));
    }

    public function testCleansWhitespaceNbspAndDebris(): void
    {
        self::assertSame(['Safety Leadership', 'Risk'], PlanCellSplitter::split("  Safety\u{00A0}\u{00A0}Leadership ;\r\n/ - Risk ,"));
        self::assertSame([], PlanCellSplitter::split(" / \n "));
        self::assertSame([], PlanCellSplitter::split(null));
    }
}
