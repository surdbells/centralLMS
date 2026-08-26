<?php

declare(strict_types=1);

namespace Central\Api\Application\Dashboard;

use Central\Api\Domain\Certificate\Certificate;
use Central\Api\Domain\Certificate\CertificateRepository;
use Central\Api\Domain\Dashboard\DashboardRepository;
use Central\Api\Domain\Dashboard\LearningStreak;
use Central\Api\Domain\Enrollment\Enrollment;
use Central\Api\Domain\Enrollment\EnrollmentRepository;
use Central\Api\Domain\Gamification\Badge;
use Central\Api\Domain\Gamification\GamificationRepository;
use Central\Api\Domain\Gamification\LeaderboardPeriod;
use Central\Api\Support\Clock;

/**
 * A learner's own dashboard.
 *
 * Not built from reports, on purpose. A learner holds no report permission,
 * and routing their own progress through the reporting layer would mean either
 * granting them one or carving an exception into the thing that decides who may
 * see whom. Everything here is about one person: the caller.
 *
 * Most of it already exists. Points, badges and rank come from the gamification
 * repository, certificates from the certificate repository, courses from the
 * enrolment repository. `LearnerDashboard.php` re-queried all of it — 1,035
 * lines, `getMyCertificates()` and `getAchievements()` among them, each with
 * its own idea of what counted.
 */
final readonly class LearnerDashboardAssembler
{
    /** How far back the streak looks. Longer than any streak worth drawing. */
    private const STREAK_WINDOW_DAYS = 400;

    private const CHART_DAYS = 14;

    private const FEED_LENGTH = 15;

    public function __construct(
        private DashboardRepository $dashboards,
        private EnrollmentRepository $enrollments,
        private CertificateRepository $certificates,
        private GamificationRepository $gamification,
        private Clock $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function assemble(string $tenantId, string $userId): array
    {
        $totals = $this->dashboards->learnerTotals($tenantId, $userId);
        $points = $this->gamification->totalPoints($tenantId, $userId);

        return [
            'overview'    => $this->overview($totals, $points, $tenantId, $userId),
            'streak'      => LearningStreak::from(
                $this->dashboards->activeDays($tenantId, $userId, self::STREAK_WINDOW_DAYS),
                $this->clock->now(),
            )->toArray(),
            'courses'     => $this->courses($tenantId, $userId),
            'activity'    => $this->dashboards->dailyActivity($tenantId, $userId, self::CHART_DAYS),
            'achievements' => $this->achievements($tenantId, $userId, $points),
            'certificates' => array_map(
                static fn (Certificate $c): array => $c->toArray(),
                $this->certificates->forLearner($tenantId, $userId),
            ),
            'deadlines'    => $this->dashboards->learnerDeadlines($tenantId, $userId, self::FEED_LENGTH),
            'recommended'  => $this->dashboards->recommendedCourses($tenantId, $userId, 6),
            'recentActivity' => $this->dashboards->learnerActivity($tenantId, $userId, self::FEED_LENGTH),
        ];
    }

    /**
     * @param  array<string, mixed> $totals
     * @return array<string, mixed>
     */
    private function overview(array $totals, int $points, string $tenantId, string $userId): array
    {
        $enrolled  = (int) $totals['enrolled'];
        $completed = (int) $totals['completed'];

        return [
            ...$totals,
            'points'       => $points,
            'certificates' => count($this->certificates->forLearner($tenantId, $userId)),
            'badges'       => count($this->gamification->badgesFor($tenantId, $userId)),
            // Courses finished out of courses held. The legacy called this
            // `overall_progress` and computed the same thing, which is worth
            // keeping: an average of progress percentages reads as almost
            // finished when somebody is 90% through one of ten courses.
            'overallProgress' => $enrolled === 0 ? 0.0 : round(100 * $completed / $enrolled, 1),
        ];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function courses(string $tenantId, string $userId): array
    {
        $result = $this->enrollments->forUser($tenantId, $userId, 1, 100);

        $sorted = ['inProgress' => [], 'notStarted' => [], 'completed' => []];

        foreach ($result['items'] as $enrollment) {
            $bucket = match ($enrollment->status->value) {
                'completed'   => 'completed',
                'in_progress' => 'inProgress',
                'not_started' => 'notStarted',
                // A dropped enrolment is not on somebody's dashboard.
                default       => null,
            };

            if ($bucket !== null) {
                $sorted[$bucket][] = $enrollment->toArray();
            }
        }

        return $sorted;
    }

    /**
     * @return array<string, mixed>
     */
    private function achievements(string $tenantId, string $userId, int $points): array
    {
        $ladder   = $this->gamification->ladder($tenantId);
        $progress = $ladder->progressFor($points);
        $rank     = $this->gamification->rankOf(
            $tenantId,
            $userId,
            LeaderboardPeriod::AllTime,
            $this->clock->now(),
        );

        return [
            'points'  => $points,
            'level'   => $progress->current?->toArray(),
            'next'    => $progress->next?->toArray(),
            'percentToNext' => $progress->percentToNext(),
            'pointsToNext'  => $progress->pointsToNext(),
            'badges'  => array_map(
                static fn (Badge $b): array => $b->toArray(),
                $this->gamification->badgesFor($tenantId, $userId),
            ),
            'rank'    => $rank?->toArray(),
        ];
    }
}
