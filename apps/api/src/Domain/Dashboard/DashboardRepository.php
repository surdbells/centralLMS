<?php

declare(strict_types=1);

namespace Central\Api\Domain\Dashboard;

use Central\Api\Domain\Report\ReportCriteria;

/**
 * The parts of a dashboard no report already answers.
 *
 * Deliberately small. `AdminDashboard.php` was 842 lines and `LearnerDashboard.php`
 * 1,035, and almost every figure in them has a twin in `class/reports/` — the
 * two were written separately and disagree in places. Here the admin dashboard
 * is assembled from the report definitions that already exist, and this
 * interface covers only what none of them produces: the mixes, the two feeds,
 * and the learner's own view, which cannot go through reports because a learner
 * has no report permission.
 *
 * The admin methods take a {@see ReportCriteria} for the same reason the report
 * repository does: the tenant arrives inside a scope built from the
 * authenticated caller, and there is nowhere to pass a different one.
 */
interface DashboardRepository
{
    // --- admin ------------------------------------------------------------

    /**
     * The overview figures the executive report does not carry, and the same
     * figures for the previous window so growth can be worked out.
     *
     * @return array{
     *     publishedCourses: int,
     *     inProgress: int,
     *     notStarted: int,
     *     averageScore: float|null,
     *     enrolmentsThisPeriod: int,
     *     enrolmentsPreviousPeriod: int,
     *     completionsThisPeriod: int,
     *     completionsPreviousPeriod: int
     * }
     */
    public function overviewSupplement(ReportCriteria $criteria, DashboardPeriod $period): array;

    /**
     * Courses by category and by level.
     *
     * @return array{byCategory: list<array<string, mixed>>, byLevel: list<array<string, mixed>>}
     */
    public function courseMix(ReportCriteria $criteria): array;

    /**
     * People by account status, and how many joined in the window.
     *
     * @return array{byStatus: list<array<string, mixed>>, joinedThisPeriod: int}
     */
    public function learnerMix(ReportCriteria $criteria, DashboardPeriod $period): array;

    /**
     * Enrolments falling due soon and not yet finished.
     *
     * Distinct from the overdue report, which is the ones already missed.
     *
     * @return list<array<string, mixed>>
     */
    public function upcomingDeadlines(ReportCriteria $criteria, int $withinDays, int $limit): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function recentCompletions(ReportCriteria $criteria, int $limit): array;

    /**
     * A merged feed: enrolments, completions and certificates, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function recentActivity(ReportCriteria $criteria, int $limit): array;

    // --- learner ----------------------------------------------------------

    /**
     * @return array{
     *     enrolled: int, completed: int, inProgress: int, notStarted: int,
     *     overdue: int, secondsSpent: int, averageScore: float|null
     * }
     */
    public function learnerTotals(string $tenantId, string $userId): array;

    /**
     * The days this person did something, most recent first.
     *
     * What `user_streaks` was a stale cache of. One query over the activity
     * that actually happened, and the streak is worked out from the dates —
     * see {@see LearningStreak}.
     *
     * **Resolution:** the last day each lesson was touched, plus the day each
     * attempt was submitted. There is one progress row per lesson, so somebody
     * who reads the same lesson every morning for a week counts once. Finer
     * would need an append-only activity log; `learning_sessions` was meant to
     * be one and holds three rows for the entire platform, which is why the
     * stored streaks it fed were fiction.
     *
     * @return list<string> `Y-m-d`
     */
    public function activeDays(string $tenantId, string $userId, int $days): array;

    /**
     * Time spent and lessons finished, per day, oldest first.
     *
     * @return list<array{date: string, seconds: int, lessons: int}>
     */
    public function dailyActivity(string $tenantId, string $userId, int $days): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function learnerDeadlines(string $tenantId, string $userId, int $limit): array;

    /**
     * Published courses this person is not enrolled on.
     *
     * @return list<array<string, mixed>>
     */
    public function recommendedCourses(string $tenantId, string $userId, int $limit): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function learnerActivity(string $tenantId, string $userId, int $limit): array;
}
