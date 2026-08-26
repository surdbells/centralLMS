<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Enrollment;

use Central\Api\Domain\Enrollment\LessonProgress;
use Central\Api\Domain\Enrollment\ProgressStatus;
use Central\Api\Domain\Exception\ValidationFailed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LessonProgress::class)]
#[CoversClass(ProgressStatus::class)]
final class ProgressReportTest extends TestCase
{
    #[Test]
    public function a_percentage_report_derives_its_own_status(): void
    {
        // Status is never taken from the request: the legacy rows where
        // percentage and status disagree are what this prevents.
        $report = LessonProgress::reportFromInput(['progressPercentage' => 40]);

        self::assertSame(40.0, $report['percentage']);
        self::assertSame(ProgressStatus::InProgress, $report['status']);
    }

    #[Test]
    public function a_status_sent_by_the_client_is_ignored(): void
    {
        $report = LessonProgress::reportFromInput([
            'progressPercentage' => 10,
            'status'             => 'completed',
        ]);

        self::assertSame(ProgressStatus::InProgress, $report['status']);
    }

    #[Test]
    public function completed_true_is_shorthand_for_one_hundred(): void
    {
        $report = LessonProgress::reportFromInput(['completed' => true]);

        self::assertSame(100.0, $report['percentage']);
        self::assertSame(ProgressStatus::Completed, $report['status']);
    }

    #[Test]
    public function completed_true_wins_over_a_lower_percentage(): void
    {
        // A player that sends both means "the video ended".
        $report = LessonProgress::reportFromInput([
            'progressPercentage' => 92,
            'completed'          => true,
        ]);

        self::assertSame(100.0, $report['percentage']);
    }

    #[Test]
    public function completed_false_is_not_a_progress_report_on_its_own(): void
    {
        $this->expectException(ValidationFailed::class);

        LessonProgress::reportFromInput(['completed' => false]);
    }

    #[Test]
    public function an_empty_report_is_rejected(): void
    {
        $this->expectException(ValidationFailed::class);

        LessonProgress::reportFromInput([]);
    }

    #[Test]
    public function a_percentage_outside_the_range_is_rejected(): void
    {
        foreach ([-1, 101] as $value) {
            try {
                LessonProgress::reportFromInput(['progressPercentage' => $value]);
                self::fail(sprintf('%d should be rejected.', $value));
            } catch (ValidationFailed $e) {
                self::assertArrayHasKey('progressPercentage', $e->details()['violations']);
            }
        }
    }

    #[Test]
    public function the_boundaries_are_accepted(): void
    {
        self::assertSame(0.0, LessonProgress::reportFromInput(['progressPercentage' => 0])['percentage']);
        self::assertSame(100.0, LessonProgress::reportFromInput(['progressPercentage' => 100])['percentage']);
    }

    #[Test]
    public function zero_percent_is_not_started_and_anything_above_it_is_in_progress(): void
    {
        // A learner who watched ten seconds has started; reporting otherwise
        // makes "not started" useless as a nudge list.
        self::assertSame(ProgressStatus::NotStarted, ProgressStatus::fromPercentage(0.0));
        self::assertSame(ProgressStatus::InProgress, ProgressStatus::fromPercentage(0.01));
        self::assertSame(ProgressStatus::Completed, ProgressStatus::fromPercentage(100.0));
        // Over 100 cannot arrive through validation, but must not read as
        // "in progress" if it ever does.
        self::assertSame(ProgressStatus::Completed, ProgressStatus::fromPercentage(150.0));
    }

    #[Test]
    public function negative_time_is_rejected(): void
    {
        $this->expectException(ValidationFailed::class);

        LessonProgress::reportFromInput(['completed' => true, 'secondsSpent' => -1]);
    }

    #[Test]
    public function an_implausible_amount_of_time_in_one_report_is_rejected(): void
    {
        // Twelve hours in a single report is a broken clock or a forged
        // request, and it would poison every time-spent figure downstream.
        $this->expectException(ValidationFailed::class);

        LessonProgress::reportFromInput(['completed' => true, 'secondsSpent' => 43201]);
    }

    #[Test]
    public function time_just_inside_the_limit_is_accepted(): void
    {
        $report = LessonProgress::reportFromInput(['completed' => true, 'secondsSpent' => 43200]);

        self::assertSame(43200, $report['secondsToAdd']);
    }

    #[Test]
    public function a_score_outside_the_range_is_rejected(): void
    {
        $this->expectException(ValidationFailed::class);

        LessonProgress::reportFromInput(['completed' => true, 'score' => 101]);
    }

    #[Test]
    public function an_absent_score_stays_absent_rather_than_becoming_zero(): void
    {
        // A null score means "not assessed"; zero means "failed completely".
        self::assertNull(LessonProgress::reportFromInput(['completed' => true])['score']);
        self::assertNull(LessonProgress::reportFromInput(['completed' => true, 'score' => ''])['score']);
        self::assertSame(0.0, LessonProgress::reportFromInput(['completed' => true, 'score' => 0])['score']);
    }

    #[Test]
    public function an_absent_position_stays_null_so_it_is_not_reset_to_zero(): void
    {
        // The repository only writes the position when one was sent; a report
        // with no position must not rewind the video to the start.
        self::assertNull(LessonProgress::reportFromInput(['completed' => true])['position']);
        self::assertSame(0, LessonProgress::reportFromInput(['completed' => true, 'positionSeconds' => 0])['position']);
    }

    #[Test]
    public function a_negative_position_is_rejected(): void
    {
        $this->expectException(ValidationFailed::class);

        LessonProgress::reportFromInput(['completed' => true, 'positionSeconds' => -5]);
    }

    #[Test]
    public function every_violation_is_reported_at_once(): void
    {
        try {
            LessonProgress::reportFromInput([
                'progressPercentage' => 250,
                'score'              => -4,
                'secondsSpent'       => -1,
                'positionSeconds'    => -1,
            ]);
            self::fail('Expected ValidationFailed.');
        } catch (ValidationFailed $e) {
            self::assertSame(
                ['progressPercentage', 'score', 'secondsSpent', 'positionSeconds'],
                array_keys($e->details()['violations']),
            );
        }
    }
}
