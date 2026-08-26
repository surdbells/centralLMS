<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Gamification;

use Central\Api\Domain\Gamification\GamificationLevel;
use Central\Api\Domain\Gamification\LeaderboardPeriod;
use Central\Api\Domain\Gamification\LevelLadder;
use Central\Api\Domain\Gamification\LevelProgress;
use Central\Api\Domain\Gamification\PointReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where a points total puts somebody.
 *
 * `user_level_progress` stored the total and the level beside the ledger that
 * already implied both, and held one row for the whole system — so for every
 * user but one the summary was simply missing. These are the sums it was
 * meant to be caching.
 */
#[CoversClass(LevelLadder::class)]
#[CoversClass(LevelProgress::class)]
#[CoversClass(LeaderboardPeriod::class)]
#[CoversClass(PointReason::class)]
final class LevelLadderTest extends TestCase
{
    #[Test]
    public function a_total_lands_on_the_highest_rung_it_clears(): void
    {
        $progress = $this->ladder()->progressFor(150);

        self::assertSame('Learner', $progress->current?->name);
        self::assertSame('Expert', $progress->next?->name);
    }

    #[Test]
    public function landing_exactly_on_a_threshold_reaches_it(): void
    {
        // Off by one here means somebody who has earned exactly 100 points is
        // told they need 0 more to reach a level they are standing on.
        $progress = $this->ladder()->progressFor(100);

        self::assertSame('Learner', $progress->current?->name);
        self::assertSame(400, $progress->pointsToNext());
    }

    #[Test]
    public function a_new_joiner_stands_on_the_bottom_rung(): void
    {
        $progress = $this->ladder()->progressFor(0);

        self::assertSame('Beginner', $progress->current?->name);
        self::assertFalse($progress->isAtTop());
    }

    #[Test]
    public function a_ladder_that_starts_above_zero_still_places_a_new_joiner(): void
    {
        // Otherwise somebody with no points has no level at all, which reads
        // as an error rather than as a beginning.
        $ladder = new LevelLadder([
            $this->level(1, 'Bronze', 50),
            $this->level(2, 'Silver', 200),
        ]);

        $progress = $ladder->progressFor(0);

        self::assertSame('Bronze', $progress->current?->name);
        self::assertSame('Silver', $progress->next?->name);
    }

    #[Test]
    public function the_top_rung_has_nothing_after_it(): void
    {
        $progress = $this->ladder()->progressFor(9999);

        self::assertSame('Expert', $progress->current?->name);
        self::assertNull($progress->next);
        self::assertNull($progress->pointsToNext());
        self::assertTrue($progress->isAtTop());
    }

    #[Test]
    public function progress_is_measured_between_the_two_thresholds(): void
    {
        // Somebody on 150 of a 100–500 band is an eighth of the way through
        // it, not 30% of the way to a level they already hold.
        self::assertSame(12.5, $this->ladder()->progressFor(150)->percentToNext());
        self::assertSame(0.0, $this->ladder()->progressFor(100)->percentToNext());
        self::assertSame(50.0, $this->ladder()->progressFor(300)->percentToNext());
    }

    #[Test]
    public function the_bar_reads_as_finished_at_the_top(): void
    {
        // Not zero, which would read as never started.
        self::assertSame(100.0, $this->ladder()->progressFor(9999)->percentToNext());
    }

    #[Test]
    public function an_empty_ladder_answers_without_a_level(): void
    {
        // A tenant that has not configured one still has a points total, and
        // the account page has to render.
        $progress = (new LevelLadder([]))->progressFor(500);

        self::assertTrue((new LevelLadder([]))->isEmpty());
        self::assertNull($progress->current);
        self::assertSame(500, $progress->points);
    }

    #[Test]
    public function rungs_are_sorted_however_they_arrive(): void
    {
        // `progressFor` walks in order and stops at the first rung out of
        // reach, so an unsorted ladder silently reports the wrong level.
        $ladder = new LevelLadder([
            $this->level(3, 'Expert', 500),
            $this->level(1, 'Beginner', 0),
            $this->level(2, 'Learner', 100),
        ]);

        self::assertSame('Learner', $ladder->progressFor(150)->current?->name);
        self::assertSame(0, $ladder->levels[0]->requiredPoints);
    }

    // --- periods ----------------------------------------------------------

    #[Test]
    public function a_period_starts_on_a_calendar_boundary(): void
    {
        // Not a rolling window: "this week" means since Monday, and a rolling
        // seven days would move the standings under somebody as they look.
        $wednesday = new \DateTimeImmutable('2026-03-04 15:00:00');

        self::assertNull(LeaderboardPeriod::AllTime->since($wednesday));
        self::assertSame('2026-03-04 00:00', LeaderboardPeriod::Daily->since($wednesday)?->format('Y-m-d H:i'));
        self::assertSame('2026-03-02 00:00', LeaderboardPeriod::Weekly->since($wednesday)?->format('Y-m-d H:i'));
        self::assertSame('2026-03-01 00:00', LeaderboardPeriod::Monthly->since($wednesday)?->format('Y-m-d H:i'));
    }

    #[Test]
    public function a_week_starting_on_monday_includes_monday(): void
    {
        $monday = new \DateTimeImmutable('2026-03-02 09:00:00');

        self::assertSame('2026-03-02 00:00', LeaderboardPeriod::Weekly->since($monday)?->format('Y-m-d H:i'));
    }

    // --- reasons ----------------------------------------------------------

    #[Test]
    public function the_legacy_spellings_collapse_onto_five_things(): void
    {
        // 122 rows held eight spellings: `course` and `course_completion` and
        // `scorm_completion`, `assessment` and `assessment_pass` and
        // `quiz_passed`.
        self::assertSame(PointReason::CourseCompleted, PointReason::fromLegacy('course'));
        self::assertSame(PointReason::CourseCompleted, PointReason::fromLegacy('course_completion'));
        self::assertSame(PointReason::CourseCompleted, PointReason::fromLegacy('scorm_completion'));
        self::assertSame(PointReason::AssessmentPassed, PointReason::fromLegacy('assessment'));
        self::assertSame(PointReason::AssessmentPassed, PointReason::fromLegacy('assessment_pass'));
        self::assertSame(PointReason::AssessmentPassed, PointReason::fromLegacy('quiz_passed'));
        self::assertSame(PointReason::LessonCompleted, PointReason::fromLegacy('lesson_completion'));
    }

    #[Test]
    public function an_unrecognised_spelling_is_not_guessed_at(): void
    {
        self::assertNull(PointReason::fromLegacy('something_else'));
    }

    #[Test]
    public function only_a_manual_award_has_no_subject(): void
    {
        // The schema ties `subject_type` to the reason, so they have to agree.
        foreach (PointReason::cases() as $reason) {
            if ($reason === PointReason::Manual || $reason === PointReason::Streak) {
                self::assertNull($reason->subjectType(), $reason->value);

                continue;
            }

            self::assertNotNull($reason->subjectType(), $reason->value);
        }
    }

    private function ladder(): LevelLadder
    {
        return new LevelLadder([
            $this->level(1, 'Beginner', 0),
            $this->level(2, 'Learner', 100),
            $this->level(3, 'Expert', 500),
        ]);
    }

    private function level(int $number, string $name, int $required): GamificationLevel
    {
        return new GamificationLevel(
            id: 'level-' . $number,
            tenantId: 'tenant-1',
            number: $number,
            name: $name,
            requiredPoints: $required,
        );
    }
}
