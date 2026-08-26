<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Ilt;

use Central\Api\Domain\Ilt\AttendanceCalculator;
use Central\Api\Domain\Ilt\AttendanceStatus;
use Central\Api\Domain\Ilt\SessionStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AttendanceCalculator::class)]
#[CoversClass(\Central\Api\Domain\Ilt\AttendanceSummary::class)]
#[CoversClass(AttendanceStatus::class)]
final class AttendanceCalculatorTest extends TestCase
{
    private AttendanceCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new AttendanceCalculator();
    }

    /**
     * @param list<array{string, bool, SessionStatus}> $sessions
     *
     * @return list<array{sessionId: string, isMandatory: bool, status: SessionStatus}>
     */
    private static function sessions(array $sessions): array
    {
        return array_map(
            static fn (array $s): array => [
                'sessionId'   => $s[0],
                'isMandatory' => $s[1],
                'status'      => $s[2],
            ],
            $sessions,
        );
    }

    #[Test]
    public function attending_everything_is_one_hundred_percent(): void
    {
        $summary = $this->calculator->calculate(
            self::sessions([
                ['s1', true, SessionStatus::Completed],
                ['s2', true, SessionStatus::Completed],
            ]),
            ['s1' => AttendanceStatus::Present, 's2' => AttendanceStatus::Present],
            80.0,
        );

        self::assertSame(100.0, $summary->percentage);
        self::assertTrue($summary->meetsRequirement());
    }

    #[Test]
    public function an_unrecorded_session_counts_as_absent(): void
    {
        // Silence is not attendance. Treating a missing row as present is how
        // a certificate gets issued to someone who never appeared.
        $summary = $this->calculator->calculate(
            self::sessions([
                ['s1', true, SessionStatus::Completed],
                ['s2', true, SessionStatus::Completed],
            ]),
            ['s1' => AttendanceStatus::Present],
            80.0,
        );

        self::assertSame(50.0, $summary->percentage);
        self::assertFalse($summary->meetsRequirement());
        self::assertSame(['s2'], $summary->missedSessionIds);
    }

    #[Test]
    public function arriving_late_still_counts_as_attending(): void
    {
        // Fifteen minutes into a two-hour session is attendance. A rule that
        // says otherwise turns a traffic jam into a failed certification.
        $summary = $this->calculator->calculate(
            self::sessions([['s1', true, SessionStatus::Completed]]),
            ['s1' => AttendanceStatus::Late],
            100.0,
        );

        self::assertSame(100.0, $summary->percentage);
        self::assertTrue($summary->meetsRequirement());
    }

    #[Test]
    public function an_excused_absence_neither_rewards_nor_punishes(): void
    {
        // Out of the denominator rather than into the numerator: two of three
        // with one excused is 100%, not 66.7% and not 33%.
        $summary = $this->calculator->calculate(
            self::sessions([
                ['s1', true, SessionStatus::Completed],
                ['s2', true, SessionStatus::Completed],
                ['s3', true, SessionStatus::Completed],
            ]),
            [
                's1' => AttendanceStatus::Present,
                's2' => AttendanceStatus::Present,
                's3' => AttendanceStatus::Excused,
            ],
            100.0,
        );

        self::assertSame(100.0, $summary->percentage);
        self::assertSame(2, $summary->sessionsCounted);
        self::assertSame(1, $summary->sessionsExcused);
        self::assertTrue($summary->meetsRequirement());
    }

    #[Test]
    public function a_cancelled_session_is_not_one_anybody_missed(): void
    {
        $summary = $this->calculator->calculate(
            self::sessions([
                ['s1', true, SessionStatus::Completed],
                ['s2', true, SessionStatus::Cancelled],
            ]),
            ['s1' => AttendanceStatus::Present],
            100.0,
        );

        self::assertSame(100.0, $summary->percentage);
        self::assertSame(1, $summary->sessionsCounted);
    }

    #[Test]
    public function optional_sessions_can_be_excluded_from_the_reckoning(): void
    {
        $sessions = self::sessions([
            ['s1', true, SessionStatus::Completed],
            ['s2', false, SessionStatus::Completed],
        ]);
        $marks = ['s1' => AttendanceStatus::Present];

        self::assertSame(50.0, $this->calculator->calculate($sessions, $marks, 80.0)->percentage);
        self::assertSame(
            100.0,
            $this->calculator->calculate($sessions, $marks, 80.0, mandatoryOnly: true)->percentage,
        );
    }

    #[Test]
    public function a_programme_with_nothing_to_attend_is_met_rather_than_failed(): void
    {
        // Dividing by nothing and calling it 0% would fail a learner for a
        // programme that never ran.
        $summary = $this->calculator->calculate([], [], 100.0);

        self::assertSame(100.0, $summary->percentage);
        self::assertTrue($summary->meetsRequirement());
    }

    #[Test]
    public function a_programme_where_everything_was_excused_is_also_met(): void
    {
        $summary = $this->calculator->calculate(
            self::sessions([['s1', true, SessionStatus::Completed]]),
            ['s1' => AttendanceStatus::Excused],
            100.0,
        );

        self::assertSame(100.0, $summary->percentage);
        self::assertSame(0, $summary->sessionsCounted);
    }

    #[Test]
    public function a_rounded_figure_still_meets_a_matching_requirement(): void
    {
        // Two of three is 66.67 after rounding. A programme requiring 66.67%
        // must not fail a learner who met it exactly.
        $summary = $this->calculator->calculate(
            self::sessions([
                ['s1', true, SessionStatus::Completed],
                ['s2', true, SessionStatus::Completed],
                ['s3', true, SessionStatus::Completed],
            ]),
            ['s1' => AttendanceStatus::Present, 's2' => AttendanceStatus::Present],
            66.67,
        );

        self::assertSame(66.67, $summary->percentage);
        self::assertTrue($summary->meetsRequirement());
    }

    #[Test]
    public function falling_just_short_does_not_meet_it(): void
    {
        $summary = $this->calculator->calculate(
            self::sessions([
                ['s1', true, SessionStatus::Completed],
                ['s2', true, SessionStatus::Completed],
                ['s3', true, SessionStatus::Completed],
            ]),
            ['s1' => AttendanceStatus::Present, 's2' => AttendanceStatus::Present],
            80.0,
        );

        self::assertFalse($summary->meetsRequirement());
    }

    #[Test]
    public function it_says_how_many_more_sessions_are_needed(): void
    {
        $summary = $this->calculator->calculate(
            self::sessions([['s1', true, SessionStatus::Completed]]),
            ['s1' => AttendanceStatus::Absent],
            50.0,
        );

        // One attended out of two total clears 50%.
        self::assertSame(1, $summary->sessionsStillNeeded(1));
    }

    #[Test]
    public function it_says_nothing_when_the_requirement_is_already_met(): void
    {
        $summary = $this->calculator->calculate(
            self::sessions([['s1', true, SessionStatus::Completed]]),
            ['s1' => AttendanceStatus::Present],
            100.0,
        );

        self::assertNull($summary->sessionsStillNeeded(3));
    }

    #[Test]
    public function it_says_nothing_when_the_requirement_can_no_longer_be_met(): void
    {
        // Distinct from "already met", and the caller needs to tell them apart
        // to say anything useful to the learner.
        $summary = $this->calculator->calculate(
            self::sessions([
                ['s1', true, SessionStatus::Completed],
                ['s2', true, SessionStatus::Completed],
            ]),
            ['s1' => AttendanceStatus::Absent, 's2' => AttendanceStatus::Absent],
            100.0,
        );

        self::assertNull($summary->sessionsStillNeeded(1));
    }

    #[Test]
    public function every_status_is_counted_exactly_one_way(): void
    {
        foreach (AttendanceStatus::cases() as $status) {
            self::assertFalse(
                $status->countsAsAttended() && $status->countsAgainst(),
                $status->value . ' cannot both count for and against.',
            );
        }

        self::assertTrue(AttendanceStatus::Present->countsAsAttended());
        self::assertTrue(AttendanceStatus::Late->countsAsAttended());
        self::assertTrue(AttendanceStatus::Absent->countsAgainst());
        self::assertFalse(AttendanceStatus::Excused->countsAgainst());
        self::assertFalse(AttendanceStatus::Excused->countsAsAttended());
    }
}
