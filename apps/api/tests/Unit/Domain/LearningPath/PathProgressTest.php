<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\LearningPath;

use Central\Api\Domain\LearningPath\CompletionRule;
use Central\Api\Domain\LearningPath\ItemState;
use Central\Api\Domain\LearningPath\PathItem;
use Central\Api\Domain\LearningPath\PathItemKind;
use Central\Api\Domain\LearningPath\PathPacing;
use Central\Api\Domain\LearningPath\PathProgress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where somebody has got to on a path.
 *
 * The legacy stored every figure this computes. The single path in the database
 * claims `total_items = 2`, has three item rows with none active, and every one
 * of its thirty-three enrolments carries `items_total = 4` — three stored
 * answers to one question, and none of them right.
 */
#[CoversClass(PathProgress::class)]
#[CoversClass(PathItem::class)]
#[CoversClass(ItemState::class)]
#[CoversClass(PathPacing::class)]
#[CoversClass(PathItemKind::class)]
#[CoversClass(CompletionRule::class)]
final class PathProgressTest extends TestCase
{
    // --- sequential -------------------------------------------------------

    #[Test]
    public function a_sequential_path_opens_one_item_at_a_time(): void
    {
        $progress = $this->progress($this->threeItems(), [], PathPacing::Sequential);

        self::assertSame(['available', 'locked', 'locked'], $this->statuses($progress));
        self::assertSame('item-1', $progress->currentItemId);
    }

    #[Test]
    public function finishing_one_opens_the_next(): void
    {
        $progress = $this->progress($this->threeItems(), ['item-1'], PathPacing::Sequential);

        self::assertSame(['completed', 'available', 'locked'], $this->statuses($progress));
        self::assertSame('item-2', $progress->currentItemId);
    }

    #[Test]
    public function a_gap_leaves_the_rest_shut(): void
    {
        // Finishing the third without the first — possible when somebody took
        // the course elsewhere — opens nothing beyond the first unfinished
        // item, but the completion still counts.
        $progress = $this->progress($this->threeItems(), ['item-3'], PathPacing::Sequential);

        self::assertSame(['available', 'locked', 'completed'], $this->statuses($progress));
        self::assertSame('item-1', $progress->currentItemId);
        self::assertSame(1, $progress->completed);
    }

    #[Test]
    public function a_finished_path_has_nothing_current(): void
    {
        $progress = $this->progress($this->threeItems(), ['item-1', 'item-2', 'item-3'], PathPacing::Sequential);

        self::assertNull($progress->currentItemId);
        self::assertTrue($progress->isComplete);
        self::assertSame(100.0, $progress->percent);
    }

    // --- flexible ---------------------------------------------------------

    #[Test]
    public function a_flexible_path_opens_everything(): void
    {
        $progress = $this->progress($this->threeItems(), [], PathPacing::Flexible);

        self::assertSame(['available', 'available', 'available'], $this->statuses($progress));
    }

    #[Test]
    public function a_flexible_path_can_be_taken_backwards(): void
    {
        $progress = $this->progress($this->threeItems(), ['item-3'], PathPacing::Flexible);

        self::assertSame(['available', 'available', 'completed'], $this->statuses($progress));
        // The first unfinished open item, which is where a player should land.
        self::assertSame('item-1', $progress->currentItemId);
    }

    // --- milestone --------------------------------------------------------

    #[Test]
    public function a_milestone_section_gates_the_ones_after_it(): void
    {
        $progress = $this->progress($this->twoSections(), [], PathPacing::Milestone);

        // Both items of the first section are open; the second section is not.
        self::assertSame(['available', 'available', 'locked'], $this->statuses($progress));
    }

    #[Test]
    public function finishing_a_milestone_opens_the_next_section(): void
    {
        $progress = $this->progress($this->twoSections(), ['item-1', 'item-2'], PathPacing::Milestone);

        self::assertSame(['completed', 'completed', 'available'], $this->statuses($progress));
    }

    #[Test]
    public function half_a_milestone_opens_nothing(): void
    {
        $progress = $this->progress($this->twoSections(), ['item-1'], PathPacing::Milestone);

        self::assertSame(['completed', 'available', 'locked'], $this->statuses($progress));
    }

    #[Test]
    public function a_section_minimum_can_be_fewer_than_all_of_it(): void
    {
        // "Do any one of these three and move on" — the `min_items_to_complete`
        // the legacy declared and never consulted.
        $progress = PathProgress::of(
            $this->twoSections(),
            ['item-1' => '2026-03-01T00:00:00+00:00'],
            PathPacing::Milestone,
            CompletionRule::AllRequiredItems,
            100.0,
            ['section-1' => 1],
        );

        self::assertSame(['completed', 'available', 'available'], $this->statuses($progress));
    }

