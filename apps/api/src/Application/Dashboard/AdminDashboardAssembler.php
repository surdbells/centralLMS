<?php

declare(strict_types=1);

namespace Central\Api\Application\Dashboard;

use Central\Api\Domain\Dashboard\DashboardPeriod;
use Central\Api\Domain\Dashboard\DashboardRepository;
use Central\Api\Domain\Report\ReportCatalogue;
use Central\Api\Domain\Report\ReportCriteria;
use Central\Api\Domain\Report\ReportKey;
use Central\Api\Domain\Report\ReportRepository;
use Central\Api\Domain\Report\ReportResult;

/**
 * The administrator's dashboard, assembled from reports that already exist.
 *
 * `AdminDashboard.php` was 842 lines of SQL, and almost every figure in it had
 * a twin in `class/reports/` — written separately, and disagreeing in places
 * (the two definitions of "mandatory" being the clearest). Nothing here writes
 * a query that a report already answers. What is left is the mixes and the two
 * feeds, which live in {@see DashboardRepository}.
 *
 * The scope comes with the criteria, so a line manager's dashboard covers their
 * own reports rather than the organisation — for free, because the reports
 * already honour it.
 */
final readonly class AdminDashboardAssembler
{
    /** How far ahead the deadline list looks. */
    private const DEADLINE_HORIZON_DAYS = 30;

    private const FEED_LENGTH = 15;

    public function __construct(
        private ReportRepository $reports,
        private DashboardRepository $dashboards,
        private ReportCatalogue $catalogue,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function assemble(ReportCriteria $criteria, DashboardPeriod $period): array
    {
        $executive  = $this->run(ReportKey::ExecutiveDashboard, $criteria);
        $supplement = $this->dashboards->overviewSupplement($criteria, $period);

        return [
            'period'   => $period->toArray(),
            'scope'    => $criteria->scope->toArray(),
            'overview' => $this->overview($executive, $supplement),
            'trends'   => [
                'enrolments'  => $this->run(ReportKey::EnrollmentTrends, $criteria)->series,
                'completions' => $this->run(ReportKey::CompletionTrend, $criteria)->series,
            ],
            'courses'     => $this->dashboards->courseMix($criteria),
            'learners'    => $this->dashboards->learnerMix($criteria, $period),
            'compliance'  => $this->totals(ReportKey::MandatoryCompliance, $criteria),
            'assessments' => $this->totals(ReportKey::AssessmentSummary, $criteria),
            // Tables, capped: a dashboard shows the head of a list and links
            // to the report for the rest.
            'topCourses'        => $this->rows(ReportKey::CourseEffectiveness, $criteria, 10),
            'atRiskLearners'    => $this->rows(ReportKey::StrugglingLearners, $criteria, 10),
            'overdue'           => $this->rows(ReportKey::OverdueTraining, $criteria, 10),
            'departments'       => $this->rows(ReportKey::CompletionByDepartment, $criteria, 10),
            'engagement'        => [
                'byHour' => $this->run(ReportKey::PeakUsage, $criteria)->series,
            ],
            'upcomingDeadlines' => $this->dashboards->upcomingDeadlines(
                $criteria,
                self::DEADLINE_HORIZON_DAYS,
                self::FEED_LENGTH,
            ),
            'recentCompletions' => $this->dashboards->recentCompletions($criteria, self::FEED_LENGTH),
            'recentActivity'    => $this->dashboards->recentActivity($criteria, self::FEED_LENGTH),
        ];
    }

    /**
     * @param  array<string, mixed> $supplement
     * @return array<string, mixed>
     */
    private function overview(ReportResult $executive, array $supplement): array
    {
        $totals = $executive->totals;

        return [
            'people'           => (int) ($totals['people'] ?? 0),
            'activeLast30'     => (int) ($totals['activeLast30'] ?? 0),
            'learners'         => (int) ($totals['learners'] ?? 0),
            'courses'          => (int) ($totals['courses'] ?? 0),
            'publishedCourses' => $supplement['publishedCourses'],
            'enrolments'       => (int) ($totals['enrolments'] ?? 0),
            'completed'        => (int) ($totals['completed'] ?? 0),
            'inProgress'       => $supplement['inProgress'],
            'notStarted'       => $supplement['notStarted'],
            'completionRate'   => (float) ($totals['completionRate'] ?? 0),
            'complianceRate'   => (float) ($totals['complianceRate'] ?? 0),
            'overdue'          => (int) ($totals['overdue'] ?? 0),
            'certificates'     => (int) ($totals['certificates'] ?? 0),
            'averageScore'     => $supplement['averageScore'],
            'learningSeconds'  => (int) ($totals['totalSeconds'] ?? 0),

            'enrolmentsThisPeriod'  => $supplement['enrolmentsThisPeriod'],
            'completionsThisPeriod' => $supplement['completionsThisPeriod'],
            // Null when the previous window was empty, and the client shows a
            // dash. The legacy divided by it and reported +100% growth for
            // every tenant in its first month.
            'enrolmentGrowth'  => DashboardPeriod::growth(
                $supplement['enrolmentsThisPeriod'],
                $supplement['enrolmentsPreviousPeriod'],
            ),
            'completionGrowth' => DashboardPeriod::growth(
                $supplement['completionsThisPeriod'],
                $supplement['completionsPreviousPeriod'],
            ),
        ];
    }

    private function run(ReportKey $key, ReportCriteria $criteria): ReportResult
    {
        return $this->reports->run($this->catalogue->find($key), $criteria);
    }

    /**
     * @return array<string, mixed>
     */
    private function totals(ReportKey $key, ReportCriteria $criteria): array
    {
        return $this->run($key, $criteria)->totals;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(ReportKey $key, ReportCriteria $criteria, int $limit): array
    {
        return array_slice($this->run($key, $criteria)->rows, 0, $limit);
    }
}
