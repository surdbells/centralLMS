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
    public function a_null_position_is_treated_as_absent(): void
    {
        // A SCORM heartbeat sends no position; serialised as null it must not
        // rewind the stored one to zero.
        self::assertNull(LessonProgress::reportFromInput(['completed' => true, 'positionSeconds' => null])['position']);
    }

    // --- opened is started -------------------------------------------------

    #[Test]
    public function time_on_a_lesson_at_zero_percent_is_in_progress(): void
    {
        // The video heartbeat: time, but no percentage. Calling that "not
        // started" left opened courses on the nudge list.
        $report = LessonProgress::reportFromInput(['progressPercentage' => 0, 'secondsSpent' => 30]);

        self::assertSame(0.0, $report['percentage']);
        self::assertSame(ProgressStatus::InProgress, $report['status']);
    }

    #[Test]
    public function a_playback_position_at_zero_percent_is_in_progress(): void
    {
        $report = LessonProgress::reportFromInput(['progressPercentage' => 0, 'positionSeconds' => 42]);

        self::assertSame(ProgressStatus::InProgress, $report['status']);
    }

    #[Test]
    public function a_bare_zero_report_is_still_not_started(): void
    {
        // Nothing in it says the learner was there: no time, no position at
        // or beyond the start, no SCORM state.
        $report = LessonProgress::reportFromInput(['progressPercentage' => 0, 'positionSeconds' => 0]);

        self::assertSame(ProgressStatus::NotStarted, $report['status']);
    }

    #[Test]
    public function completion_still_wins_over_the_activity_rule(): void
    {
        $report = LessonProgress::reportFromInput(['completed' => true, 'secondsSpent' => 30]);

        self::assertSame(ProgressStatus::Completed, $report['status']);
    }

    // --- SCORM state -------------------------------------------------------

    #[Test]
    public function a_report_without_scorm_state_has_none(): void
    {
        self::assertNull(LessonProgress::reportFromInput(['completed' => true])['scorm']);
        self::assertNull(LessonProgress::reportFromInput(['completed' => true, 'scorm' => null])['scorm']);
    }

    #[Test]
    public function scorm_state_keeps_only_the_whitelisted_keys(): void
    {
        $report = LessonProgress::reportFromInput([
            'progressPercentage' => 50,
            'scorm'              => [
                'location'     => 'slide_5',
                'suspendData'  => '{"answers":[1,2]}',
                'lessonStatus' => 'incomplete',
                'exit'         => 'suspend',
                'totalTime'    => 'PT1M30S',
                // Free text from the CMI map is not ours to keep.
                'interactions' => 'whatever the learner typed',
            ],
        ]);

        self::assertSame([
            'location'     => 'slide_5',
            'suspendData'  => '{"answers":[1,2]}',
            'lessonStatus' => 'incomplete',
            'exit'         => 'suspend',
            'totalTime'    => 'PT1M30S',
        ], $report['scorm']);
    }

    #[Test]
    public function scorm_state_returns_only_the_keys_that_were_sent(): void
    {
        // So the repository merges them over the stored state instead of
        // blanking the suspend data a previous commit saved.
        $report = LessonProgress::reportFromInput([
            'progressPercentage' => 50,
            'scorm'              => ['lessonStatus' => 'completed', 'location' => null],
        ]);

        self::assertSame(['lessonStatus' => 'completed'], $report['scorm']);
    }

    #[Test]
    public function a_numeric_location_is_kept_as_text(): void
    {
        $report = LessonProgress::reportFromInput(['progressPercentage' => 0, 'scorm' => ['location' => 12]]);

        self::assertSame(['location' => '12'], $report['scorm']);
    }

    #[Test]
    public function scorm_state_alone_is_an_in_progress_report_at_zero(): void
    {
        // The wrapper's pagehide flush may carry nothing but the state. Zero
        // is safe because the stored percentage never goes backwards.
        $report = LessonProgress::reportFromInput(['scorm' => ['suspendData' => 'abc']]);

        self::assertSame(0.0, $report['percentage']);
        self::assertSame(ProgressStatus::InProgress, $report['status']);
        self::assertSame(['suspendData' => 'abc'], $report['scorm']);
    }

    #[Test]
    public function an_empty_scorm_object_is_no_state_and_no_report(): void
    {
        $this->expectException(ValidationFailed::class);

        LessonProgress::reportFromInput(['scorm' => []]);
    }

    #[Test]
    public function scorm_state_that_is_not_an_object_is_rejected(): void
    {
        try {
            LessonProgress::reportFromInput(['progressPercentage' => 10, 'scorm' => 'slide_5']);
            self::fail('Expected ValidationFailed.');
        } catch (ValidationFailed $e) {
            self::assertArrayHasKey('scorm', $e->details()['violations']);
        }
    }

    #[Test]
    public function a_nested_value_in_scorm_state_is_rejected(): void
    {
        try {
            LessonProgress::reportFromInput(['progressPercentage' => 10, 'scorm' => ['suspendData' => ['a' => 1]]]);
            self::fail('Expected ValidationFailed.');
        } catch (ValidationFailed $e) {
            self::assertArrayHasKey('scorm.suspendData', $e->details()['violations']);
        }
    }

    #[Test]
    public function every_scorm_limit_is_enforced_and_its_boundary_accepted(): void
    {
        foreach (LessonProgress::SCORM_LIMITS as $key => $limit) {
            $atLimit = LessonProgress::reportFromInput([
                'progressPercentage' => 10,
                'scorm'              => [$key => str_repeat('a', $limit)],
            ]);
            self::assertSame($limit, mb_strlen($atLimit['scorm'][$key] ?? ''), $key);

            try {
                LessonProgress::reportFromInput([
                    'progressPercentage' => 10,
                    'scorm'              => [$key => str_repeat('a', $limit + 1)],
                ]);
                self::fail(sprintf('%s over %d characters should be rejected.', $key, $limit));
            } catch (ValidationFailed $e) {
                self::assertArrayHasKey('scorm.' . $key, $e->details()['violations']);
            }
        }
    }

    #[Test]
    public function the_scorm_limits_are_the_agreed_ones(): void
    {
        self::assertSame(1000, LessonProgress::SCORM_LIMITS['location']);
        self::assertSame(64000, LessonProgress::SCORM_LIMITS['suspendData']);
        self::assertSame(64, LessonProgress::SCORM_LIMITS['lessonStatus']);
        self::assertSame(64, LessonProgress::SCORM_LIMITS['exit']);
        self::assertSame(64, LessonProgress::SCORM_LIMITS['totalTime']);
    }

    #[Test]
    public function scorm_limits_count_characters_not_bytes(): void
    {
        // Multi-byte text at the limit is within it.
        $report = LessonProgress::reportFromInput([
            'progressPercentage' => 10,
            'scorm'              => ['location' => str_repeat('é', 1000)],
        ]);

        self::assertSame(1000, mb_strlen($report['scorm']['location'] ?? ''));
    }

    #[Test]
    public function nul_bytes_are_stripped_because_jsonb_cannot_store_them(): void
    {
        $report = LessonProgress::reportFromInput([
            'progressPercentage' => 10,
            'scorm'              => ['suspendData' => "a\0b"],
        ]);

        self::assertSame(['suspendData' => 'ab'], $report['scorm']);
    }

    #[Test]
    public function invalid_utf8_in_scorm_state_is_rejected(): void
    {
        try {
            LessonProgress::reportFromInput(['progressPercentage' => 10, 'scorm' => ['suspendData' => "\xC3\x28"]]);
            self::fail('Expected ValidationFailed.');
        } catch (ValidationFailed $e) {
            self::assertArrayHasKey('scorm.suspendData', $e->details()['violations']);
        }
    }

    #[Test]
    public function to_array_carries_the_scorm_state_or_null(): void
    {
        $state = [
            'location'     => 'slide_5',
            'suspendData'  => 'abc',
            'lessonStatus' => 'incomplete',
            'exit'         => 'suspend',
            'totalTime'    => 'PT10S',
        ];

        $with = new LessonProgress('p1', 't1', 'e1', 'u1', 'l1', scorm: $state);
        $without = new LessonProgress('p2', 't1', 'e1', 'u1', 'l2');

        self::assertSame($state, $with->toArray()['scorm']);
        self::assertArrayHasKey('scorm', $without->toArray());
        self::assertNull($without->toArray()['scorm']);
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