    #[Test]
    public function an_ordinary_section_is_a_heading_rather_than_a_gate(): void
    {
        $items = [
            $this->item('item-1', 1, sectionId: 'section-1', milestone: false),
            $this->item('item-2', 2, sectionId: 'section-2', milestone: false),
        ];

        $progress = $this->progress($items, [], PathPacing::Milestone);

        self::assertSame(['available', 'available'], $this->statuses($progress));
    }

    #[Test]
    public function an_item_outside_every_section_is_never_gated(): void
    {
        $items = [
            $this->item('item-1', 1, sectionId: 'section-1', milestone: true),
            $this->item('loose', 2),
        ];

        $progress = $this->progress($items, [], PathPacing::Milestone);

        self::assertSame(['available', 'available'], $this->statuses($progress));
    }

    // --- how far through --------------------------------------------------

    #[Test]
    public function progress_is_measured_over_the_required_items(): void
    {
        // The optional ones are optional: somebody who has done everything
        // they must have done is finished, whatever they skipped.
        $items = [
            $this->item('item-1', 1),
            $this->item('item-2', 2),
            $this->item('extra', 3, required: false),
        ];

        $progress = $this->progress($items, ['item-1', 'item-2'], PathPacing::Flexible);

        self::assertSame(100.0, $progress->percent);
        self::assertTrue($progress->isComplete);
        self::assertSame(2, $progress->completed);
        self::assertSame(3, $progress->total);
    }

    #[Test]
    public function an_optional_item_still_counts_as_done(): void
    {
        $items = [$this->item('item-1', 1), $this->item('extra', 2, required: false)];

        $progress = $this->progress($items, ['extra'], PathPacing::Flexible);

        self::assertSame(1, $progress->completed);
        self::assertSame(0, $progress->requiredCompleted);
        self::assertSame(0.0, $progress->percent);
    }

    #[Test]
    public function a_path_of_only_optional_items_is_measured_over_all_of_them(): void
    {
        $items = [
            $this->item('item-1', 1, required: false),
            $this->item('item-2', 2, required: false),
        ];

        self::assertSame(50.0, $this->progress($items, ['item-1'], PathPacing::Flexible)->percent);
    }

    #[Test]
    public function an_empty_path_is_never_complete(): void
    {
        // Thirty-three people were enrolled on a path whose items had all been
        // deactivated. Telling them they had finished it would be worse than
        // telling them nothing.
        $progress = $this->progress([], [], PathPacing::Sequential);

        self::assertFalse($progress->isComplete);
        self::assertSame(0.0, $progress->percent);
        self::assertNull($progress->currentItemId);
    }

    #[Test]
    public function a_path_of_only_optional_items_is_never_complete_by_the_all_rule(): void
    {
        // Nothing is required, so "every required item" is satisfied
        // vacuously — and a path nobody has to do anything on should not
        // announce itself finished the moment somebody joins.
        $items = [$this->item('item-1', 1, required: false)];

        self::assertFalse($this->progress($items, [], PathPacing::Flexible)->isComplete);
    }

    #[Test]
    public function a_percentage_rule_finishes_at_its_threshold(): void
    {
        $items = [$this->item('item-1', 1), $this->item('item-2', 2), $this->item('item-3', 3)];

        $twoOfThree = PathProgress::of(
            $items,
            $this->finished(['item-1', 'item-2']),
            PathPacing::Flexible,
            CompletionRule::Percentage,
            60.0,
        );

        self::assertSame(66.7, $twoOfThree->percent);
        self::assertTrue($twoOfThree->isComplete);

        $oneOfThree = PathProgress::of(
            $items,
            $this->finished(['item-1']),
            PathPacing::Flexible,
            CompletionRule::Percentage,
            60.0,
        );

        self::assertFalse($oneOfThree->isComplete);
    }

    // --- points -----------------------------------------------------------

    #[Test]
    public function points_are_earned_by_finishing_the_items_that_carry_them(): void
    {
        $items = [
            $this->item('item-1', 1, points: 10),
            $this->item('item-2', 2, points: 25),
            $this->item('item-3', 3, points: 5),
        ];

        $progress = $this->progress($items, ['item-1', 'item-3'], PathPacing::Flexible);

        self::assertSame(15, $progress->pointsEarned);
        self::assertSame(40, $progress->pointsAvailable);
    }

    // --- deadlines --------------------------------------------------------

    #[Test]
    public function a_deadline_can_follow_the_enrolment_rather_than_the_calendar(): void
    {
        // What makes one path work for cohorts starting in different months.
        $item     = $this->item('item-1', 1, dueDays: 14);
        $enrolled = new \DateTimeImmutable('2026-03-01');

        self::assertSame('2026-03-15', $item->dueFor($enrolled)?->format('Y-m-d'));
    }

