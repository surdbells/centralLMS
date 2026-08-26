<?php

declare(strict_types=1);

namespace Central\Api\Domain\Ilt;

/**
 * Works out whether a learner attended enough of a programme.
 *
 * Pure, like {@see \Central\Api\Domain\Assessment\Grader} and
 * {@see \Central\Api\Domain\Assessment\PaperBuilder}: given the sessions and
 * what was recorded against them, it returns the figure. Attendance decides
 * whether a certificate is issued, so it is worth being able to test the rule
 * without a database in the way.
 */
final class AttendanceCalculator
{
    /**
     * @param list<array{sessionId: string, isMandatory: bool, status: SessionStatus}> $sessions
     * @param array<string, AttendanceStatus>                                         $marks     session id => what was recorded
     */
    public function calculate(
        array $sessions,
        array $marks,
        float $requiredPercentage,
        bool $mandatoryOnly = false,
    ): AttendanceSummary {
        $counted = 0;
        $attended = 0;
        $excused = 0;
        $missing = [];

        foreach ($sessions as $session) {
            // A cancelled session is not a session anyone missed.
            if (!$session['status']->countsTowardsAttendance()) {
                continue;
            }

            // A programme may be assessed on its mandatory sessions alone, so
            // an optional extra evening does not drag someone below the bar.
            if ($mandatoryOnly && !$session['isMandatory']) {
                continue;
            }

            $mark = $marks[$session['sessionId']] ?? AttendanceStatus::Absent;

            if ($mark->countsAsAttended()) {
                $counted++;
                $attended++;

                continue;
            }

            if ($mark === AttendanceStatus::Excused) {
                // Out of the denominator rather than into the numerator: an
                // excused absence should neither reward nor punish.
                $excused++;

                continue;
            }

            $counted++;
            $missing[] = $session['sessionId'];
        }

        // Every session excused, or none to attend. Treated as met rather than
        // as zero: dividing by nothing and calling it 0% would fail a learner
        // for a programme that never ran.
        $percentage = $counted === 0 ? 100.0 : round($attended / $counted * 100, 2);

        return new AttendanceSummary(
            percentage: $percentage,
            sessionsAttended: $attended,
            sessionsCounted: $counted,
            sessionsExcused: $excused,
            missedSessionIds: $missing,
            required: $requiredPercentage,
        );
    }
}