    #[Test]
    public function a_fixed_date_wins_over_an_offset(): void
    {
        $item = new PathItem(
            id: 'item-1',
            tenantId: 'tenant-1',
            pathId: 'path-1',
            kind: PathItemKind::Course,
            courseId: 'course-1',
            dueDate: new \DateTimeImmutable('2026-06-30'),
            dueDaysFromEnrolment: 14,
        );

        self::assertSame(
            '2026-06-30',
            $item->dueFor(new \DateTimeImmutable('2026-03-01'))?->format('Y-m-d'),
        );
    }

    #[Test]
    public function an_item_with_no_deadline_has_none(): void
    {
        self::assertNull($this->item('item-1', 1)->dueFor(new \DateTimeImmutable('2026-03-01')));
    }

    // --- titles -----------------------------------------------------------

    #[Test]
    public function an_item_is_named_by_the_thing_it_points_at(): void
    {
        // So a course renamed in the catalogue reads correctly inside every
        // path that contains it, rather than keeping the name it had when it
        // was added.
        $item = new PathItem(
            id: 'item-1',
            tenantId: 'tenant-1',
            pathId: 'path-1',
            kind: PathItemKind::Course,
            courseId: 'course-1',
            title: 'The name it had in January',
            subjectTitle: 'Fire Safety 2026',
        );

        self::assertSame('Fire Safety 2026', $item->displayTitle());
    }

    #[Test]
    public function a_link_is_named_by_its_own_title(): void
    {
        $item = new PathItem(
            id: 'item-1',
            tenantId: 'tenant-1',
            pathId: 'path-1',
            kind: PathItemKind::Link,
            externalUrl: 'https://example.test/reading',
            title: 'Further reading',
        );

        self::assertSame('Further reading', $item->displayTitle());
        self::assertNull($item->subjectId());
    }

    #[Test]
    public function only_the_first_three_kinds_can_be_evidenced(): void
    {
        // A course enrolment, an assessment attempt and an attendance register
        // all say whether something happened. Opening a link is not something
        // this system can observe, so those are marked instead.
        self::assertTrue(PathItemKind::Course->isEvidenced());
        self::assertTrue(PathItemKind::Assessment->isEvidenced());
        self::assertTrue(PathItemKind::Session->isEvidenced());
        self::assertFalse(PathItemKind::Media->isEvidenced());
        self::assertFalse(PathItemKind::Link->isEvidenced());
    }

    #[Test]
    public function the_legacy_kinds_fold_onto_five(): void
    {
        self::assertSame(PathItemKind::Media, PathItemKind::fromLegacy('document'));
        self::assertSame(PathItemKind::Media, PathItemKind::fromLegacy('video'));
        self::assertSame(PathItemKind::Link, PathItemKind::fromLegacy('external_link'));
        self::assertSame(PathItemKind::Session, PathItemKind::fromLegacy('live_session'));
        // A milestone is a section, not a step somebody takes.
        self::assertNull(PathItemKind::fromLegacy('milestone'));
        self::assertNull(PathItemKind::fromLegacy('checkpoint'));
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @param list<PathItem> $items
     * @param list<string>   $done
     */
    private function progress(array $items, array $done, PathPacing $pacing): PathProgress
    {
        return PathProgress::of($items, $this->finished($done), $pacing, CompletionRule::AllRequiredItems);
    }

    /**
     * @param  list<string>         $ids
     * @return array<string, string>
     */
    private function finished(array $ids): array
    {
        $finished = [];

        foreach ($ids as $id) {
            $finished[$id] = '2026-03-01T00:00:00+00:00';
        }

        return $finished;
    }

    /**
     * @param  PathProgress $progress
     * @return list<string>
     */
    private function statuses(PathProgress $progress): array
    {
        return array_map(static fn (ItemState $s): string => $s->status(), $progress->items);
    }

    /**
     * @return list<PathItem>
     */
    private function threeItems(): array
    {
        return [$this->item('item-1', 1), $this->item('item-2', 2), $this->item('item-3', 3)];
    }

    /**
     * Two milestone sections, two items in the first and one in the second.
     *
     * @return list<PathItem>
     */
    private function twoSections(): array
    {
        return [
            $this->item('item-1', 1, sectionId: 'section-1', milestone: true),
            $this->item('item-2', 2, sectionId: 'section-1', milestone: true),
            $this->item('item-3', 3, sectionId: 'section-2', milestone: true),
        ];
    }

    private function item(
        string $id,
        int $order,
        bool $required = true,
        int $points = 0,
        ?string $sectionId = null,
        bool $milestone = false,
        ?int $dueDays = null,
    ): PathItem {
        return new PathItem(
            id: $id,
            tenantId: 'tenant-1',
            pathId: 'path-1',
            kind: PathItemKind::Course,
            sectionId: $sectionId,
            courseId: 'course-' . $order,
            sortOrder: $order,
            isRequired: $required,
            pointsValue: $points,
            dueDaysFromEnrolment: $dueDays,
            isMilestoneSection: $milestone,
        );
    }
}
