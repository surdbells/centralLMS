<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Report\ReportColumn;
use Central\Api\Domain\Report\ReportCriteria;
use Central\Api\Domain\Report\ReportDefinition;
use Central\Api\Domain\Report\ReportKey;
use Central\Api\Domain\Report\ReportRepository;
use Central\Api\Domain\Report\ReportResult;
use Central\Api\Domain\Report\ReportScope;
use Doctrine\DBAL\Connection;

/**
 * The SQL behind every report.
 *
 * Long by nature — 57 reports are 57 questions — but each method is short and
 * every one of them starts the same way: `ReportQuery::tenant($criteria, …)`,
 * which is the only way to obtain a WHERE clause and always produces a tenant
 * condition. See {@see ReportQuery} for why that is a class rather than a
 * convention.
 *
 * Three things are deliberately consistent across all of them:
 *
 *   * **Dropped enrolments are excluded unless the report is about them.**
 *     Someone taken off a course is not "not started"; counting them as such
 *     makes every completion rate wrong in the pessimistic direction.
 *   * **Rates are computed, never stored.** `COUNT(*) FILTER (WHERE …)` over
 *     the rows, every time. The legacy kept `is_completed` beside `status` and
 *     they disagreed in 16 of 159 rows.
 *   * **Averages exclude the rows that have no value yet.** An average score
 *     over people who have not sat the assessment is not a low average, it is
 *     a wrong one.
 */
final class DbalReportRepository implements ReportRepository
{
    /**
     * What makes a piece of training mandatory.
     *
     * Either the assignment said so, or the course requires it of everybody.
     * Written once and referenced by every report that asks, because the
     * legacy checked only the first and a course marked mandatory in the
     * catalogue was invisible to compliance unless each enrolment happened to
     * repeat the flag — so two reports over the same data gave two answers.
     */
    private const MANDATORY = '(e.is_mandatory OR c.is_mandatory)';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function run(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        return match ($definition->key) {
            // --- enrolment ------------------------------------------------
            ReportKey::EnrollmentSummary  => $this->enrollmentSummary($definition, $criteria),
            ReportKey::EnrollmentTrends   => $this->enrollmentTrends($definition, $criteria),
            ReportKey::EnrollmentDetail   => $this->enrollmentDetail($definition, $criteria),
            ReportKey::PendingEnrollments => $this->pendingEnrollments($definition, $criteria),
            ReportKey::EnrollmentBySource => $this->enrollmentBySource($definition, $criteria),
            ReportKey::DropOff            => $this->dropOff($definition, $criteria),
            ReportKey::EnrollmentByCourse => $this->enrollmentByCourse($definition, $criteria),

            // --- completion -----------------------------------------------
            ReportKey::CompletionSummary      => $this->completionSummary($definition, $criteria),
            ReportKey::IndividualProgress     => $this->individualProgress($definition, $criteria),
            ReportKey::CompletionByDepartment => $this->completionByUnit($definition, $criteria, 'department'),
            ReportKey::CompletionByTeam       => $this->completionByUnit($definition, $criteria, 'team'),
            ReportKey::CompletionByBranch     => $this->completionByUnit($definition, $criteria, 'branch'),
            ReportKey::OverdueTraining        => $this->overdueTraining($definition, $criteria),
            ReportKey::TimeToCompletion       => $this->timeToCompletion($definition, $criteria),
            ReportKey::CompletionTrend        => $this->completionTrend($definition, $criteria),

            // --- compliance -----------------------------------------------
            ReportKey::MandatoryCompliance    => $this->mandatoryCompliance($definition, $criteria),
            ReportKey::NonCompliantUsers      => $this->nonCompliantUsers($definition, $criteria),
            ReportKey::ComplianceByDepartment => $this->complianceByUnit($definition, $criteria, 'department'),
            ReportKey::ComplianceByBranch     => $this->complianceByUnit($definition, $criteria, 'branch'),
            ReportKey::ComplianceByTeam       => $this->complianceByUnit($definition, $criteria, 'team'),
            ReportKey::ComplianceStatusDashboard => $this->complianceStatusDashboard($definition, $criteria),
            ReportKey::RegulatoryTrainingAudit   => $this->regulatoryTrainingAudit($definition, $criteria),
            ReportKey::ComplianceExecutiveSummary => $this->complianceExecutiveSummary($definition, $criteria),
            ReportKey::ComplianceGapAnalysis  => $this->complianceGapAnalysis($definition, $criteria),
            ReportKey::CertificationExpiry    => $this->certificationExpiry($definition, $criteria),
            ReportKey::RecertificationDue     => $this->recertificationDue($definition, $criteria),

            // --- assessment -----------------------------------------------
            ReportKey::AssessmentSummary    => $this->assessmentSummary($definition, $criteria),
            ReportKey::AssessmentDetail     => $this->assessmentDetail($definition, $criteria),
            ReportKey::IndividualAssessment => $this->individualAssessment($definition, $criteria),
            ReportKey::FailedAssessments    => $this->failedAssessments($definition, $criteria),
            ReportKey::QuestionAnalysis     => $this->questionAnalysis($definition, $criteria),
            ReportKey::ScoreDistribution    => $this->scoreDistribution($definition, $criteria),
            ReportKey::AssessmentAttempts   => $this->assessmentAttempts($definition, $criteria),
            ReportKey::TopPerformers        => $this->topPerformers($definition, $criteria),
            ReportKey::StrugglingLearners   => $this->strugglingLearners($definition, $criteria),
            ReportKey::ProctoringViolations => $this->proctoringViolations($definition, $criteria),
            // --- courses --------------------------------------------------
            ReportKey::CourseCatalog       => $this->courseCatalog($definition, $criteria),
            ReportKey::CourseEffectiveness => $this->courseEffectiveness($definition, $criteria),
            ReportKey::CourseUsage         => $this->courseUsage($definition, $criteria),
            ReportKey::ModulePerformance   => $this->modulePerformance($definition, $criteria),
            ReportKey::ContentEngagement   => $this->contentEngagement($definition, $criteria),
            ReportKey::UnusedCourses       => $this->unusedCourses($definition, $criteria),

            // --- people ---------------------------------------------------
            ReportKey::ActiveUsers         => $this->activeUsers($definition, $criteria),
            ReportKey::InactiveUsers       => $this->inactiveUsers($definition, $criteria),
            ReportKey::NewUsers            => $this->newUsers($definition, $criteria),
            ReportKey::UserActivitySummary => $this->userActivitySummary($definition, $criteria),
            ReportKey::DepartmentTraining  => $this->departmentTraining($definition, $criteria),
            ReportKey::RoleBasedTraining   => $this->roleBasedTraining($definition, $criteria),

            // --- time and engagement --------------------------------------
            ReportKey::TimeSpent       => $this->timeSpent($definition, $criteria),
            ReportKey::PeakUsage       => $this->peakUsage($definition, $criteria),
            ReportKey::SessionDuration => $this->sessionDuration($definition, $criteria),
            ReportKey::EngagementScore => $this->engagementScore($definition, $criteria),
            ReportKey::ActivityReport  => $this->activityReport($definition, $criteria),
            ReportKey::PlatformUsage   => $this->platformUsage($definition, $criteria),

            // --- certificates ---------------------------------------------
            ReportKey::CertificatesIssued   => $this->certificatesIssued($definition, $criteria),
            ReportKey::VerificationLog      => $this->verificationLog($definition, $criteria),
            ReportKey::ExpiringCertificates => $this->expiringCertificates($definition, $criteria),
            ReportKey::ReprintHistory       => $this->reprintHistory($definition, $criteria),
            ReportKey::TemplateUsage        => $this->templateUsage($definition, $criteria),

            // --- executive ------------------------------------------------
            ReportKey::ExecutiveDashboard => $this->executiveDashboard($definition, $criteria),
            ReportKey::MonthlyTraining    => $this->monthlyTraining($definition, $criteria),
            ReportKey::YearOverYear       => $this->yearOverYear($definition, $criteria),

            // --- gamification ---------------------------------------------
            ReportKey::Leaderboard           => $this->leaderboard($definition, $criteria),
            ReportKey::BadgesEarned          => $this->badgesEarned($definition, $criteria),
            ReportKey::PointsSummary         => $this->pointsSummary($definition, $criteria),
            ReportKey::DepartmentLeaderboard => $this->departmentLeaderboard($definition, $criteria),
            ReportKey::GamificationParticipation => $this->gamificationParticipation($definition, $criteria),

            // --- learning paths -------------------------------------------
            ReportKey::PathEnrollment    => $this->pathEnrollment($definition, $criteria),
            ReportKey::PathProgress      => $this->pathProgress($definition, $criteria),
            ReportKey::PathCompletion    => $this->pathCompletion($definition, $criteria),
            ReportKey::PathDropOff       => $this->pathDropOff($definition, $criteria),
            ReportKey::PathEffectiveness => $this->pathEffectiveness($definition, $criteria),

            // --- live training --------------------------------------------
            ReportKey::SessionAttendance       => $this->sessionAttendance($definition, $criteria),
            ReportKey::NoShow                  => $this->noShow($definition, $criteria),
            ReportKey::SessionUtilization      => $this->sessionUtilization($definition, $criteria),
            ReportKey::InstructorEffectiveness => $this->instructorEffectiveness($definition, $criteria),
            ReportKey::VenueUtilization        => $this->venueUtilization($definition, $criteria),

            // --- onboarding -----------------------------------------------
            ReportKey::OnboardingByCohort  => $this->onboardingByCohort($definition, $criteria),
            ReportKey::TimeToCompetency    => $this->timeToCompetency($definition, $criteria),
            ReportKey::OnboardingByOrg     => $this->onboardingByOrg($definition, $criteria),
            ReportKey::NewHireDashboard    => $this->newHireDashboard($definition, $criteria),
            ReportKey::OnboardingDropOff   => $this->onboardingDropOff($definition, $criteria),
            ReportKey::ProbationCompliance => $this->probationCompliance($definition, $criteria),

            // --- gaps in existing families --------------------------------
            ReportKey::SelfEnrollment      => $this->selfEnrollment($definition, $criteria),
            ReportKey::IncompleteCourses   => $this->incompleteCourses($definition, $criteria),
            ReportKey::CompletionByManager => $this->completionByManager($definition, $criteria),
            ReportKey::LearningHoursByDepartment => $this->learningHoursByDepartment($definition, $criteria),
            ReportKey::MonthlyTrainingHours => $this->monthlyTrainingHours($definition, $criteria),
            ReportKey::CertificatesByCourse => $this->certificatesByCourse($definition, $criteria),
            ReportKey::DrilldownLearners   => $this->drilldownLearners($definition, $criteria),

            // --- for managers ---------------------------------------------
            ReportKey::ManagerTeamDashboard     => $this->managerTeamDashboard($definition, $criteria),
            ReportKey::ManagerComplianceSummary => $this->managerComplianceSummary($definition, $criteria),
            ReportKey::ManagerActionRequired    => $this->managerActionRequired($definition, $criteria),
            ReportKey::TeamVsOrgBenchmark       => $this->teamVsOrgBenchmark($definition, $criteria),

            // --- cohorts and groups ---------------------------------------
            ReportKey::CohortProgress   => $this->cohortProgress($definition, $criteria),
            ReportKey::GroupPerformance => $this->groupPerformance($definition, $criteria),
            ReportKey::PeerBenchmarking => $this->peerBenchmarking($definition, $criteria),

            // --- audit and records ----------------------------------------
            ReportKey::FullAuditTrail        => $this->fullAuditTrail($definition, $criteria),
            ReportKey::DataRetention         => $this->dataRetention($definition, $criteria),
            ReportKey::TrainingRecordsExport => $this->trainingRecordsExport($definition, $criteria),

            // --- system ---------------------------------------------------
            ReportKey::SystemUsage  => $this->systemUsage($definition, $criteria),
            ReportKey::StorageUsage => $this->storageUsage($definition, $criteria),

            // --- my learning (self-service) -------------------------------
            ReportKey::MyLearningSummary      => $this->myLearningSummary($definition, $criteria),
            ReportKey::MyEnrolledCourses      => $this->myCourses($definition, $criteria, 'all'),
            ReportKey::MyCompletedCourses     => $this->myCourses($definition, $criteria, 'completed'),
            ReportKey::MyInProgressCourses    => $this->myCourses($definition, $criteria, 'in_progress'),
            ReportKey::MyOverdueCourses       => $this->myCourses($definition, $criteria, 'overdue'),
            ReportKey::MyCertificates         => $this->myCertificates($definition, $criteria, false),
            ReportKey::MyExpiringCertificates => $this->myCertificates($definition, $criteria, true),
            ReportKey::MyBadges               => $this->myBadges($definition, $criteria),
            ReportKey::MyPointsSummary        => $this->myPointsSummary($definition, $criteria),
            ReportKey::MyAssessmentResults    => $this->myAssessmentResults($definition, $criteria),
            ReportKey::MyLearningPaths        => $this->myLearningPaths($definition, $criteria),
            ReportKey::MyComplianceStatus     => $this->myComplianceStatus($definition, $criteria),
            ReportKey::MyLearningTime         => $this->myLearningTime($definition, $criteria),
            ReportKey::MyTranscript           => $this->myTranscript($definition, $criteria),
            ReportKey::MyQuizHistory          => $this->myQuizHistory($definition, $criteria),
            ReportKey::MyMandatoryTraining    => $this->myMandatoryTraining($definition, $criteria),
            ReportKey::MyUpcomingDeadlines    => $this->myUpcomingDeadlines($definition, $criteria),
            ReportKey::MyPathProgress         => $this->myPathProgress($definition, $criteria),

            // --- assessment impact ----------------------------------------
            ReportKey::RepeatAssessmentTrend => $this->repeatAssessmentTrend($definition, $criteria),
            ReportKey::CourseLearnerRoster   => $this->courseLearnerRoster($definition, $criteria),
            // No default: the match covers every case of a closed enum, so
            // adding a report without a query behind it stops compiling here
            // rather than failing for somebody who ran it.
        };
    }

    // --- enrolment --------------------------------------------------------

    private function enrollmentSummary(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->betweenDates($criteria, 'e.enrolled_at')
            ->where('e.deleted_at IS NULL');

        $row = $this->connection->fetchAssociative(
            sprintf(
                "SELECT
                    COUNT(*)                                                        AS total,
                    COUNT(*) FILTER (WHERE e.status = 'not_started')                AS not_started,
                    COUNT(*) FILTER (WHERE e.status = 'in_progress')                AS in_progress,
                    COUNT(*) FILTER (WHERE e.status = 'completed')                  AS completed,
                    COUNT(*) FILTER (WHERE e.status = 'dropped')                    AS dropped,
                    COUNT(*) FILTER (WHERE " . self::MANDATORY . ")             AS mandatory,
                    COUNT(DISTINCT e.user_id)                                       AS learners,
                    COUNT(DISTINCT e.course_id)                                     AS courses,
                    -- Dropped rows are excluded from the averages: their
                    -- progress froze when they came off, and including it
                    -- drags every figure down for a reason nobody can see.
                    AVG(e.progress_percentage) FILTER (WHERE e.status <> 'dropped') AS avg_progress,
                    AVG(e.final_score) FILTER (WHERE e.status = 'completed'
                                                 AND e.final_score IS NOT NULL)     AS avg_score,
                    COALESCE(SUM(e.time_spent_seconds), 0)                          AS total_seconds
                 FROM enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN courses c ON c.id = e.course_id
                 WHERE %s",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        $row   = $row === false ? [] : $row;
        $total = (int) ($row['total'] ?? 0);

        return ReportResult::summary(
            $definition,
            [
                'total'          => $total,
                'learners'       => (int) ($row['learners'] ?? 0),
                'courses'        => (int) ($row['courses'] ?? 0),
                'mandatory'      => (int) ($row['mandatory'] ?? 0),
                'completed'      => (int) ($row['completed'] ?? 0),
                'completionRate' => $this->percent((int) ($row['completed'] ?? 0), $total),
                'avgProgress'    => $this->round($row['avg_progress'] ?? null),
                'avgScore'       => $this->round($row['avg_score'] ?? null),
                'totalSeconds'   => (int) ($row['total_seconds'] ?? 0),
            ],
            [
                ['label' => 'Not started', 'value' => (int) ($row['not_started'] ?? 0), 'percent' => $this->percent((int) ($row['not_started'] ?? 0), $total)],
                ['label' => 'In progress', 'value' => (int) ($row['in_progress'] ?? 0), 'percent' => $this->percent((int) ($row['in_progress'] ?? 0), $total)],
                ['label' => 'Completed',   'value' => (int) ($row['completed'] ?? 0),   'percent' => $this->percent((int) ($row['completed'] ?? 0), $total)],
                ['label' => 'Dropped',     'value' => (int) ($row['dropped'] ?? 0),     'percent' => $this->percent((int) ($row['dropped'] ?? 0), $total)],
            ],
            $criteria,
        );
    }

    private function enrollmentTrends(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->betweenDates($criteria, 'e.enrolled_at')
            ->where('e.deleted_at IS NULL');

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT to_char(date_trunc('month', e.enrolled_at), 'YYYY-MM') AS period,
                        COUNT(*)                                       AS enrolled,
                        COUNT(*) FILTER (WHERE e.status = 'completed') AS completed
                 FROM enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN courses c ON c.id = e.course_id
                 WHERE %s
                 GROUP BY 1
                 ORDER BY 1",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        return ReportResult::series(
            $definition,
            array_map(
                static fn (array $r): array => [
                    'label'     => (string) $r['period'],
                    'value'     => (int) $r['enrolled'],
                    'completed' => (int) $r['completed'],
                ],
                $rows,
            ),
            ['months' => count($rows), 'total' => array_sum(array_column($rows, 'enrolled'))],
            $criteria,
        );
    }

    private function enrollmentDetail(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->betweenDates($criteria, 'e.enrolled_at')
            ->where('e.deleted_at IS NULL');

        $sort = $criteria->sortColumn(
            ['learner', 'course', 'status', 'progress', 'score', 'enrolledAt', 'dueDate'],
            'enrolledAt',
        );

        $columns = [
            ReportColumn::text('learner', 'Learner'),
            ReportColumn::text('email', 'Email'),
            ReportColumn::text('department', 'Department'),
            ReportColumn::text('course', 'Course'),
            ReportColumn::status('status', 'Status'),
            ReportColumn::percent('progress', 'Progress'),
            ReportColumn::number('score', 'Score'),
            ReportColumn::duration('seconds', 'Time spent'),
            ReportColumn::date('enrolledAt', 'Enrolled'),
            ReportColumn::date('dueDate', 'Due'),
            ReportColumn::date('completedAt', 'Completed'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM enrollments e
             JOIN users u ON u.id = e.user_id
             JOIN courses c ON c.id = e.course_id
             LEFT JOIN lookups d ON d.id = u.department_id',
            $q,
            "trim(u.first_name || ' ' || u.last_name) AS learner,
             u.email                                  AS email,
             d.name                                   AS department,
             c.title                                  AS course,
             e.status                                 AS status,
             e.progress_percentage                    AS progress,
             e.final_score                            AS score,
             e.time_spent_seconds                     AS seconds,
             e.enrolled_at                            AS \"enrolledAt\",
             e.due_date                               AS \"dueDate\",
             e.completed_at                           AS \"completedAt\"",
            // One expression, not a list: the direction is appended to what
            // is passed, so `u.first_name, u.last_name` would sort the
            // surname descending and the forename ascending.
            [
                'learner'    => "(u.first_name || ' ' || u.last_name)",
                'course'     => 'c.title',
                'status'     => 'e.status',
                'progress'   => 'e.progress_percentage',
                'score'      => 'e.final_score',
                'enrolledAt' => 'e.enrolled_at',
                'dueDate'    => 'e.due_date',
            ][$sort] ?? 'e.enrolled_at',
        );
    }

    private function pendingEnrollments(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->betweenDates($criteria, 'e.enrolled_at')
            ->where('e.deleted_at IS NULL')
            // `not_started` alone, and not `progress = 0`: someone who opened a
            // lesson and closed it is in progress, and chasing them says
            // something different from chasing someone who never looked.
            ->where("e.status = 'not_started'");

        $columns = [
            ReportColumn::text('learner', 'Learner'),
            ReportColumn::text('email', 'Email'),
            ReportColumn::text('department', 'Department'),
            ReportColumn::text('course', 'Course'),
            ReportColumn::date('enrolledAt', 'Enrolled'),
            ReportColumn::date('dueDate', 'Due'),
            ReportColumn::number('daysWaiting', 'Days waiting'),
            ReportColumn::status('mandatory', 'Mandatory'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM enrollments e
             JOIN users u ON u.id = e.user_id
             JOIN courses c ON c.id = e.course_id
             LEFT JOIN lookups d ON d.id = u.department_id',
            $q,
            "trim(u.first_name || ' ' || u.last_name)                  AS learner,
             u.email                                                   AS email,
             d.name                                                    AS department,
             c.title                                                   AS course,
             e.enrolled_at                                             AS \"enrolledAt\",
             e.due_date                                                AS \"dueDate\",
             EXTRACT(DAY FROM now() - e.enrolled_at)::int              AS \"daysWaiting\",
             CASE WHEN e.is_mandatory THEN 'Mandatory' ELSE 'Optional' END AS mandatory",
            'e.enrolled_at',
        );
    }

    private function enrollmentBySource(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->betweenDates($criteria, 'e.enrolled_at')
            ->where('e.deleted_at IS NULL');

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT e.source,
                        COUNT(*)                                       AS total,
                        COUNT(*) FILTER (WHERE e.status = 'completed') AS completed
                 FROM enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN courses c ON c.id = e.course_id
                 WHERE %s
                 GROUP BY e.source
                 ORDER BY total DESC",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        $total = array_sum(array_map(static fn (array $r): int => (int) $r['total'], $rows));

        return ReportResult::series(
            $definition,
            array_map(
                fn (array $r): array => [
                    'label'          => $this->humanise((string) $r['source']),
                    'value'          => (int) $r['total'],
                    'percent'        => $this->percent((int) $r['total'], $total),
                    // Worth showing beside the count: self-enrolment and
                    // assignment produce very different completion rates, and
                    // that is the actual finding in this report.
                    'completionRate' => $this->percent((int) $r['completed'], (int) $r['total']),
                ],
                $rows,
            ),
            ['total' => $total],
            $criteria,
        );
    }

    private function dropOff(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $stale = (int) ($criteria->filters['inactiveDays'] ?? 14);
        $stale = max(1, min(365, $stale));

        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->where('e.deleted_at IS NULL')
            // Two different things, both worth chasing: taken off the course,
            // or started and gone quiet.
            ->where(
                "(e.status = 'dropped'
                  OR (e.status = 'in_progress'
                      AND e.last_activity_at IS NOT NULL
                      AND e.last_activity_at < now() - make_interval(days => :staleDays)))",
                'staleDays',
                $stale,
            );

        $columns = [
            ReportColumn::text('learner', 'Learner'),
            ReportColumn::text('email', 'Email'),
            ReportColumn::text('course', 'Course'),
            ReportColumn::status('status', 'Status'),
            ReportColumn::percent('progress', 'Progress reached'),
            ReportColumn::date('lastActivityAt', 'Last seen'),
            ReportColumn::number('daysSince', 'Days since'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM enrollments e
             JOIN users u ON u.id = e.user_id
             JOIN courses c ON c.id = e.course_id',
            $q,
            "trim(u.first_name || ' ' || u.last_name)  AS learner,
             u.email                                   AS email,
             c.title                                   AS course,
             CASE WHEN e.status = 'dropped' THEN 'Dropped' ELSE 'Gone quiet' END AS status,
             e.progress_percentage                     AS progress,
             e.last_activity_at                        AS \"lastActivityAt\",
             EXTRACT(DAY FROM now() - COALESCE(e.last_activity_at, e.enrolled_at))::int AS \"daysSince\"",
            'e.last_activity_at',
        );
    }

    private function enrollmentByCourse(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->betweenDates($criteria, 'e.enrolled_at')
            ->where('e.deleted_at IS NULL');

        $columns = [
            ReportColumn::text('course', 'Course'),
            ReportColumn::status('status', 'Status'),
            ReportColumn::number('enrolled', 'Enrolled'),
            ReportColumn::number('completed', 'Completed'),
            ReportColumn::percent('completionRate', 'Completion rate'),
            ReportColumn::percent('avgProgress', 'Average progress'),
            ReportColumn::number('avgScore', 'Average score'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            'FROM enrollments e
             JOIN users u ON u.id = e.user_id
             JOIN courses c ON c.id = e.course_id',
            $q,
            "c.title AS course,
             c.status AS status,
             COUNT(*) FILTER (WHERE e.status <> 'dropped')                   AS enrolled,
             COUNT(*) FILTER (WHERE e.status = 'completed')                  AS completed,
             ROUND(100.0 * COUNT(*) FILTER (WHERE e.status = 'completed')
                   / NULLIF(COUNT(*) FILTER (WHERE e.status <> 'dropped'), 0), 1) AS \"completionRate\",
             ROUND(AVG(e.progress_percentage) FILTER (WHERE e.status <> 'dropped'), 1) AS \"avgProgress\",
             ROUND(AVG(e.final_score) FILTER (WHERE e.final_score IS NOT NULL), 1)     AS \"avgScore\"",
            'c.id, c.title, c.status',
            'enrolled DESC',
        );
    }

    // --- completion -------------------------------------------------------

    private function completionSummary(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->betweenDates($criteria, 'e.enrolled_at')
            ->where('e.deleted_at IS NULL')
            ->where("e.status <> 'dropped'");

        $row = $this->connection->fetchAssociative(
            sprintf(
                "SELECT
                    COUNT(*)                                       AS total,
                    COUNT(*) FILTER (WHERE e.status = 'completed') AS completed,
                    COUNT(*) FILTER (WHERE e.status <> 'completed'
                                       AND e.due_date IS NOT NULL
                                       AND e.due_date < CURRENT_DATE) AS overdue,
                    COUNT(*) FILTER (WHERE e.status <> 'completed'
                                       AND e.due_date IS NOT NULL
                                       AND e.due_date >= CURRENT_DATE
                                       AND e.due_date < CURRENT_DATE + 7) AS due_this_week,
                    ROUND(AVG(e.progress_percentage), 1)           AS avg_progress,
                    ROUND(AVG(e.final_score) FILTER (WHERE e.status = 'completed'), 1) AS avg_score,
                    -- Only over people who actually finished: a median that
                    -- counts the unfinished as taking forever is not a median
                    -- of anything.
                    ROUND(AVG(EXTRACT(EPOCH FROM e.completed_at - e.enrolled_at) / 86400)
                          FILTER (WHERE e.status = 'completed' AND e.completed_at IS NOT NULL), 1) AS avg_days
                 FROM enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN courses c ON c.id = e.course_id
                 WHERE %s",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        $row   = $row === false ? [] : $row;
        $total = (int) ($row['total'] ?? 0);

        return ReportResult::summary(
            $definition,
            [
                'total'          => $total,
                'completed'      => (int) ($row['completed'] ?? 0),
                'completionRate' => $this->percent((int) ($row['completed'] ?? 0), $total),
                'overdue'        => (int) ($row['overdue'] ?? 0),
                'dueThisWeek'    => (int) ($row['due_this_week'] ?? 0),
                'avgProgress'    => $this->round($row['avg_progress'] ?? null),
                'avgScore'       => $this->round($row['avg_score'] ?? null),
                'avgDays'        => $this->round($row['avg_days'] ?? null),
            ],
            [],
            $criteria,
        );
    }

    private function individualProgress(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        // The subject is on the scope, not the filters: the right to see this
        // person was settled at the edge, and re-reading a request parameter
        // here would undo that.
        $learnerId = $criteria->scope->subjectId ?? $criteria->learnerId ?? '';

        $person = $this->connection->fetchAssociative(
            "SELECT trim(u.first_name || ' ' || u.last_name) AS name, u.email,
                    d.name AS department, t.name AS team, b.name AS branch,
                    u.created_at, u.last_login_at
             FROM users u
             LEFT JOIN lookups d ON d.id = u.department_id
             LEFT JOIN lookups t ON t.id = u.team_id
             LEFT JOIN lookups b ON b.id = u.branch_id
             WHERE u.id = :id AND u.tenant_id = :tenant AND u.deleted_at IS NULL",
            ['id' => $learnerId, 'tenant' => $criteria->scope->tenantId],
        );

        if ($person === false) {
            return ReportResult::summary($definition, [], [], $criteria)
                ->withNote('That person is not in this organisation.');
        }

        $q = ReportQuery::tenant($criteria, 'e')
            ->where('e.user_id = :learnerId', 'learnerId', $learnerId)
            ->where('e.deleted_at IS NULL');

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT c.title AS course, e.status, e.progress_percentage AS progress,
                        e.final_score AS score, e.time_spent_seconds AS seconds,
                        e.enrolled_at AS \"enrolledAt\", e.due_date AS \"dueDate\",
                        e.completed_at AS \"completedAt\",
                        (e.status <> 'completed' AND e.due_date IS NOT NULL
                         AND e.due_date < CURRENT_DATE) AS overdue
                 FROM enrollments e
                 JOIN courses c ON c.id = e.course_id
                 WHERE %s
                 ORDER BY e.enrolled_at DESC",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        $completed = count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'completed'));
        $active    = array_filter($rows, static fn (array $r): bool => $r['status'] !== 'dropped');

        return new ReportResult(
            definition: $definition,
            columns: [
                ReportColumn::text('course', 'Course'),
                ReportColumn::status('status', 'Status'),
                ReportColumn::percent('progress', 'Progress'),
                ReportColumn::number('score', 'Score'),
                ReportColumn::duration('seconds', 'Time spent'),
                ReportColumn::date('enrolledAt', 'Enrolled'),
                ReportColumn::date('dueDate', 'Due'),
                ReportColumn::date('completedAt', 'Completed'),
            ],
            rows: $rows,
            totals: [
                'learner'        => (string) $person['name'],
                'email'          => (string) $person['email'],
                'department'     => (string) ($person['department'] ?? ''),
                'team'           => (string) ($person['team'] ?? ''),
                'branch'         => (string) ($person['branch'] ?? ''),
                'enrolments'     => count($active),
                'completed'      => $completed,
                'completionRate' => $this->percent($completed, count($active)),
                'overdue'        => count(array_filter($rows, static fn (array $r): bool => (bool) $r['overdue'])),
                'totalSeconds'   => array_sum(array_map(static fn (array $r): int => (int) $r['seconds'], $rows)),
            ],
            criteria: $criteria,
        );
    }

    /**
     * Completion grouped by an organisational unit.
     *
     * One method for department, team and branch: the same query with a
     * different column. Three near-identical copies is how the legacy's
     * `completionByDepartment`, `completionByTeam` and `completionByBranch`
     * came to differ from one another in small ways nobody intended.
     */
    private function completionByUnit(
        ReportDefinition $definition,
        ReportCriteria $criteria,
        string $unit,
    ): ReportResult {
        // Not interpolated from anything a caller sends — the report key
        // chose it, and the three possibilities are written out here.
        $column = match ($unit) {
            'department' => 'department_id',
            'team'       => 'team_id',
            'branch'     => 'branch_id',
            default      => throw new \LogicException('Unknown organisational unit.'),
        };

        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->betweenDates($criteria, 'e.enrolled_at')
            ->where('e.deleted_at IS NULL')
            ->where("e.status <> 'dropped'");

        $columns = [
            ReportColumn::text('unit', ucfirst($unit)),
            ReportColumn::number('people', 'People'),
            ReportColumn::number('enrolled', 'Enrolments'),
            ReportColumn::number('completed', 'Completed'),
            ReportColumn::percent('completionRate', 'Completion rate'),
            ReportColumn::number('overdue', 'Overdue'),
            ReportColumn::percent('avgProgress', 'Average progress'),
            ReportColumn::number('avgScore', 'Average score'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            sprintf(
                'FROM enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN courses c ON c.id = e.course_id
                 LEFT JOIN lookups l ON l.id = u.%s',
                $column,
            ),
            $q,
            "COALESCE(l.name, 'Unassigned') AS unit,
             COUNT(DISTINCT e.user_id)      AS people,
             COUNT(*)                       AS enrolled,
             COUNT(*) FILTER (WHERE e.status = 'completed') AS completed,
             ROUND(100.0 * COUNT(*) FILTER (WHERE e.status = 'completed')
                   / NULLIF(COUNT(*), 0), 1)                AS \"completionRate\",
             COUNT(*) FILTER (WHERE e.status <> 'completed'
                                AND e.due_date IS NOT NULL
                                AND e.due_date < CURRENT_DATE)  AS overdue,
             ROUND(AVG(e.progress_percentage), 1)                AS \"avgProgress\",
             ROUND(AVG(e.final_score) FILTER (WHERE e.final_score IS NOT NULL), 1) AS \"avgScore\"",
            'l.id, l.name',
            '"completionRate" DESC NULLS LAST',
        );
    }

    private function overdueTraining(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->where('e.deleted_at IS NULL')
            ->where("e.status NOT IN ('completed', 'dropped')")
            // A date, not an instant. Comparing a due date against `now()`
            // makes everyone with a deadline today overdue from midnight.
            ->where('e.due_date IS NOT NULL AND e.due_date < CURRENT_DATE');

        $columns = [
            ReportColumn::text('learner', 'Learner'),
            ReportColumn::text('email', 'Email'),
            ReportColumn::text('department', 'Department'),
            ReportColumn::text('manager', 'Manager'),
            ReportColumn::text('course', 'Course'),
            ReportColumn::date('dueDate', 'Due'),
            ReportColumn::number('daysOverdue', 'Days overdue'),
            ReportColumn::percent('progress', 'Progress'),
            ReportColumn::status('urgency', 'Urgency'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM enrollments e
             JOIN users u ON u.id = e.user_id
             JOIN courses c ON c.id = e.course_id
             LEFT JOIN lookups d ON d.id = u.department_id
             LEFT JOIN users m ON m.id = u.manager_id',
            $q,
            "trim(u.first_name || ' ' || u.last_name) AS learner,
             u.email                                  AS email,
             d.name                                   AS department,
             NULLIF(trim(COALESCE(m.first_name, '') || ' ' || COALESCE(m.last_name, '')), '') AS manager,
             c.title                                  AS course,
             e.due_date                               AS \"dueDate\",
             (CURRENT_DATE - e.due_date)              AS \"daysOverdue\",
             e.progress_percentage                    AS progress,
             CASE
                 WHEN CURRENT_DATE - e.due_date > 30 THEN 'Critical'
                 WHEN CURRENT_DATE - e.due_date > 7  THEN 'High'
                 ELSE 'Recent'
             END AS urgency",
            '(CURRENT_DATE - e.due_date)',
            fixedDirection: 'DESC',
        );
    }

    private function timeToCompletion(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->betweenDates($criteria, 'e.completed_at')
            ->where('e.deleted_at IS NULL')
            ->where("e.status = 'completed' AND e.completed_at IS NOT NULL");

        $columns = [
            ReportColumn::text('course', 'Course'),
            ReportColumn::number('completions', 'Completions'),
            ReportColumn::number('avgDays', 'Average days'),
            ReportColumn::number('medianDays', 'Median days'),
            ReportColumn::number('fastestDays', 'Fastest'),
            ReportColumn::number('slowestDays', 'Slowest'),
            ReportColumn::duration('avgSeconds', 'Average time in the course'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            'FROM enrollments e
             JOIN users u ON u.id = e.user_id
             JOIN courses c ON c.id = e.course_id',
            $q,
            // Median alongside the mean, because one person who enrolled a
            // year ago and finished last week takes the average with them.
            'c.title AS course,
             COUNT(*) AS completions,
             ROUND(AVG(EXTRACT(EPOCH FROM e.completed_at - e.enrolled_at) / 86400), 1) AS "avgDays",
             ROUND(PERCENTILE_CONT(0.5) WITHIN GROUP (
                 ORDER BY EXTRACT(EPOCH FROM e.completed_at - e.enrolled_at) / 86400
             )::numeric, 1) AS "medianDays",
             ROUND(MIN(EXTRACT(EPOCH FROM e.completed_at - e.enrolled_at) / 86400), 1) AS "fastestDays",
             ROUND(MAX(EXTRACT(EPOCH FROM e.completed_at - e.enrolled_at) / 86400), 1) AS "slowestDays",
             ROUND(AVG(e.time_spent_seconds))::int AS "avgSeconds"',
            'c.id, c.title',
            'completions DESC',
        );
    }

    private function completionTrend(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->betweenDates($criteria, 'e.completed_at')
            ->where('e.deleted_at IS NULL')
            ->where("e.status = 'completed' AND e.completed_at IS NOT NULL");

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT to_char(date_trunc('month', e.completed_at), 'YYYY-MM') AS period,
                        COUNT(*) AS completed,
                        ROUND(AVG(e.final_score) FILTER (WHERE e.final_score IS NOT NULL), 1) AS avg_score
                 FROM enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN courses c ON c.id = e.course_id
                 WHERE %s
                 GROUP BY 1
                 ORDER BY 1",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        return ReportResult::series(
            $definition,
            array_map(
                fn (array $r): array => [
                    'label'    => (string) $r['period'],
                    'value'    => (int) $r['completed'],
                    'avgScore' => $this->round($r['avg_score'] ?? null),
                ],
                $rows,
            ),
            ['total' => array_sum(array_map(static fn (array $r): int => (int) $r['completed'], $rows))],
            $criteria,
        );
    }

    // --- compliance -------------------------------------------------------

    private function mandatoryCompliance(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->where('e.deleted_at IS NULL')
            // Mandatory means either the enrolment said so or the course is
            // mandatory for everyone. The legacy checked only the first, so a
            // course marked mandatory in the catalogue was invisible to
            // compliance unless each enrolment repeated the flag.
            ->where(self::MANDATORY)
            ->where("e.status <> 'dropped'");

        $row = $this->connection->fetchAssociative(
            sprintf(
                "SELECT
                    COUNT(*)                                       AS total,
                    COUNT(DISTINCT e.user_id)                      AS people,
                    COUNT(*) FILTER (WHERE e.status = 'completed') AS completed,
                    COUNT(*) FILTER (WHERE e.status <> 'completed'
                                       AND e.due_date IS NOT NULL
                                       AND e.due_date < CURRENT_DATE) AS overdue,
                    COUNT(DISTINCT e.user_id) FILTER (WHERE e.status <> 'completed') AS people_outstanding,
                    COUNT(*) FILTER (WHERE e.status <> 'completed'
                                       AND e.due_date IS NOT NULL
                                       AND e.due_date BETWEEN CURRENT_DATE AND CURRENT_DATE + 30) AS due_soon,
                    ROUND(AVG(e.progress_percentage), 1)           AS avg_progress
                 FROM enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN courses c ON c.id = e.course_id
                 WHERE %s",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        $row   = $row === false ? [] : $row;
        $total = (int) ($row['total'] ?? 0);

        return ReportResult::summary(
            $definition,
            [
                'requirements'      => $total,
                'people'            => (int) ($row['people'] ?? 0),
                'completed'         => (int) ($row['completed'] ?? 0),
                // The figure a regulator asks for, and the one nobody should
                // have to compute from two others.
                'complianceRate'    => $this->percent((int) ($row['completed'] ?? 0), $total),
                'overdue'           => (int) ($row['overdue'] ?? 0),
                'dueWithin30Days'   => (int) ($row['due_soon'] ?? 0),
                'peopleOutstanding' => (int) ($row['people_outstanding'] ?? 0),
                'avgProgress'       => $this->round($row['avg_progress'] ?? null),
            ],
            [],
            $criteria,
        );
    }

    private function nonCompliantUsers(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->where('e.deleted_at IS NULL')
            ->where(self::MANDATORY)
            ->where("e.status NOT IN ('completed', 'dropped')");

        $columns = [
            ReportColumn::text('learner', 'Learner'),
            ReportColumn::text('email', 'Email'),
            ReportColumn::text('department', 'Department'),
            ReportColumn::text('manager', 'Manager'),
            ReportColumn::number('outstanding', 'Outstanding'),
            ReportColumn::number('overdue', 'Overdue'),
            ReportColumn::date('earliestDue', 'Earliest due'),
            ReportColumn::percent('avgProgress', 'Average progress'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            'FROM enrollments e
             JOIN users u ON u.id = e.user_id
             JOIN courses c ON c.id = e.course_id
             LEFT JOIN lookups d ON d.id = u.department_id
             LEFT JOIN users m ON m.id = u.manager_id',
            $q,
            "trim(u.first_name || ' ' || u.last_name) AS learner,
             u.email                                  AS email,
             d.name                                   AS department,
             NULLIF(trim(COALESCE(m.first_name, '') || ' ' || COALESCE(m.last_name, '')), '') AS manager,
             COUNT(*)                                 AS outstanding,
             COUNT(*) FILTER (WHERE e.due_date IS NOT NULL AND e.due_date < CURRENT_DATE) AS overdue,
             MIN(e.due_date)                          AS \"earliestDue\",
             ROUND(AVG(e.progress_percentage), 1)     AS \"avgProgress\"",
            'u.id, u.first_name, u.last_name, u.email, d.name, m.first_name, m.last_name',
            'overdue DESC, outstanding DESC',
        );
    }

    /**
     * Mandatory completion grouped by one organisational unit.
     *
     * One method for department, branch and team: the query is the same but
     * for which column names the group, and three copies would be three places
     * for the compliance-rate formula to drift.
     */
    private function complianceByUnit(
        ReportDefinition $definition,
        ReportCriteria $criteria,
        string $unit,
    ): ReportResult {
        $column = match ($unit) {
            'branch' => 'u.branch_id',
            'team'   => 'u.team_id',
            default  => 'u.department_id',
        };

        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->where('e.deleted_at IS NULL')
            ->where(self::MANDATORY)
            ->where("e.status <> 'dropped'");

        $columns = [
            ReportColumn::text('unit', ucfirst($unit)),
            ReportColumn::number('people', 'People'),
            ReportColumn::number('requirements', 'Requirements'),
            ReportColumn::number('completed', 'Completed'),
            ReportColumn::percent('complianceRate', 'Compliance rate'),
            ReportColumn::number('overdue', 'Overdue'),
            ReportColumn::number('peopleOutstanding', 'People outstanding'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            sprintf(
                'FROM enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN courses c ON c.id = e.course_id
                 LEFT JOIN lookups d ON d.id = %s',
                $column,
            ),
            $q,
            "COALESCE(d.name, 'Unassigned') AS unit,
             COUNT(DISTINCT e.user_id)      AS people,
             COUNT(*)                       AS requirements,
             COUNT(*) FILTER (WHERE e.status = 'completed') AS completed,
             ROUND(100.0 * COUNT(*) FILTER (WHERE e.status = 'completed')
                   / NULLIF(COUNT(*), 0), 1)               AS \"complianceRate\",
             COUNT(*) FILTER (WHERE e.status <> 'completed'
                                AND e.due_date IS NOT NULL
                                AND e.due_date < CURRENT_DATE) AS overdue,
             COUNT(DISTINCT e.user_id) FILTER (WHERE e.status <> 'completed') AS \"peopleOutstanding\"",
            'd.id, d.name',
            '"complianceRate" ASC NULLS FIRST',
        );
    }

    private function complianceStatusDashboard(
        ReportDefinition $definition,
        ReportCriteria $criteria,
    ): ReportResult {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->where('e.deleted_at IS NULL')
            ->where(self::MANDATORY)
            ->where("e.status <> 'dropped'");

        $row = $this->connection->fetchAssociative(
            sprintf(
                "SELECT COUNT(DISTINCT e.user_id) AS people,
                        COUNT(*) AS requirements,
                        COUNT(*) FILTER (WHERE e.status = 'completed') AS completed,
                        COUNT(*) FILTER (WHERE e.status <> 'completed') AS pending,
                        COUNT(*) FILTER (WHERE e.status <> 'completed'
                            AND e.due_date IS NOT NULL AND e.due_date < CURRENT_DATE) AS overdue,
                        COUNT(DISTINCT e.user_id) FILTER (
                            WHERE e.status <> 'completed'
                              AND e.due_date IS NOT NULL AND e.due_date < CURRENT_DATE
                        ) AS people_overdue
                 FROM enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN courses c ON c.id = e.course_id
                 WHERE %s",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        $row = $row === false ? [] : $row;
        $requirements = (int) ($row['requirements'] ?? 0);
        $completed = (int) ($row['completed'] ?? 0);

        // The picture broken down by course, so the dashboard is not just one
        // number but says which mandatory course is dragging it down.
        $byCourse = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT c.title AS label,
                        COUNT(*) AS value,
                        COUNT(*) FILTER (WHERE e.status = 'completed') AS completed
                 FROM enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN courses c ON c.id = e.course_id
                 WHERE %s
                 GROUP BY c.title
                 ORDER BY (COUNT(*) FILTER (WHERE e.status = 'completed')::numeric
                           / NULLIF(COUNT(*), 0)) ASC NULLS FIRST
                 LIMIT 20",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        return ReportResult::summary(
            $definition,
            [
                'people'         => (int) ($row['people'] ?? 0),
                'requirements'   => $requirements,
                'completed'      => $completed,
                'pending'        => (int) ($row['pending'] ?? 0),
                'overdue'        => (int) ($row['overdue'] ?? 0),
                'peopleOverdue'  => (int) ($row['people_overdue'] ?? 0),
                'complianceRate' => $this->percent($completed, $requirements),
            ],
            array_map(
                fn (array $r): array => [
                    'label'          => (string) $r['label'],
                    'value'          => (int) $r['value'],
                    'completionRate' => $this->percent((int) $r['completed'], (int) $r['value']),
                ],
                $byCourse,
            ),
            $criteria,
        );
    }

    private function regulatoryTrainingAudit(
        ReportDefinition $definition,
        ReportCriteria $criteria,
    ): ReportResult {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->betweenDates($criteria, 'e.completed_at')
            ->where('e.deleted_at IS NULL')
            ->where(self::MANDATORY)
            ->where("e.status = 'completed'");

        $columns = [
            ReportColumn::text('learner', 'Learner'),
            ReportColumn::text('email', 'Email'),
            ReportColumn::text('department', 'Department'),
            ReportColumn::text('course', 'Course'),
            ReportColumn::date('completedAt', 'Completed'),
            ReportColumn::text('certificate', 'Certificate'),
            ReportColumn::date('certificateExpiry', 'Expires'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            "FROM enrollments e
             JOIN users u ON u.id = e.user_id
             JOIN courses c ON c.id = e.course_id
             LEFT JOIN lookups d ON d.id = u.department_id
             LEFT JOIN certificates cert ON cert.user_id = e.user_id AND cert.course_id = e.course_id
                                        AND cert.status = 'active'",
            $q,
            "trim(u.first_name || ' ' || u.last_name) AS learner,
             u.email AS email,
             d.name AS department,
             c.title AS course,
             e.completed_at AS \"completedAt\",
             cert.number AS certificate,
             cert.valid_until AS \"certificateExpiry\"",
            'e.completed_at',
            'e.id',
        );
    }

    private function complianceExecutiveSummary(
        ReportDefinition $definition,
        ReportCriteria $criteria,
    ): ReportResult {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->where('e.deleted_at IS NULL')
            ->where(self::MANDATORY)
            ->where("e.status <> 'dropped'");

        $row = $this->connection->fetchAssociative(
            sprintf(
                "SELECT COUNT(DISTINCT e.user_id) AS people,
                        COUNT(*) AS requirements,
                        COUNT(*) FILTER (WHERE e.status = 'completed') AS completed,
                        COUNT(*) FILTER (WHERE e.status <> 'completed'
                            AND e.due_date IS NOT NULL AND e.due_date < CURRENT_DATE) AS overdue
                 FROM enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN courses c ON c.id = e.course_id
                 WHERE %s",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        $row = $row === false ? [] : $row;
        $requirements = (int) ($row['requirements'] ?? 0);
        $completed = (int) ($row['completed'] ?? 0);

        // The branches furthest behind, worst first — where an executive
        // looks after the headline rate. Named so the client can re-request
        // the leaf learner list scoped to that branch.
        $worstBranches = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT COALESCE(d.name, 'Unassigned') AS label,
                        u.branch_id AS \"branchId\",
                        COUNT(*) AS requirements,
                        COUNT(*) FILTER (WHERE e.status = 'completed') AS completed,
                        ROUND(100.0 * COUNT(*) FILTER (WHERE e.status = 'completed')
                              / NULLIF(COUNT(*), 0), 1) AS \"complianceRate\"
                 FROM enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN courses c ON c.id = e.course_id
                 LEFT JOIN lookups d ON d.id = u.branch_id
                 WHERE %s
                 GROUP BY u.branch_id, d.name
                 ORDER BY \"complianceRate\" ASC NULLS FIRST
                 LIMIT 10",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        return ReportResult::summary(
            $definition,
            [
                'people'         => (int) ($row['people'] ?? 0),
                'requirements'   => $requirements,
                'completed'      => $completed,
                'overdue'        => (int) ($row['overdue'] ?? 0),
                'complianceRate' => $this->percent($completed, $requirements),
            ],
            $this->normalise($worstBranches),
            $criteria,
        );
    }

    private function complianceGapAnalysis(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->where('e.deleted_at IS NULL')
            ->where(self::MANDATORY)
            ->where("e.status <> 'dropped'");

        $columns = [
            ReportColumn::text('course', 'Course'),
            ReportColumn::number('assigned', 'Assigned'),
            ReportColumn::number('completed', 'Completed'),
            ReportColumn::number('outstanding', 'Outstanding'),
            ReportColumn::percent('complianceRate', 'Compliance rate'),
            ReportColumn::number('overdue', 'Overdue'),
            ReportColumn::percent('avgProgress', 'Average progress'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            'FROM enrollments e
             JOIN users u ON u.id = e.user_id
             JOIN courses c ON c.id = e.course_id',
            $q,
            "c.title AS course,
             COUNT(*) AS assigned,
             COUNT(*) FILTER (WHERE e.status = 'completed')  AS completed,
             COUNT(*) FILTER (WHERE e.status <> 'completed') AS outstanding,
             ROUND(100.0 * COUNT(*) FILTER (WHERE e.status = 'completed')
                   / NULLIF(COUNT(*), 0), 1)                 AS \"complianceRate\",
             COUNT(*) FILTER (WHERE e.status <> 'completed'
                                AND e.due_date IS NOT NULL
                                AND e.due_date < CURRENT_DATE) AS overdue,
             ROUND(AVG(e.progress_percentage), 1)             AS \"avgProgress\"",
            'c.id, c.title',
            // Widest gap first: this report exists to say where to start.
            'outstanding DESC',
        );
    }

    private function certificationExpiry(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $within = max(1, min(730, (int) ($criteria->filters['withinDays'] ?? 90)));

        $q = ReportQuery::tenant($criteria, 'cert', 'u')
            ->withScopeFilters($criteria, 'u')
            ->where("cert.status = 'active'")
            ->where(
                'cert.valid_until IS NOT NULL
                 AND cert.valid_until BETWEEN CURRENT_DATE AND CURRENT_DATE + make_interval(days => :withinDays)',
                'withinDays',
                $within,
            );

        $columns = [
            ReportColumn::text('learner', 'Learner'),
            ReportColumn::text('email', 'Email'),
            ReportColumn::text('department', 'Department'),
            ReportColumn::text('title', 'Certificate'),
            ReportColumn::text('number', 'Number'),
            ReportColumn::date('validUntil', 'Expires'),
            ReportColumn::number('daysLeft', 'Days left'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM certificates cert
             JOIN users u ON u.id = cert.user_id
             LEFT JOIN lookups d ON d.id = u.department_id',
            $q,
            "trim(u.first_name || ' ' || u.last_name) AS learner,
             u.email                                  AS email,
             d.name                                   AS department,
             cert.title                               AS title,
             cert.number                              AS number,
             cert.valid_until                         AS \"validUntil\",
             (cert.valid_until - CURRENT_DATE)        AS \"daysLeft\"",
            'cert.valid_until',
            tieBreak: 'cert.id',
            fixedDirection: 'ASC',
        );
    }

    private function recertificationDue(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'cert', 'u')
            ->withScopeFilters($criteria, 'u')
            // Lapsed, and nothing in flight to replace it. A person already
            // re-enrolled on the course does not belong on a chase list.
            ->where('cert.valid_until IS NOT NULL AND cert.valid_until < CURRENT_DATE')
            ->where("cert.status IN ('active', 'expired')")
            ->where(
                "NOT EXISTS (
                    SELECT 1 FROM certificates newer
                    WHERE newer.tenant_id = cert.tenant_id
                      AND newer.user_id = cert.user_id
                      AND newer.course_id IS NOT DISTINCT FROM cert.course_id
                      AND newer.status = 'active'
                      AND (newer.valid_until IS NULL OR newer.valid_until >= CURRENT_DATE)
                 )",
            )
            ->where(
                "NOT EXISTS (
                    SELECT 1 FROM enrollments again
                    WHERE again.tenant_id = cert.tenant_id
                      AND again.user_id = cert.user_id
                      AND again.course_id = cert.course_id
                      AND again.deleted_at IS NULL
                      AND again.status IN ('not_started', 'in_progress')
                 )",
            );

        $columns = [
            ReportColumn::text('learner', 'Learner'),
            ReportColumn::text('email', 'Email'),
            ReportColumn::text('department', 'Department'),
            ReportColumn::text('title', 'Certificate'),
            ReportColumn::date('validUntil', 'Lapsed on'),
            ReportColumn::number('daysLapsed', 'Days lapsed'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM certificates cert
             JOIN users u ON u.id = cert.user_id
             LEFT JOIN lookups d ON d.id = u.department_id',
            $q,
            "trim(u.first_name || ' ' || u.last_name) AS learner,
             u.email                                  AS email,
             d.name                                   AS department,
             cert.title                               AS title,
             cert.valid_until                         AS \"validUntil\",
             (CURRENT_DATE - cert.valid_until)        AS \"daysLapsed\"",
            'cert.valid_until',
            tieBreak: 'cert.id',
            fixedDirection: 'ASC',
        );
    }

    // --- assessment -------------------------------------------------------

    private function assessmentSummary(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = $this->attemptScope($criteria);

        $row = $this->connection->fetchAssociative(
            sprintf(
                "SELECT
                    COUNT(*)                                    AS attempts,
                    COUNT(DISTINCT at.user_id)                  AS learners,
                    COUNT(DISTINCT at.assessment_id)            AS assessments,
                    COUNT(*) FILTER (WHERE at.passed)           AS passed,
                    COUNT(*) FILTER (WHERE at.passed IS FALSE)  AS failed,
                    -- Averages over graded attempts only: an abandoned
                    -- attempt has no score, and counting it as nought turns
                    -- a paper nobody finished into a paper everybody failed.
                    ROUND(AVG(at.percentage) FILTER (WHERE at.percentage IS NOT NULL), 1) AS avg_score,
                    ROUND(AVG(at.time_spent_seconds) FILTER (WHERE at.time_spent_seconds > 0))::int AS avg_seconds,
                    COUNT(*) FILTER (WHERE at.status = 'in_progress') AS in_progress
                 FROM attempts at
                 JOIN users u ON u.id = at.user_id
                 JOIN assessments a ON a.id = at.assessment_id
                 WHERE %s",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        $row    = $row === false ? [] : $row;
        $graded = (int) ($row['passed'] ?? 0) + (int) ($row['failed'] ?? 0);

        return ReportResult::summary(
            $definition,
            [
                'attempts'    => (int) ($row['attempts'] ?? 0),
                'learners'    => (int) ($row['learners'] ?? 0),
                'assessments' => (int) ($row['assessments'] ?? 0),
                'passed'      => (int) ($row['passed'] ?? 0),
                'failed'      => (int) ($row['failed'] ?? 0),
                // Over graded attempts, not over all of them.
                'passRate'    => $this->percent((int) ($row['passed'] ?? 0), $graded),
                'avgScore'    => $this->round($row['avg_score'] ?? null),
                'avgSeconds'  => (int) ($row['avg_seconds'] ?? 0),
                'inProgress'  => (int) ($row['in_progress'] ?? 0),
            ],
            [],
            $criteria,
        );
    }

    private function assessmentDetail(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = $this->attemptScope($criteria);

        $columns = [
            ReportColumn::text('learner', 'Learner'),
            ReportColumn::text('email', 'Email'),
            ReportColumn::text('assessment', 'Assessment'),
            ReportColumn::number('attemptNumber', 'Attempt'),
            ReportColumn::number('score', 'Score'),
            ReportColumn::percent('percentage', 'Percentage'),
            ReportColumn::status('outcome', 'Outcome'),
            ReportColumn::duration('seconds', 'Time taken'),
            ReportColumn::date('submittedAt', 'Submitted'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM attempts at
             JOIN users u ON u.id = at.user_id
             JOIN assessments a ON a.id = at.assessment_id',
            $q,
            "trim(u.first_name || ' ' || u.last_name) AS learner,
             u.email                                  AS email,
             a.title                                  AS assessment,
             at.attempt_number                        AS \"attemptNumber\",
             at.raw_score                             AS score,
             at.percentage                            AS percentage,
             CASE
                 WHEN at.passed IS TRUE  THEN 'Passed'
                 WHEN at.passed IS FALSE THEN 'Failed'
                 ELSE 'Not marked'
             END                                      AS outcome,
             at.time_spent_seconds                    AS seconds,
             at.submitted_at                          AS \"submittedAt\"",
            'at.submitted_at',
            tieBreak: 'at.id',
        );
    }

    private function individualAssessment(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $learnerId = $criteria->scope->subjectId ?? $criteria->learnerId ?? '';

        $person = $this->connection->fetchAssociative(
            "SELECT trim(u.first_name || ' ' || u.last_name) AS name, u.email
             FROM users u WHERE u.id = :id AND u.tenant_id = :tenant AND u.deleted_at IS NULL",
            ['id' => $learnerId, 'tenant' => $criteria->scope->tenantId],
        );

        if ($person === false) {
            return ReportResult::summary($definition, [], [], $criteria)
                ->withNote('That person is not in this organisation.');
        }

        $q = ReportQuery::tenant($criteria, 'at')
            ->where('at.user_id = :learnerId', 'learnerId', $learnerId);

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT a.title AS assessment, at.attempt_number AS \"attemptNumber\",
                        at.raw_score AS score, at.percentage, a.passing_score AS \"passMark\",
                        CASE
                            WHEN at.passed IS TRUE  THEN 'Passed'
                            WHEN at.passed IS FALSE THEN 'Failed'
                            ELSE 'Not marked'
                        END AS outcome,
                        at.time_spent_seconds AS seconds,
                        at.submitted_at AS \"submittedAt\"
                 FROM attempts at
                 JOIN assessments a ON a.id = at.assessment_id
                 WHERE %s
                 ORDER BY at.submitted_at DESC NULLS LAST, at.id",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        $graded = array_filter($rows, static fn (array $r): bool => $r['outcome'] !== 'Not marked');
        $passed = array_filter($rows, static fn (array $r): bool => $r['outcome'] === 'Passed');
        $scores = array_filter(
            array_map(static fn (array $r): mixed => $r['percentage'], $rows),
            static fn (mixed $v): bool => $v !== null,
        );

        return new ReportResult(
            definition: $definition,
            columns: [
                ReportColumn::text('assessment', 'Assessment'),
                ReportColumn::number('attemptNumber', 'Attempt'),
                ReportColumn::number('score', 'Score'),
                ReportColumn::percent('percentage', 'Percentage'),
                ReportColumn::number('passMark', 'Pass mark'),
                ReportColumn::status('outcome', 'Outcome'),
                ReportColumn::duration('seconds', 'Time taken'),
                ReportColumn::date('submittedAt', 'Submitted'),
            ],
            rows: $this->normalise($rows),
            totals: [
                'learner'  => (string) $person['name'],
                'email'    => (string) $person['email'],
                'attempts' => count($rows),
                'passed'   => count($passed),
                'passRate' => $this->percent(count($passed), count($graded)),
                'avgScore' => $scores === [] ? null : round(array_sum(array_map('floatval', $scores)) / count($scores), 1),
                'bestScore' => $scores === [] ? null : round(max(array_map('floatval', $scores)), 1),
            ],
            criteria: $criteria,
        );
    }

    private function failedAssessments(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = $this->attemptScope($criteria)->where('at.passed IS FALSE');

        $columns = [
            ReportColumn::text('learner', 'Learner'),
            ReportColumn::text('email', 'Email'),
            ReportColumn::text('assessment', 'Assessment'),
            ReportColumn::number('attemptNumber', 'Attempt'),
            ReportColumn::percent('percentage', 'Scored'),
            ReportColumn::number('passMark', 'Needed'),
            ReportColumn::number('shortBy', 'Short by'),
            ReportColumn::date('submittedAt', 'Submitted'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM attempts at
             JOIN users u ON u.id = at.user_id
             JOIN assessments a ON a.id = at.assessment_id',
            $q,
            "trim(u.first_name || ' ' || u.last_name) AS learner,
             u.email                                  AS email,
             a.title                                  AS assessment,
             at.attempt_number                        AS \"attemptNumber\",
             at.percentage                            AS percentage,
             a.passing_score                          AS \"passMark\",
             ROUND(a.passing_score - at.percentage, 1) AS \"shortBy\",
             at.submitted_at                          AS \"submittedAt\"",
            'at.submitted_at',
            tieBreak: 'at.id',
        );
    }

    private function questionAnalysis(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $assessmentId = $criteria->assessmentId ?? '';

        $q = ReportQuery::tenant($criteria, 'aq')
            ->where('aq.assessment_id = :assessmentId', 'assessmentId', $assessmentId);

        $columns = [
            ReportColumn::text('question', 'Question'),
            ReportColumn::text('type', 'Type'),
            ReportColumn::number('answered', 'Answered'),
            ReportColumn::number('correct', 'Correct'),
            ReportColumn::percent('correctRate', 'Correct rate'),
            ReportColumn::status('verdict', 'Reads as'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            'FROM assessment_questions aq
             JOIN questions qn ON qn.id = aq.question_id
             LEFT JOIN attempt_answers aa ON aa.question_id = aq.question_id
             LEFT JOIN attempts at ON at.id = aa.attempt_id AND at.assessment_id = aq.assessment_id',
            $q,
            "left(qn.text, 120)                          AS question,
             qn.type                                     AS type,
             COUNT(aa.id)                                AS answered,
             COUNT(aa.id) FILTER (WHERE aa.is_correct)   AS correct,
             ROUND(100.0 * COUNT(aa.id) FILTER (WHERE aa.is_correct)
                   / NULLIF(COUNT(aa.id), 0), 1)         AS \"correctRate\",
             -- The reason this report exists. A question everybody gets right
             -- is not testing anything; one almost everybody gets wrong is
             -- usually badly worded rather than hard.
             CASE
                 WHEN COUNT(aa.id) = 0 THEN 'Not yet answered'
                 WHEN 100.0 * COUNT(aa.id) FILTER (WHERE aa.is_correct) / COUNT(aa.id) >= 95 THEN 'Too easy'
                 WHEN 100.0 * COUNT(aa.id) FILTER (WHERE aa.is_correct) / COUNT(aa.id) <= 20 THEN 'Check the wording'
                 ELSE 'Discriminating'
             END                                         AS verdict",
            'aq.id, qn.id, qn.text, qn.type',
            '"correctRate" ASC NULLS LAST',
        );
    }

    private function scoreDistribution(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = $this->attemptScope($criteria)->where('at.percentage IS NOT NULL');

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT width_bucket(at.percentage, 0, 100, 10) AS band, COUNT(*) AS total
                 FROM attempts at
                 JOIN users u ON u.id = at.user_id
                 JOIN assessments a ON a.id = at.assessment_id
                 WHERE %s
                 GROUP BY band
                 ORDER BY band',
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        $counts = [];

        foreach ($rows as $row) {
            // width_bucket puts exactly 100 into an eleventh bucket. It
            // belongs in the top band, not in one of its own.
            $band = min(10, max(1, (int) $row['band']));
            $counts[$band] = ($counts[$band] ?? 0) + (int) $row['total'];
        }

        $total  = array_sum($counts);
        $series = [];

        for ($band = 1; $band <= 10; $band++) {
            $count = $counts[$band] ?? 0;

            $series[] = [
                // Every band, including the empty ones: a distribution with
                // gaps where nobody scored is a different picture from one
                // drawn only through the bands that have somebody in them.
                'label'   => sprintf('%d–%d%%', ($band - 1) * 10, $band * 10),
                'value'   => $count,
                'percent' => $this->percent($count, $total),
            ];
        }

        return ReportResult::series($definition, $series, ['attempts' => $total], $criteria);
    }

    private function assessmentAttempts(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = $this->attemptScope($criteria);

        $columns = [
            ReportColumn::text('assessment', 'Assessment'),
            ReportColumn::number('learners', 'Learners'),
            ReportColumn::number('attempts', 'Attempts'),
            ReportColumn::number('avgAttempts', 'Average attempts'),
            ReportColumn::number('maxAllowed', 'Allowed'),
            ReportColumn::number('exhausted', 'Out of attempts'),
            ReportColumn::percent('passRate', 'Pass rate'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            'FROM attempts at
             JOIN users u ON u.id = at.user_id
             JOIN assessments a ON a.id = at.assessment_id',
            $q,
            "a.title                          AS assessment,
             COUNT(DISTINCT at.user_id)       AS learners,
             COUNT(*)                         AS attempts,
             ROUND(COUNT(*)::numeric / NULLIF(COUNT(DISTINCT at.user_id), 0), 1) AS \"avgAttempts\",
             a.max_attempts                   AS \"maxAllowed\",
             -- People who used every attempt and never passed. The figure
             -- that says a paper is too hard or a cohort needs teaching.
             COUNT(DISTINCT at.user_id) FILTER (
                 WHERE a.max_attempts IS NOT NULL AND at.attempt_number >= a.max_attempts
             )                                AS exhausted,
             ROUND(100.0 * COUNT(*) FILTER (WHERE at.passed)
                   / NULLIF(COUNT(*) FILTER (WHERE at.passed IS NOT NULL), 0), 1) AS \"passRate\"",
            'a.id, a.title, a.max_attempts',
            'attempts DESC',
        );
    }

    private function topPerformers(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        return $this->performers($definition, $criteria, best: true);
    }

    private function strugglingLearners(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        return $this->performers($definition, $criteria, best: false);
    }

    /**
     * Both ends of the same league table.
     *
     * One method because they are the same query ordered differently, and two
     * copies is how they come to disagree about what counts as an attempt.
     */
    private function performers(
        ReportDefinition $definition,
        ReportCriteria $criteria,
        bool $best,
    ): ReportResult {
        $q = $this->attemptScope($criteria)
            ->where('at.percentage IS NOT NULL')
            // A single attempt is not a track record in either direction.
            ->where('TRUE');

        $columns = [
            ReportColumn::text('learner', 'Learner'),
            ReportColumn::text('email', 'Email'),
            ReportColumn::text('department', 'Department'),
            ReportColumn::number('attempts', 'Attempts'),
            ReportColumn::percent('avgScore', 'Average score'),
            ReportColumn::percent('bestScore', 'Best'),
            ReportColumn::number('passed', 'Passed'),
            ReportColumn::number('failed', 'Failed'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            'FROM attempts at
             JOIN users u ON u.id = at.user_id
             JOIN assessments a ON a.id = at.assessment_id
             LEFT JOIN lookups d ON d.id = u.department_id',
            $q,
            "trim(u.first_name || ' ' || u.last_name)      AS learner,
             u.email                                       AS email,
             d.name                                        AS department,
             COUNT(*)                                      AS attempts,
             ROUND(AVG(at.percentage), 1)                  AS \"avgScore\",
             ROUND(MAX(at.percentage), 1)                  AS \"bestScore\",
             COUNT(*) FILTER (WHERE at.passed)             AS passed,
             COUNT(*) FILTER (WHERE at.passed IS FALSE)    AS failed",
            'u.id, u.first_name, u.last_name, u.email, d.name',
            $best
                ? '"avgScore" DESC, passed DESC'
                // Failures first, then the lowest average: someone who failed
                // four times matters more than someone who scraped through.
                : 'failed DESC, "avgScore" ASC',
        );
    }

    private function proctoringViolations(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'v', 'u')
            ->withScopeFilters($criteria, 'u')
            ->betweenDates($criteria, 'v.occurred_at')
            ->maybe($criteria->assessmentId, 'at.assessment_id = :assessmentId', 'assessmentId');

        $columns = [
            ReportColumn::text('learner', 'Learner'),
            ReportColumn::text('assessment', 'Assessment'),
            ReportColumn::text('type', 'Violation'),
            ReportColumn::status('severity', 'Severity'),
            ReportColumn::text('description', 'What was seen'),
            ReportColumn::status('reviewStatus', 'Review'),
            ReportColumn::date('occurredAt', 'When'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM proctoring_violations v
             JOIN proctoring_sessions ps ON ps.id = v.session_id
             JOIN attempts at ON at.id = ps.attempt_id
             JOIN users u ON u.id = ps.user_id
             JOIN assessments a ON a.id = at.assessment_id',
            $q,
            "trim(u.first_name || ' ' || u.last_name) AS learner,
             a.title                                  AS assessment,
             v.type                                   AS type,
             v.severity                               AS severity,
             v.description                            AS description,
             v.review_status                          AS \"reviewStatus\",
             v.occurred_at                            AS \"occurredAt\"",
            'v.occurred_at',
            tieBreak: 'v.id',
        );
    }

    /**
     * The scope shared by every assessment report.
     *
     * `submitted_at` rather than `started_at` for the date range: a report of
     * "March results" means papers marked in March, and an attempt opened on
     * the 31st and submitted in April is an April result.
     */
    private function attemptScope(ReportCriteria $criteria): ReportQuery
    {
        return ReportQuery::tenant($criteria, 'at', 'u')
            ->withScopeFilters($criteria, 'u')
            ->betweenDates($criteria, 'at.submitted_at')
            ->maybe($criteria->assessmentId, 'at.assessment_id = :assessmentId', 'assessmentId')
            ->maybe($criteria->courseId, 'a.course_id = :courseId', 'courseId')
            ->where('a.deleted_at IS NULL');
    }

    // --- courses ----------------------------------------------------------

    private function courseCatalog(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'c')
            ->maybe($criteria->courseId, 'c.id = :courseId', 'courseId')
            ->betweenDates($criteria, 'c.created_at')
            ->where('c.deleted_at IS NULL');

        $columns = [
            ReportColumn::text('course', 'Course'),
            ReportColumn::text('category', 'Category'),
            ReportColumn::status('status', 'Status'),
            ReportColumn::text('level', 'Level'),
            ReportColumn::number('modules', 'Modules'),
            ReportColumn::number('lessons', 'Lessons'),
            ReportColumn::duration('durationSeconds', 'Length'),
            ReportColumn::status('mandatory', 'Mandatory'),
            ReportColumn::number('enrolled', 'Enrolled'),
            ReportColumn::date('publishedAt', 'Published'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM courses c
             LEFT JOIN lookups cat ON cat.id = c.category_id',
            $q,
            "c.title    AS course,
             cat.name   AS category,
             c.status   AS status,
             c.level    AS level,
             (SELECT COUNT(*) FROM course_modules m
               WHERE m.course_id = c.id AND m.deleted_at IS NULL)  AS modules,
             (SELECT COUNT(*) FROM lessons l
               WHERE l.course_id = c.id AND l.deleted_at IS NULL)  AS lessons,
             COALESCE(c.duration_minutes, 0) * 60                  AS \"durationSeconds\",
             CASE WHEN c.is_mandatory THEN 'Mandatory' ELSE 'Optional' END AS mandatory,
             (SELECT COUNT(*) FROM enrollments e
               WHERE e.course_id = c.id AND e.deleted_at IS NULL
                 AND e.status <> 'dropped')                        AS enrolled,
             c.published_at AS \"publishedAt\"",
            'c.title',
            tieBreak: 'c.id',
            fixedDirection: 'ASC',
        );
    }

    private function courseEffectiveness(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->betweenDates($criteria, 'e.enrolled_at')
            ->where('e.deleted_at IS NULL')
            ->where("e.status <> 'dropped'");

        $columns = [
            ReportColumn::text('course', 'Course'),
            ReportColumn::number('enrolled', 'Enrolled'),
            ReportColumn::percent('completionRate', 'Completion rate'),
            ReportColumn::number('avgScore', 'Average score'),
            ReportColumn::duration('avgSeconds', 'Average time'),
            ReportColumn::number('avgDays', 'Average days'),
            ReportColumn::percent('dropRate', 'Drop rate'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            'FROM enrollments e
             JOIN users u ON u.id = e.user_id
             JOIN courses c ON c.id = e.course_id',
            $q,
            "c.title  AS course,
             COUNT(*) AS enrolled,
             ROUND(100.0 * COUNT(*) FILTER (WHERE e.status = 'completed')
                   / NULLIF(COUNT(*), 0), 1)                          AS \"completionRate\",
             ROUND(AVG(e.final_score) FILTER (WHERE e.final_score IS NOT NULL), 1) AS \"avgScore\",
             ROUND(AVG(e.time_spent_seconds) FILTER (WHERE e.status = 'completed'))::int AS \"avgSeconds\",
             ROUND(AVG(EXTRACT(EPOCH FROM e.completed_at - e.enrolled_at) / 86400)
                   FILTER (WHERE e.completed_at IS NOT NULL), 1)      AS \"avgDays\",
             -- Dropped rows are excluded from the scope above, so the rate is
             -- counted from the course rather than from this result set.
             ROUND(100.0 * (SELECT COUNT(*) FROM enrollments dropped
                             WHERE dropped.course_id = c.id
                               AND dropped.deleted_at IS NULL
                               AND dropped.status = 'dropped')
                   / NULLIF((SELECT COUNT(*) FROM enrollments any_e
                              WHERE any_e.course_id = c.id
                                AND any_e.deleted_at IS NULL), 0), 1) AS \"dropRate\"",
            'c.id, c.title',
            'enrolled DESC',
        );
    }

    private function courseUsage(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->betweenDates($criteria, 'e.last_activity_at')
            ->where('e.deleted_at IS NULL');

        $columns = [
            ReportColumn::text('course', 'Course'),
            ReportColumn::number('learners', 'Learners'),
            ReportColumn::number('active', 'Active in period'),
            ReportColumn::duration('totalSeconds', 'Total time'),
            ReportColumn::duration('avgSeconds', 'Average per learner'),
            ReportColumn::date('lastActivityAt', 'Last touched'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            'FROM enrollments e
             JOIN users u ON u.id = e.user_id
             JOIN courses c ON c.id = e.course_id',
            $q,
            "c.title                                     AS course,
             COUNT(DISTINCT e.user_id)                   AS learners,
             COUNT(DISTINCT e.user_id) FILTER (
                 WHERE e.last_activity_at > now() - interval '30 days'
             )                                           AS active,
             COALESCE(SUM(e.time_spent_seconds), 0)      AS \"totalSeconds\",
             ROUND(AVG(e.time_spent_seconds))::int       AS \"avgSeconds\",
             MAX(e.last_activity_at)                     AS \"lastActivityAt\"",
            'c.id, c.title',
            '"totalSeconds" DESC',
        );
    }

    private function modulePerformance(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $courseId = $criteria->courseId ?? '';

        $q = ReportQuery::tenant($criteria, 'm')
            ->where('m.course_id = :courseId', 'courseId', $courseId)
            ->where('m.deleted_at IS NULL');

        $columns = [
            ReportColumn::text('module', 'Module'),
            ReportColumn::number('position', 'Position'),
            ReportColumn::number('lessons', 'Lessons'),
            ReportColumn::number('started', 'Reached'),
            ReportColumn::number('completed', 'Completed'),
            ReportColumn::percent('completionRate', 'Completion rate'),
            ReportColumn::duration('avgSeconds', 'Average time'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            'FROM course_modules m
             LEFT JOIN lessons l ON l.module_id = m.id AND l.deleted_at IS NULL
             LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id',
            $q,
            "m.title      AS module,
             m.sort_order AS position,
             COUNT(DISTINCT l.id) AS lessons,
             -- Distinct people, not rows: a module of six lessons would
             -- otherwise report six times its actual reach.
             COUNT(DISTINCT lp.user_id)                                  AS started,
             COUNT(DISTINCT lp.user_id) FILTER (WHERE lp.status = 'completed') AS completed,
             ROUND(100.0 * COUNT(DISTINCT lp.user_id) FILTER (WHERE lp.status = 'completed')
                   / NULLIF(COUNT(DISTINCT lp.user_id), 0), 1)           AS \"completionRate\",
             ROUND(AVG(lp.time_spent_seconds))::int                      AS \"avgSeconds\"",
            'm.id, m.title, m.sort_order',
            'position ASC',
        );
    }

    private function contentEngagement(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $courseId = $criteria->courseId ?? '';

        $q = ReportQuery::tenant($criteria, 'l')
            ->where('l.course_id = :courseId', 'courseId', $courseId)
            ->where('l.deleted_at IS NULL');

        $columns = [
            ReportColumn::text('lesson', 'Lesson'),
            ReportColumn::text('module', 'Module'),
            ReportColumn::text('contentType', 'Type'),
            ReportColumn::number('viewers', 'Viewers'),
            ReportColumn::number('completed', 'Completed'),
            ReportColumn::percent('completionRate', 'Completion rate'),
            ReportColumn::duration('avgSeconds', 'Average time'),
            ReportColumn::duration('expectedSeconds', 'Expected'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            'FROM lessons l
             LEFT JOIN course_modules m ON m.id = l.module_id
             LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id',
            $q,
            "l.title        AS lesson,
             m.title        AS module,
             l.content_type AS \"contentType\",
             COUNT(DISTINCT lp.user_id) AS viewers,
             COUNT(DISTINCT lp.user_id) FILTER (WHERE lp.status = 'completed') AS completed,
             ROUND(100.0 * COUNT(DISTINCT lp.user_id) FILTER (WHERE lp.status = 'completed')
                   / NULLIF(COUNT(DISTINCT lp.user_id), 0), 1) AS \"completionRate\",
             ROUND(AVG(lp.time_spent_seconds))::int            AS \"avgSeconds\",
             -- Beside the actual time, so a lesson budgeted at ten minutes
             -- that takes forty is visible without arithmetic.
             COALESCE(l.duration_minutes, 0) * 60              AS \"expectedSeconds\"",
            'l.id, l.title, m.title, l.content_type, l.duration_minutes, l.sort_order',
            'l.sort_order ASC',
        );
    }

    private function unusedCourses(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'c')
            ->where('c.deleted_at IS NULL')
            ->where("c.status = 'published'")
            ->where(
                "NOT EXISTS (
                    SELECT 1 FROM enrollments e
                    WHERE e.course_id = c.id AND e.deleted_at IS NULL AND e.status <> 'dropped'
                 )",
            );

        $columns = [
            ReportColumn::text('course', 'Course'),
            ReportColumn::text('category', 'Category'),
            ReportColumn::number('lessons', 'Lessons'),
            ReportColumn::date('publishedAt', 'Published'),
            ReportColumn::number('daysPublished', 'Days published'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM courses c
             LEFT JOIN lookups cat ON cat.id = c.category_id',
            $q,
            "c.title  AS course,
             cat.name AS category,
             (SELECT COUNT(*) FROM lessons l
               WHERE l.course_id = c.id AND l.deleted_at IS NULL) AS lessons,
             c.published_at AS \"publishedAt\",
             EXTRACT(DAY FROM now() - c.published_at)::int        AS \"daysPublished\"",
            'c.published_at',
            tieBreak: 'c.id',
            fixedDirection: 'ASC',
        );
    }

    // --- people -----------------------------------------------------------

    private function activeUsers(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'u', 'u')
            ->withScopeFilters($criteria, 'u')
            ->where('u.deleted_at IS NULL')
            ->where("u.status = 'active'")
            ->where(
                'u.last_login_at IS NOT NULL'
                . ($criteria->from !== null ? ' AND u.last_login_at >= :activeSince' : ''),
                $criteria->from !== null ? 'activeSince' : null,
                $criteria->from?->format('Y-m-d H:i:s.uP'),
            );

        $columns = [
            ReportColumn::text('name', 'Name'),
            ReportColumn::text('email', 'Email'),
            ReportColumn::text('department', 'Department'),
            ReportColumn::date('lastLoginAt', 'Last signed in'),
            ReportColumn::number('logins', 'Sign-ins'),
            ReportColumn::number('enrolments', 'Enrolments'),
            ReportColumn::number('completed', 'Completed'),
            ReportColumn::duration('seconds', 'Time spent'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM users u
             LEFT JOIN lookups d ON d.id = u.department_id',
            $q,
            "trim(u.first_name || ' ' || u.last_name) AS name,
             u.email          AS email,
             d.name           AS department,
             u.last_login_at  AS \"lastLoginAt\",
             u.login_count    AS logins,
             (SELECT COUNT(*) FROM enrollments e
               WHERE e.user_id = u.id AND e.deleted_at IS NULL AND e.status <> 'dropped') AS enrolments,
             (SELECT COUNT(*) FROM enrollments e
               WHERE e.user_id = u.id AND e.deleted_at IS NULL AND e.status = 'completed') AS completed,
             (SELECT COALESCE(SUM(e.time_spent_seconds), 0) FROM enrollments e
               WHERE e.user_id = u.id AND e.deleted_at IS NULL) AS seconds",
            'u.last_login_at',
            tieBreak: 'u.id',
        );
    }

    private function inactiveUsers(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $days = max(1, min(3650, (int) ($criteria->filters['inactiveDays'] ?? 60)));

        $q = ReportQuery::tenant($criteria, 'u', 'u')
            ->withScopeFilters($criteria, 'u')
            ->where('u.deleted_at IS NULL')
            ->where("u.status = 'active'")
            // Never signed in counts. Those are the accounts most worth
            // chasing, and a plain `last_login_at < …` drops every one of
            // them because NULL fails the comparison.
            ->where(
                '(u.last_login_at IS NULL OR u.last_login_at < now() - make_interval(days => :dormantDays))',
                'dormantDays',
                $days,
            );

        $columns = [
            ReportColumn::text('name', 'Name'),
            ReportColumn::text('email', 'Email'),
            ReportColumn::text('department', 'Department'),
            ReportColumn::date('lastLoginAt', 'Last signed in'),
            ReportColumn::number('daysDormant', 'Days dormant'),
            ReportColumn::number('outstanding', 'Outstanding training'),
            ReportColumn::date('createdAt', 'Joined'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM users u
             LEFT JOIN lookups d ON d.id = u.department_id',
            $q,
            "trim(u.first_name || ' ' || u.last_name) AS name,
             u.email         AS email,
             d.name          AS department,
             u.last_login_at AS \"lastLoginAt\",
             EXTRACT(DAY FROM now() - COALESCE(u.last_login_at, u.created_at))::int AS \"daysDormant\",
             (SELECT COUNT(*) FROM enrollments e
               WHERE e.user_id = u.id AND e.deleted_at IS NULL
                 AND e.status IN ('not_started', 'in_progress'))  AS outstanding,
             u.created_at    AS \"createdAt\"",
            'COALESCE(u.last_login_at, u.created_at)',
            tieBreak: 'u.id',
            fixedDirection: 'ASC',
        );
    }

    private function newUsers(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'u', 'u')
            ->withScopeFilters($criteria, 'u')
            ->betweenDates($criteria, 'u.created_at')
            ->where('u.deleted_at IS NULL');

        $columns = [
            ReportColumn::text('name', 'Name'),
            ReportColumn::text('email', 'Email'),
            ReportColumn::text('department', 'Department'),
            ReportColumn::date('createdAt', 'Joined'),
            ReportColumn::status('signedIn', 'Signed in yet'),
            ReportColumn::number('enrolments', 'Enrolments'),
            ReportColumn::number('completed', 'Completed'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM users u
             LEFT JOIN lookups d ON d.id = u.department_id',
            $q,
            "trim(u.first_name || ' ' || u.last_name) AS name,
             u.email      AS email,
             d.name       AS department,
             u.created_at AS \"createdAt\",
             -- The number that matters about a joiner: whether onboarding
             -- actually reached them.
             CASE WHEN u.last_login_at IS NULL THEN 'Not yet' ELSE 'Yes' END AS \"signedIn\",
             (SELECT COUNT(*) FROM enrollments e
               WHERE e.user_id = u.id AND e.deleted_at IS NULL AND e.status <> 'dropped') AS enrolments,
             (SELECT COUNT(*) FROM enrollments e
               WHERE e.user_id = u.id AND e.deleted_at IS NULL AND e.status = 'completed') AS completed",
            'u.created_at',
            tieBreak: 'u.id',
        );
    }

    private function userActivitySummary(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'u', 'u')
            ->withScopeFilters($criteria, 'u')
            ->where('u.deleted_at IS NULL');

        $row = $this->connection->fetchAssociative(
            sprintf(
                "SELECT
                    COUNT(*)                                              AS people,
                    COUNT(*) FILTER (WHERE u.status = 'active')           AS active,
                    COUNT(*) FILTER (WHERE u.last_login_at IS NULL)       AS never_signed_in,
                    COUNT(*) FILTER (WHERE u.last_login_at > now() - interval '30 days') AS active_30d,
                    COUNT(*) FILTER (WHERE u.created_at > now() - interval '30 days')    AS joined_30d
                 FROM users u
                 WHERE %s",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        // Enrolment figures come from their own scope, so the two never
        // disagree about which people are in view.
        $eq = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u')
            ->where('e.deleted_at IS NULL')
            ->where("e.status <> 'dropped'");

        $enrolments = $this->connection->fetchAssociative(
            sprintf(
                "SELECT COUNT(*) AS total,
                        COUNT(*) FILTER (WHERE e.status = 'completed') AS completed,
                        COALESCE(SUM(e.time_spent_seconds), 0)         AS seconds
                 FROM enrollments e
                 JOIN users u ON u.id = e.user_id
                 WHERE %s",
                $eq->sql(),
            ),
            $eq->params(),
            $eq->types(),
        );

        $row        = $row === false ? [] : $row;
        $enrolments = $enrolments === false ? [] : $enrolments;
        $people     = (int) ($row['people'] ?? 0);

        return ReportResult::summary(
            $definition,
            [
                'people'         => $people,
                'active'         => (int) ($row['active'] ?? 0),
                'neverSignedIn'  => (int) ($row['never_signed_in'] ?? 0),
                'activeLast30'   => (int) ($row['active_30d'] ?? 0),
                'joinedLast30'   => (int) ($row['joined_30d'] ?? 0),
                'enrolments'     => (int) ($enrolments['total'] ?? 0),
                'completed'      => (int) ($enrolments['completed'] ?? 0),
                'completionRate' => $this->percent(
                    (int) ($enrolments['completed'] ?? 0),
                    (int) ($enrolments['total'] ?? 0),
                ),
                'totalSeconds'   => (int) ($enrolments['seconds'] ?? 0),
                'avgPerPerson'   => $people === 0
                    ? 0
                    : (int) round((int) ($enrolments['seconds'] ?? 0) / $people),
            ],
            [],
            $criteria,
        );
    }

    private function departmentTraining(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'u', 'u')
            ->withScopeFilters($criteria, 'u')
            ->where('u.deleted_at IS NULL')
            ->where("u.status = 'active'");

        $columns = [
            ReportColumn::text('department', 'Department'),
            ReportColumn::number('people', 'People'),
            ReportColumn::number('enrolments', 'Enrolments'),
            ReportColumn::number('completed', 'Completed'),
            ReportColumn::percent('completionRate', 'Completion rate'),
            ReportColumn::duration('totalSeconds', 'Total time'),
            ReportColumn::number('neverSignedIn', 'Never signed in'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            'FROM users u
             LEFT JOIN lookups d ON d.id = u.department_id
             LEFT JOIN enrollments e ON e.user_id = u.id
                  AND e.deleted_at IS NULL AND e.status <> \'dropped\'',
            $q,
            "COALESCE(d.name, 'Unassigned') AS department,
             COUNT(DISTINCT u.id)           AS people,
             COUNT(e.id)                    AS enrolments,
             COUNT(e.id) FILTER (WHERE e.status = 'completed') AS completed,
             ROUND(100.0 * COUNT(e.id) FILTER (WHERE e.status = 'completed')
                   / NULLIF(COUNT(e.id), 0), 1)               AS \"completionRate\",
             COALESCE(SUM(e.time_spent_seconds), 0)           AS \"totalSeconds\",
             COUNT(DISTINCT u.id) FILTER (WHERE u.last_login_at IS NULL) AS \"neverSignedIn\"",
            'd.id, d.name',
            'people DESC',
        );
    }

    private function roleBasedTraining(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'u', 'u')
            ->withScopeFilters($criteria, 'u')
            ->where('u.deleted_at IS NULL')
            ->where("u.status = 'active'");

        $columns = [
            ReportColumn::text('role', 'Role'),
            ReportColumn::number('people', 'People'),
            ReportColumn::number('enrolments', 'Enrolments'),
            ReportColumn::number('completed', 'Completed'),
            ReportColumn::percent('completionRate', 'Completion rate'),
            ReportColumn::number('overdue', 'Overdue'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            "FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             LEFT JOIN enrollments e ON e.user_id = u.id
                  AND e.deleted_at IS NULL AND e.status <> 'dropped'",
            $q,
            "COALESCE(r.name, 'No role') AS role,
             COUNT(DISTINCT u.id)        AS people,
             COUNT(e.id)                 AS enrolments,
             COUNT(e.id) FILTER (WHERE e.status = 'completed') AS completed,
             ROUND(100.0 * COUNT(e.id) FILTER (WHERE e.status = 'completed')
                   / NULLIF(COUNT(e.id), 0), 1)               AS \"completionRate\",
             COUNT(e.id) FILTER (WHERE e.status <> 'completed'
                                   AND e.due_date IS NOT NULL
                                   AND e.due_date < CURRENT_DATE) AS overdue",
            'r.id, r.name',
            'people DESC',
        );
    }

    // --- time and engagement ----------------------------------------------

    private function timeSpent(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->betweenDates($criteria, 'e.last_activity_at')
            ->where('e.deleted_at IS NULL')
            ->where('e.time_spent_seconds > 0');

        $columns = [
            ReportColumn::text('learner', 'Learner'),
            ReportColumn::text('department', 'Department'),
            ReportColumn::number('courses', 'Courses'),
            ReportColumn::duration('totalSeconds', 'Total time'),
            ReportColumn::duration('avgSeconds', 'Average per course'),
            ReportColumn::number('completed', 'Completed'),
            ReportColumn::date('lastActivityAt', 'Last active'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            'FROM enrollments e
             JOIN users u ON u.id = e.user_id
             JOIN courses c ON c.id = e.course_id
             LEFT JOIN lookups d ON d.id = u.department_id',
            $q,
            "trim(u.first_name || ' ' || u.last_name) AS learner,
             d.name                                   AS department,
             COUNT(DISTINCT e.course_id)              AS courses,
             SUM(e.time_spent_seconds)                AS \"totalSeconds\",
             ROUND(AVG(e.time_spent_seconds))::int    AS \"avgSeconds\",
             COUNT(*) FILTER (WHERE e.status = 'completed') AS completed,
             MAX(e.last_activity_at)                  AS \"lastActivityAt\"",
            'u.id, u.first_name, u.last_name, d.name',
            '"totalSeconds" DESC',
        );
    }

    private function peakUsage(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'lp', 'u')
            ->withScopeFilters($criteria, 'u')
            ->betweenDates($criteria, 'lp.last_activity_at')
            ->where('lp.last_activity_at IS NOT NULL');

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT EXTRACT(HOUR FROM lp.last_activity_at)::int AS hour,
                        COUNT(*) AS total
                 FROM lesson_progress lp
                 JOIN users u ON u.id = lp.user_id
                 WHERE %s
                 GROUP BY hour',
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        $byHour = [];

        foreach ($rows as $row) {
            $byHour[(int) $row['hour']] = (int) $row['total'];
        }

        $total  = array_sum($byHour);
        $series = [];

        // All twenty-four, including the quiet ones. A chart drawn only
        // through the hours that had activity has no shape.
        for ($hour = 0; $hour < 24; $hour++) {
            $series[] = [
                'label'   => sprintf('%02d:00', $hour),
                'value'   => $byHour[$hour] ?? 0,
                'percent' => $this->percent($byHour[$hour] ?? 0, $total),
            ];
        }

        $peak = $byHour === [] ? null : array_search(max($byHour), $byHour, true);

        return ReportResult::series(
            $definition,
            $series,
            [
                'events'   => $total,
                'peakHour' => $peak === null || $peak === false ? null : sprintf('%02d:00', $peak),
            ],
            $criteria,
        );
    }

    private function sessionDuration(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $courseId = $criteria->courseId ?? '';

        $q = ReportQuery::tenant($criteria, 'lp', 'u')
            ->withScopeFilters($criteria, 'u')
            ->where('l.course_id = :courseId', 'courseId', $courseId)
            ->where('lp.time_spent_seconds > 0');

        $columns = [
            ReportColumn::text('lesson', 'Lesson'),
            ReportColumn::number('learners', 'Learners'),
            ReportColumn::duration('avgSeconds', 'Average'),
            ReportColumn::duration('medianSeconds', 'Median'),
            ReportColumn::duration('expectedSeconds', 'Expected'),
            ReportColumn::percent('overrun', 'Over expected by'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            'FROM lesson_progress lp
             JOIN lessons l ON l.id = lp.lesson_id
             JOIN users u ON u.id = lp.user_id',
            $q,
            "l.title                                  AS lesson,
             COUNT(DISTINCT lp.user_id)               AS learners,
             ROUND(AVG(lp.time_spent_seconds))::int   AS \"avgSeconds\",
             PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY lp.time_spent_seconds)::int AS \"medianSeconds\",
             COALESCE(l.duration_minutes, 0) * 60     AS \"expectedSeconds\",
             -- Negative means faster than budgeted, which is as useful to see
             -- as the overruns.
             ROUND(100.0 * (AVG(lp.time_spent_seconds) - COALESCE(l.duration_minutes, 0) * 60)
                   / NULLIF(COALESCE(l.duration_minutes, 0) * 60, 0), 1) AS overrun",
            'l.id, l.title, l.duration_minutes, l.sort_order',
            'l.sort_order ASC',
        );
    }

    private function engagementScore(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'u', 'u')
            ->withScopeFilters($criteria, 'u')
            ->where('u.deleted_at IS NULL')
            ->where("u.status = 'active'");

        $columns = [
            ReportColumn::text('learner', 'Learner'),
            ReportColumn::text('department', 'Department'),
            ReportColumn::number('score', 'Engagement'),
            ReportColumn::number('enrolments', 'Enrolments'),
            ReportColumn::percent('avgProgress', 'Average progress'),
            ReportColumn::duration('totalSeconds', 'Time spent'),
            ReportColumn::number('daysSinceActive', 'Days since active'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            "FROM users u
             LEFT JOIN lookups d ON d.id = u.department_id
             LEFT JOIN enrollments e ON e.user_id = u.id
                  AND e.deleted_at IS NULL AND e.status <> 'dropped'",
            $q,
            // Three parts out of a hundred: how recently they were here, how
            // far through their courses they are, and how much time they have
            // put in. Written out rather than hidden behind a magic number,
            // because anyone reading the column will want to know what it
            // means before they act on it.
            "trim(u.first_name || ' ' || u.last_name) AS learner,
             d.name AS department,
             LEAST(100, GREATEST(0, ROUND(
                 -- Recency, up to 40.
                 GREATEST(0, 40 - COALESCE(EXTRACT(DAY FROM now() - MAX(e.last_activity_at))::int, 90) * 1.0)
                 -- Progress, up to 40.
                 + COALESCE(AVG(e.progress_percentage), 0) * 0.4
                 -- Time, up to 20: one point per hour, capped.
                 + LEAST(20, COALESCE(SUM(e.time_spent_seconds), 0) / 3600.0)
             )))::int AS score,
             COUNT(e.id)                            AS enrolments,
             ROUND(AVG(e.progress_percentage), 1)   AS \"avgProgress\",
             COALESCE(SUM(e.time_spent_seconds), 0) AS \"totalSeconds\",
             EXTRACT(DAY FROM now() - MAX(e.last_activity_at))::int AS \"daysSinceActive\"",
            'u.id, u.first_name, u.last_name, d.name',
            'score DESC',
        );
    }

    private function activityReport(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'lp', 'u')
            ->withScopeFilters($criteria, 'u')
            ->betweenDates($criteria, 'lp.last_activity_at')
            ->where('lp.last_activity_at IS NOT NULL');

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT to_char(date_trunc('day', lp.last_activity_at), 'YYYY-MM-DD') AS period,
                        COUNT(*)                       AS events,
                        COUNT(DISTINCT lp.user_id)     AS learners,
                        COALESCE(SUM(lp.time_spent_seconds), 0) AS seconds
                 FROM lesson_progress lp
                 JOIN users u ON u.id = lp.user_id
                 WHERE %s
                 GROUP BY 1
                 ORDER BY 1",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        return ReportResult::series(
            $definition,
            array_map(
                static fn (array $r): array => [
                    'label'    => (string) $r['period'],
                    'value'    => (int) $r['events'],
                    'learners' => (int) $r['learners'],
                    'seconds'  => (int) $r['seconds'],
                ],
                $rows,
            ),
            [
                'days'    => count($rows),
                'events'  => array_sum(array_map(static fn (array $r): int => (int) $r['events'], $rows)),
                'seconds' => array_sum(array_map(static fn (array $r): int => (int) $r['seconds'], $rows)),
            ],
            $criteria,
        );
    }

    private function platformUsage(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->betweenDates($criteria, 'e.last_activity_at')
            ->where('e.deleted_at IS NULL');

        $row = $this->connection->fetchAssociative(
            sprintf(
                "SELECT
                    COUNT(DISTINCT e.user_id)              AS learners,
                    COUNT(DISTINCT e.course_id)            AS courses,
                    COALESCE(SUM(e.time_spent_seconds), 0) AS seconds,
                    COUNT(*)                               AS enrolments,
                    COUNT(*) FILTER (WHERE e.status = 'completed') AS completed,
                    COUNT(DISTINCT e.user_id) FILTER (
                        WHERE e.last_activity_at > now() - interval '7 days'
                    ) AS active_7d,
                    COUNT(DISTINCT e.user_id) FILTER (
                        WHERE e.last_activity_at > now() - interval '30 days'
                    ) AS active_30d
                 FROM enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN courses c ON c.id = e.course_id
                 WHERE %s",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        $row      = $row === false ? [] : $row;
        $learners = (int) ($row['learners'] ?? 0);

        return ReportResult::summary(
            $definition,
            [
                'learners'      => $learners,
                'courses'       => (int) ($row['courses'] ?? 0),
                'enrolments'    => (int) ($row['enrolments'] ?? 0),
                'completed'     => (int) ($row['completed'] ?? 0),
                'totalSeconds'  => (int) ($row['seconds'] ?? 0),
                'avgPerLearner' => $learners === 0
                    ? 0
                    : (int) round((int) ($row['seconds'] ?? 0) / $learners),
                'activeLast7'   => (int) ($row['active_7d'] ?? 0),
                'activeLast30'  => (int) ($row['active_30d'] ?? 0),
            ],
            [],
            $criteria,
        );
    }

    // --- certificates -----------------------------------------------------

    private function certificatesIssued(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'cert', 'u')
            ->withScopeFilters($criteria, 'u')
            ->betweenDates($criteria, 'cert.issued_at')
            ->maybe($criteria->courseId, 'cert.course_id = :courseId', 'courseId');

        $columns = [
            ReportColumn::text('learner', 'Learner'),
            ReportColumn::text('number', 'Number'),
            ReportColumn::text('title', 'For'),
            ReportColumn::text('source', 'Earned through'),
            ReportColumn::percent('percentage', 'Score'),
            ReportColumn::status('status', 'Status'),
            ReportColumn::date('issuedAt', 'Issued'),
            ReportColumn::date('validUntil', 'Valid until'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM certificates cert
             JOIN users u ON u.id = cert.user_id',
            $q,
            "cert.learner_name AS learner,
             cert.number       AS number,
             cert.title        AS title,
             cert.source       AS source,
             cert.percentage   AS percentage,
             cert.status       AS status,
             cert.issued_at    AS \"issuedAt\",
             cert.valid_until  AS \"validUntil\"",
            'cert.issued_at',
            tieBreak: 'cert.id',
        );
    }

    private function verificationLog(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'ev')
            ->betweenDates($criteria, 'ev.created_at')
            ->where("ev.action = 'verified'");

        $columns = [
            ReportColumn::text('number', 'Certificate'),
            ReportColumn::text('learner', 'Holder'),
            ReportColumn::text('title', 'For'),
            ReportColumn::number('checks', 'Times checked'),
            ReportColumn::date('firstCheckedAt', 'First checked'),
            ReportColumn::date('lastCheckedAt', 'Last checked'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            'FROM certificate_events ev
             JOIN certificates cert ON cert.id = ev.certificate_id',
            $q,
            // Grouped rather than listed row by row: the useful signal is a
            // certificate being checked unusually often, not each individual
            // check. Verifier details are deliberately not shown — the
            // employer checking a credential is not the subject of a report.
            'cert.number       AS number,
             cert.learner_name AS learner,
             cert.title        AS title,
             COUNT(*)          AS checks,
             MIN(ev.created_at) AS "firstCheckedAt",
             MAX(ev.created_at) AS "lastCheckedAt"',
            'cert.id, cert.number, cert.learner_name, cert.title',
            'checks DESC',
        );
    }

    private function expiringCertificates(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        return $this->certificationExpiry($definition, $criteria);
    }

    private function reprintHistory(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'ev')
            ->betweenDates($criteria, 'ev.created_at')
            ->where("ev.action <> 'verified'");

        $columns = [
            ReportColumn::text('number', 'Certificate'),
            ReportColumn::text('learner', 'Holder'),
            ReportColumn::status('action', 'What happened'),
            ReportColumn::text('actor', 'By'),
            ReportColumn::text('notes', 'Notes'),
            ReportColumn::date('at', 'When'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM certificate_events ev
             JOIN certificates cert ON cert.id = ev.certificate_id
             LEFT JOIN users actor ON actor.id = ev.actor_id',
            $q,
            "cert.number       AS number,
             cert.learner_name AS learner,
             ev.action         AS action,
             NULLIF(trim(COALESCE(actor.first_name, '') || ' ' || COALESCE(actor.last_name, '')), '') AS actor,
             ev.notes          AS notes,
             ev.created_at     AS at",
            'ev.created_at',
            tieBreak: 'ev.id',
        );
    }

    private function templateUsage(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 't')
            ->where('t.deleted_at IS NULL');

        $columns = [
            ReportColumn::text('template', 'Template'),
            ReportColumn::status('isDefault', 'Default'),
            ReportColumn::number('issued', 'Certificates issued'),
            ReportColumn::number('active', 'Still valid'),
            ReportColumn::date('firstUsedAt', 'First used'),
            ReportColumn::date('lastUsedAt', 'Last used'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            'FROM certificate_templates t
             LEFT JOIN certificates cert ON cert.template_id = t.id',
            $q,
            // Counted from the certificates, not from a `usage_count` column
            // maintained by a trigger — which is what the legacy had, and the
            // least visible form of a stored derived value.
            "t.name AS template,
             CASE WHEN t.is_default THEN 'Default' ELSE '' END AS \"isDefault\",
             COUNT(cert.id)                                    AS issued,
             COUNT(cert.id) FILTER (WHERE cert.status = 'active') AS active,
             MIN(cert.issued_at)                               AS \"firstUsedAt\",
             MAX(cert.issued_at)                               AS \"lastUsedAt\"",
            't.id, t.name, t.is_default',
            'issued DESC',
        );
    }

    // --- executive --------------------------------------------------------

    private function executiveDashboard(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $eq = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->where('e.deleted_at IS NULL');

        $row = $this->connection->fetchAssociative(
            sprintf(
                "SELECT
                    COUNT(*) FILTER (WHERE e.status <> 'dropped')  AS enrolments,
                    COUNT(*) FILTER (WHERE e.status = 'completed') AS completed,
                    COUNT(DISTINCT e.user_id)                      AS learners,
                    COUNT(DISTINCT e.course_id)                    AS courses,
                    COALESCE(SUM(e.time_spent_seconds), 0)         AS seconds,
                    COUNT(*) FILTER (WHERE e.status <> 'completed'
                                       AND e.status <> 'dropped'
                                       AND e.due_date IS NOT NULL
                                       AND e.due_date < CURRENT_DATE) AS overdue,
                    COUNT(*) FILTER (WHERE " . self::MANDATORY . "
                                       AND e.status <> 'dropped')  AS mandatory,
                    COUNT(*) FILTER (WHERE " . self::MANDATORY . "
                                       AND e.status = 'completed') AS mandatory_done,
                    COUNT(*) FILTER (WHERE e.completed_at > now() - interval '30 days') AS completed_30d
                 FROM enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN courses c ON c.id = e.course_id
                 WHERE %s",
                $eq->sql(),
            ),
            $eq->params(),
            $eq->types(),
        );

        $uq = ReportQuery::tenant($criteria, 'u', 'u')
            ->withScopeFilters($criteria, 'u')
            ->where('u.deleted_at IS NULL');

        $people = $this->connection->fetchAssociative(
            sprintf(
                "SELECT COUNT(*) FILTER (WHERE u.status = 'active') AS active,
                        COUNT(*) FILTER (WHERE u.last_login_at > now() - interval '30 days') AS active_30d
                 FROM users u WHERE %s",
                $uq->sql(),
            ),
            $uq->params(),
            $uq->types(),
        );

        $cq = ReportQuery::tenant($criteria, 'cert', 'u')->withScopeFilters($criteria, 'u');

        $certificates = (int) $this->connection->fetchOne(
            sprintf(
                "SELECT COUNT(*) FROM certificates cert
                 JOIN users u ON u.id = cert.user_id
                 WHERE %s AND cert.status = 'active'",
                $cq->sql(),
            ),
            $cq->params(),
            $cq->types(),
        );

        $row       = $row === false ? [] : $row;
        $people    = $people === false ? [] : $people;
        $enrolled  = (int) ($row['enrolments'] ?? 0);
        $mandatory = (int) ($row['mandatory'] ?? 0);

        return ReportResult::summary(
            $definition,
            [
                'people'          => (int) ($people['active'] ?? 0),
                'activeLast30'    => (int) ($people['active_30d'] ?? 0),
                'learners'        => (int) ($row['learners'] ?? 0),
                'courses'         => (int) ($row['courses'] ?? 0),
                'enrolments'      => $enrolled,
                'completed'       => (int) ($row['completed'] ?? 0),
                'completionRate'  => $this->percent((int) ($row['completed'] ?? 0), $enrolled),
                'completedLast30' => (int) ($row['completed_30d'] ?? 0),
                'overdue'         => (int) ($row['overdue'] ?? 0),
                'complianceRate'  => $this->percent((int) ($row['mandatory_done'] ?? 0), $mandatory),
                'certificates'    => $certificates,
                'totalSeconds'    => (int) ($row['seconds'] ?? 0),
            ],
            [],
            $criteria,
        );
    }

    private function monthlyTraining(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->betweenDates($criteria, 'e.enrolled_at')
            ->where('e.deleted_at IS NULL');

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT to_char(date_trunc('month', e.enrolled_at), 'YYYY-MM') AS period,
                        COUNT(*)                                       AS enrolled,
                        COUNT(*) FILTER (WHERE e.status = 'completed') AS completed,
                        COUNT(DISTINCT e.user_id)                      AS learners,
                        COALESCE(SUM(e.time_spent_seconds), 0)         AS seconds
                 FROM enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN courses c ON c.id = e.course_id
                 WHERE %s
                 GROUP BY 1
                 ORDER BY 1",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        return ReportResult::series(
            $definition,
            array_map(
                fn (array $r): array => [
                    'label'     => (string) $r['period'],
                    'value'     => (int) $r['enrolled'],
                    'completed' => (int) $r['completed'],
                    'learners'  => (int) $r['learners'],
                    'seconds'   => (int) $r['seconds'],
                    'completionRate' => $this->percent((int) $r['completed'], (int) $r['enrolled']),
                ],
                $rows,
            ),
            ['months' => count($rows)],
            $criteria,
        );
    }

    private function yearOverYear(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->where('e.deleted_at IS NULL')
            ->where("e.enrolled_at >= date_trunc('year', now()) - interval '1 year'");

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT EXTRACT(YEAR FROM e.enrolled_at)::int  AS year,
                        COUNT(*)                                       AS enrolled,
                        COUNT(*) FILTER (WHERE e.status = 'completed') AS completed,
                        COUNT(DISTINCT e.user_id)                      AS learners,
                        COALESCE(SUM(e.time_spent_seconds), 0)         AS seconds,
                        ROUND(AVG(e.final_score) FILTER (WHERE e.final_score IS NOT NULL), 1) AS avg_score
                 FROM enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN courses c ON c.id = e.course_id
                 WHERE %s
                 GROUP BY 1
                 ORDER BY 1",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        $byYear = [];

        foreach ($rows as $row) {
            $byYear[(int) $row['year']] = $row;
        }

        $thisYear = (int) date('Y');
        $lastYear = $thisYear - 1;

        $measures = [
            'Enrolments'      => 'enrolled',
            'Completions'     => 'completed',
            'Learners'        => 'learners',
            'Hours'           => 'seconds',
            'Average score'   => 'avg_score',
        ];

        $out = [];

        foreach ($measures as $label => $column) {
            $now  = (float) ($byYear[$thisYear][$column] ?? 0);
            $then = (float) ($byYear[$lastYear][$column] ?? 0);

            if ($column === 'seconds') {
                $now  = round($now / 3600, 1);
                $then = round($then / 3600, 1);
            }

            $out[] = [
                'measure'  => $label,
                'thisYear' => $now,
                'lastYear' => $then,
                'change'   => round($now - $then, 1),
                // Null rather than nought when there is nothing to compare
                // against: "no change" and "no previous year" are different
                // answers and a nought would read as the first.
                'changePercent' => $then === 0.0 ? null : round(100 * ($now - $then) / $then, 1),
            ];
        }

        return new ReportResult(
            definition: $definition,
            columns: [
                ReportColumn::text('measure', 'Measure'),
                ReportColumn::number('thisYear', (string) $thisYear),
                ReportColumn::number('lastYear', (string) $lastYear),
                ReportColumn::number('change', 'Change'),
                ReportColumn::percent('changePercent', 'Change %'),
            ],
            rows: $out,
            total: count($out),
            criteria: $criteria,
        );
    }

    // --- gamification -----------------------------------------------------

    private function leaderboard(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        // Points are events, not a stored total: a running total drifts the
        // first time an award is reversed. Summed here, and again everywhere
        // else that asks, so the two never disagree.
        $q = ReportQuery::tenant($criteria, 'u')
            ->withScopeFilters($criteria, 'u')
            ->where('u.deleted_at IS NULL');

        $columns = [
            ReportColumn::text('learner', 'Learner'),
            ReportColumn::text('department', 'Department'),
            ReportColumn::number('points', 'Points'),
            ReportColumn::number('pointsThisMonth', 'This month'),
            ReportColumn::number('badges', 'Badges'),
            ReportColumn::number('completed', 'Courses done'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            "FROM users u
             LEFT JOIN lookups d ON d.id = u.department_id",
            $q,
            "trim(u.first_name || ' ' || u.last_name) AS learner,
             d.name AS department,
             COALESCE((SELECT SUM(p.points) FROM point_events p WHERE p.user_id = u.id), 0) AS points,
             COALESCE((
                 SELECT SUM(p.points) FROM point_events p
                 WHERE p.user_id = u.id AND p.earned_at >= date_trunc('month', now())
             ), 0) AS \"pointsThisMonth\",
             (SELECT COUNT(*) FROM badge_awards b WHERE b.user_id = u.id) AS badges,
             (SELECT COUNT(*) FROM enrollments e
              WHERE e.user_id = u.id AND e.status = 'completed' AND e.deleted_at IS NULL) AS completed",
            'points',
            'u.id',
            'DESC',
        );
    }

    private function badgesEarned(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'ba', 'u')
            ->withScopeFilters($criteria, 'u')
            ->betweenDates($criteria, 'ba.awarded_at');

        $columns = [
            ReportColumn::text('learner', 'Learner'),
            ReportColumn::text('department', 'Department'),
            ReportColumn::text('badge', 'Badge'),
            ReportColumn::text('badgeType', 'Type'),
            ReportColumn::number('pointsValue', 'Points'),
            ReportColumn::date('awardedAt', 'Earned'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            "FROM badge_awards ba
             JOIN users u ON u.id = ba.user_id
             JOIN badges bg ON bg.id = ba.badge_id
             LEFT JOIN lookups d ON d.id = u.department_id",
            $q,
            "trim(u.first_name || ' ' || u.last_name) AS learner,
             d.name AS department,
             bg.name AS badge,
             bg.type AS \"badgeType\",
             bg.points_value AS \"pointsValue\",
             ba.awarded_at AS \"awardedAt\"",
            'ba.awarded_at',
            'ba.id',
        );
    }

    private function pointsSummary(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'u')
            ->withScopeFilters($criteria, 'u')
            ->where('u.deleted_at IS NULL')
            // Somebody who has never earned a point is not on a points report.
            ->where('EXISTS (SELECT 1 FROM point_events p WHERE p.user_id = u.id)');

        $columns = [
            ReportColumn::text('learner', 'Learner'),
            ReportColumn::text('department', 'Department'),
            ReportColumn::number('total', 'All time'),
            ReportColumn::number('thisMonth', 'This month'),
            ReportColumn::number('thisWeek', 'This week'),
            ReportColumn::number('awards', 'Awards'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            "FROM users u
             LEFT JOIN lookups d ON d.id = u.department_id",
            $q,
            "trim(u.first_name || ' ' || u.last_name) AS learner,
             d.name AS department,
             COALESCE((SELECT SUM(p.points) FROM point_events p WHERE p.user_id = u.id), 0) AS total,
             COALESCE((SELECT SUM(p.points) FROM point_events p
                 WHERE p.user_id = u.id AND p.earned_at >= date_trunc('month', now())), 0) AS \"thisMonth\",
             COALESCE((SELECT SUM(p.points) FROM point_events p
                 WHERE p.user_id = u.id AND p.earned_at >= date_trunc('week', now())), 0) AS \"thisWeek\",
             (SELECT COUNT(*) FROM point_events p WHERE p.user_id = u.id) AS awards",
            'total',
            'u.id',
            'DESC',
        );
    }

    private function departmentLeaderboard(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'u')
            ->withScopeFilters($criteria, 'u')
            ->where('u.deleted_at IS NULL')
            ->where('u.department_id IS NOT NULL');

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT d.name AS department,
                        COUNT(DISTINCT u.id) AS people,
                        COALESCE(SUM((
                            SELECT SUM(p.points) FROM point_events p WHERE p.user_id = u.id
                        )), 0) AS points,
                        COALESCE(SUM((
                            SELECT COUNT(*) FROM badge_awards b WHERE b.user_id = u.id
                        )), 0) AS badges
                 FROM users u
                 JOIN lookups d ON d.id = u.department_id
                 WHERE %s
                 GROUP BY d.name
                 ORDER BY points DESC",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        return ReportResult::series(
            $definition,
            array_map(
                fn (array $r): array => [
                    'label'         => (string) $r['department'],
                    'value'         => (int) $r['points'],
                    'people'        => (int) $r['people'],
                    'badges'        => (int) $r['badges'],
                    // The fair comparison between a big department and a small
                    // one: total points flatter the large team for its size.
                    'pointsPerHead' => $this->round(
                        (int) $r['people'] === 0 ? 0 : (int) $r['points'] / (int) $r['people'],
                    ),
                ],
                $rows,
            ),
            ['departments' => count($rows)],
            $criteria,
        );
    }

    private function gamificationParticipation(
        ReportDefinition $definition,
        ReportCriteria $criteria,
    ): ReportResult {
        $q = ReportQuery::tenant($criteria, 'u')
            ->withScopeFilters($criteria, 'u')
            ->where('u.deleted_at IS NULL');

        $totals = $this->connection->fetchAssociative(
            sprintf(
                "SELECT COUNT(*) AS people,
                        COUNT(*) FILTER (
                            WHERE EXISTS (SELECT 1 FROM point_events p WHERE p.user_id = u.id)
                               OR EXISTS (SELECT 1 FROM badge_awards b WHERE b.user_id = u.id)
                        ) AS taking_part,
                        COALESCE((SELECT SUM(p.points) FROM point_events p WHERE p.tenant_id = :tenantId), 0)
                            AS total_points,
                        (SELECT COUNT(*) FROM badge_awards b WHERE b.tenant_id = :tenantId) AS total_badges
                 FROM users u
                 WHERE %s",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        $totals = $totals === false ? [] : $totals;
        $people = (int) ($totals['people'] ?? 0);
        $takingPart = (int) ($totals['taking_part'] ?? 0);

        // Where the points come from — the reason column of the events, which
        // is the closest the schema has to "what earns points here".
        $bySource = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT p.reason AS label, SUM(p.points) AS value, COUNT(*) AS awards
                 FROM point_events p
                 JOIN users u ON u.id = p.user_id
                 WHERE %s
                 GROUP BY p.reason
                 ORDER BY value DESC',
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        return ReportResult::summary(
            $definition,
            [
                'people'            => $people,
                'takingPart'        => $takingPart,
                'participationRate' => $this->percent($takingPart, $people),
                'totalPoints'       => (int) ($totals['total_points'] ?? 0),
                'totalBadges'       => (int) ($totals['total_badges'] ?? 0),
            ],
            array_map(
                fn (array $r): array => [
                    'label'  => $this->humanise((string) $r['label']),
                    'value'  => (int) $r['value'],
                    'awards' => (int) $r['awards'],
                ],
                $bySource,
            ),
            $criteria,
        );
    }

    // --- learning paths ---------------------------------------------------

    private function pathEnrollment(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        // Counts derived on read: a path's enrolled/active/completed figures
        // change every time somebody finishes an item, and a stored total is
        // wrong from the first of those.
        $q = ReportQuery::tenant($criteria, 'p')
            ->where('p.deleted_at IS NULL')
            ->maybe($criteria->pathId, 'p.id = :pathId', 'pathId');

        $columns = [
            ReportColumn::text('path', 'Path'),
            ReportColumn::status('status', 'Status'),
            ReportColumn::number('enrolled', 'Enrolled'),
            ReportColumn::number('active', 'Active'),
            ReportColumn::number('completed', 'Completed'),
            ReportColumn::percent('completionRate', 'Completion rate'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM learning_paths p',
            $q,
            "p.title AS path,
             p.status AS status,
             (SELECT COUNT(*) FROM learning_path_enrollments e
              WHERE e.path_id = p.id AND e.deleted_at IS NULL) AS enrolled,
             (SELECT COUNT(*) FROM learning_path_enrollments e
              WHERE e.path_id = p.id AND e.deleted_at IS NULL AND e.status = 'active') AS active,
             (SELECT COUNT(*) FROM learning_path_enrollments e
              WHERE e.path_id = p.id AND e.deleted_at IS NULL AND e.status = 'completed') AS completed,
             CASE WHEN (SELECT COUNT(*) FROM learning_path_enrollments e
                        WHERE e.path_id = p.id AND e.deleted_at IS NULL) = 0 THEN 0
                  ELSE round(100.0 * (SELECT COUNT(*) FROM learning_path_enrollments e
                        WHERE e.path_id = p.id AND e.deleted_at IS NULL AND e.status = 'completed')
                        / (SELECT COUNT(*) FROM learning_path_enrollments e
                        WHERE e.path_id = p.id AND e.deleted_at IS NULL), 1)
             END AS \"completionRate\"",
            'enrolled',
            'p.id',
            'DESC',
        );
    }

    private function pathProgress(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $pathId = $criteria->pathId ?? '';

        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u')
            ->where('e.path_id = :pathId', 'pathId', $pathId)
            ->where('e.deleted_at IS NULL');

        $columns = [
            ReportColumn::text('learner', 'Learner'),
            ReportColumn::text('email', 'Email'),
            ReportColumn::status('status', 'Status'),
            ReportColumn::number('itemsDone', 'Items done'),
            ReportColumn::number('itemsTotal', 'Items'),
            ReportColumn::percent('progress', 'Progress'),
            ReportColumn::date('enrolledAt', 'Enrolled'),
            ReportColumn::date('completedAt', 'Completed'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            "FROM learning_path_enrollments e
             JOIN users u ON u.id = e.user_id",
            $q,
            "trim(u.first_name || ' ' || u.last_name) AS learner,
             u.email AS email,
             e.status AS status,
             (SELECT COUNT(*) FROM learning_path_item_completions c WHERE c.enrollment_id = e.id) AS \"itemsDone\",
             (SELECT COUNT(*) FROM learning_path_items i
              WHERE i.path_id = e.path_id AND i.deleted_at IS NULL) AS \"itemsTotal\",
             CASE WHEN (SELECT COUNT(*) FROM learning_path_items i
                        WHERE i.path_id = e.path_id AND i.deleted_at IS NULL) = 0 THEN 0
                  ELSE round(100.0 * (SELECT COUNT(*) FROM learning_path_item_completions c
                        WHERE c.enrollment_id = e.id)
                        / (SELECT COUNT(*) FROM learning_path_items i
                        WHERE i.path_id = e.path_id AND i.deleted_at IS NULL), 1)
             END AS progress,
             e.enrolled_at AS \"enrolledAt\",
             e.completed_at AS \"completedAt\"",
            'progress',
            'e.id',
            'DESC',
        );
    }

    private function pathCompletion(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'p')
            ->where('p.deleted_at IS NULL')
            ->maybe($criteria->pathId, 'p.id = :pathId', 'pathId');

        $columns = [
            ReportColumn::text('path', 'Path'),
            ReportColumn::number('enrolled', 'Enrolled'),
            ReportColumn::number('completed', 'Completed'),
            ReportColumn::percent('completionRate', 'Completion rate'),
            ReportColumn::number('avgDays', 'Avg days to finish'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM learning_paths p',
            $q,
            "p.title AS path,
             (SELECT COUNT(*) FROM learning_path_enrollments e
              WHERE e.path_id = p.id AND e.deleted_at IS NULL) AS enrolled,
             (SELECT COUNT(*) FROM learning_path_enrollments e
              WHERE e.path_id = p.id AND e.deleted_at IS NULL AND e.status = 'completed') AS completed,
             CASE WHEN (SELECT COUNT(*) FROM learning_path_enrollments e
                        WHERE e.path_id = p.id AND e.deleted_at IS NULL) = 0 THEN 0
                  ELSE round(100.0 * (SELECT COUNT(*) FROM learning_path_enrollments e
                        WHERE e.path_id = p.id AND e.deleted_at IS NULL AND e.status = 'completed')
                        / (SELECT COUNT(*) FROM learning_path_enrollments e
                        WHERE e.path_id = p.id AND e.deleted_at IS NULL), 1)
             END AS \"completionRate\",
             (SELECT round(AVG(EXTRACT(EPOCH FROM (e.completed_at - e.enrolled_at)) / 86400)::numeric, 1)
              FROM learning_path_enrollments e
              WHERE e.path_id = p.id AND e.deleted_at IS NULL AND e.completed_at IS NOT NULL) AS \"avgDays\"",
            'enrolled',
            'p.id',
            'DESC',
        );
    }

    private function pathDropOff(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $pathId = $criteria->pathId ?? '';

        $q = ReportQuery::tenant($criteria, 'i')
            ->where('i.path_id = :pathId', 'pathId', $pathId)
            ->where('i.deleted_at IS NULL');

        // How many active-or-finished learners reached each item versus the
        // whole cohort, so the item where the line falls off is the one to fix.
        $columns = [
            ReportColumn::number('position', 'Position'),
            ReportColumn::text('item', 'Item'),
            ReportColumn::number('reached', 'Reached it'),
            ReportColumn::number('cohort', 'On the path'),
            ReportColumn::percent('reachedRate', 'Reached rate'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            "FROM learning_path_items i",
            $q,
            "i.sort_order AS position,
             i.title AS item,
             (SELECT COUNT(*) FROM learning_path_item_completions c WHERE c.item_id = i.id) AS reached,
             (SELECT COUNT(*) FROM learning_path_enrollments e
              WHERE e.path_id = i.path_id AND e.deleted_at IS NULL) AS cohort,
             CASE WHEN (SELECT COUNT(*) FROM learning_path_enrollments e
                        WHERE e.path_id = i.path_id AND e.deleted_at IS NULL) = 0 THEN 0
                  ELSE round(100.0 * (SELECT COUNT(*) FROM learning_path_item_completions c WHERE c.item_id = i.id)
                        / (SELECT COUNT(*) FROM learning_path_enrollments e
                        WHERE e.path_id = i.path_id AND e.deleted_at IS NULL), 1)
             END AS \"reachedRate\"",
            'i.id, i.sort_order, i.title',
            'i.sort_order',
        );
    }

    private function pathEffectiveness(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'p')
            ->where('p.deleted_at IS NULL')
            ->maybe($criteria->pathId, 'p.id = :pathId', 'pathId');

        $columns = [
            ReportColumn::text('path', 'Path'),
            ReportColumn::number('items', 'Items'),
            ReportColumn::number('enrolled', 'Enrolled'),
            ReportColumn::percent('completionRate', 'Completion rate'),
            ReportColumn::percent('avgReach', 'Avg reached'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM learning_paths p',
            $q,
            "p.title AS path,
             (SELECT COUNT(*) FROM learning_path_items i
              WHERE i.path_id = p.id AND i.deleted_at IS NULL) AS items,
             (SELECT COUNT(*) FROM learning_path_enrollments e
              WHERE e.path_id = p.id AND e.deleted_at IS NULL) AS enrolled,
             CASE WHEN (SELECT COUNT(*) FROM learning_path_enrollments e
                        WHERE e.path_id = p.id AND e.deleted_at IS NULL) = 0 THEN 0
                  ELSE round(100.0 * (SELECT COUNT(*) FROM learning_path_enrollments e
                        WHERE e.path_id = p.id AND e.deleted_at IS NULL AND e.status = 'completed')
                        / (SELECT COUNT(*) FROM learning_path_enrollments e
                        WHERE e.path_id = p.id AND e.deleted_at IS NULL), 1)
             END AS \"completionRate\",
             -- How much of the path the average enrolment actually reaches,
             -- which tells apart a path people finish from one they abandon
             -- halfway: the same completion rate can hide either.
             COALESCE((
                 SELECT round(AVG(reach)::numeric, 1) FROM (
                     SELECT CASE WHEN items.total = 0 THEN 0
                                 ELSE 100.0 * (SELECT COUNT(*) FROM learning_path_item_completions c
                                               WHERE c.enrollment_id = e.id) / items.total
                            END AS reach
                     FROM learning_path_enrollments e
                     CROSS JOIN LATERAL (
                         SELECT COUNT(*) AS total FROM learning_path_items i
                         WHERE i.path_id = p.id AND i.deleted_at IS NULL
                     ) items
                     WHERE e.path_id = p.id AND e.deleted_at IS NULL
                 ) reaches
             ), 0) AS \"avgReach\"",
            'enrolled',
            'p.id',
            'DESC',
        );
    }

    // --- live training ----------------------------------------------------

    private function sessionAttendance(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 's')
            ->betweenDates($criteria, 's.starts_at')
            ->where('s.deleted_at IS NULL');

        $columns = [
            ReportColumn::text('session', 'Session'),
            ReportColumn::text('training', 'Programme'),
            ReportColumn::text('instructor', 'Lead'),
            ReportColumn::date('startsAt', 'When'),
            ReportColumn::number('marked', 'Marked'),
            ReportColumn::number('attended', 'Turned up'),
            ReportColumn::percent('attendanceRate', 'Attendance'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            "FROM training_sessions s
             JOIN live_trainings lt ON lt.id = s.training_id",
            $q,
            "s.title AS session,
             lt.title AS training,
             (SELECT trim(u.first_name || ' ' || u.last_name) FROM session_instructors si
              JOIN users u ON u.id = si.user_id
              WHERE si.session_id = s.id AND si.role = 'primary' LIMIT 1) AS instructor,
             s.starts_at AS \"startsAt\",
             (SELECT COUNT(*) FROM session_attendance a WHERE a.session_id = s.id) AS marked,
             (SELECT COUNT(*) FROM session_attendance a
              WHERE a.session_id = s.id AND a.status IN ('present', 'late')) AS attended,
             CASE WHEN (SELECT COUNT(*) FROM session_attendance a WHERE a.session_id = s.id) = 0 THEN 0
                  ELSE round(100.0 * (SELECT COUNT(*) FROM session_attendance a
                        WHERE a.session_id = s.id AND a.status IN ('present', 'late'))
                        / (SELECT COUNT(*) FROM session_attendance a WHERE a.session_id = s.id), 1)
             END AS \"attendanceRate\"",
            's.starts_at',
            's.id',
        );
    }

    private function noShow(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'a', 'u')
            ->withScopeFilters($criteria, 'u')
            ->betweenDates($criteria, 's.starts_at')
            ->where("a.status = 'absent'")
            ->where('s.deleted_at IS NULL');

        $columns = [
            ReportColumn::text('learner', 'Learner'),
            ReportColumn::text('department', 'Department'),
            ReportColumn::text('session', 'Session'),
            ReportColumn::text('training', 'Programme'),
            ReportColumn::date('startsAt', 'When'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            "FROM session_attendance a
             JOIN training_sessions s ON s.id = a.session_id
             JOIN live_trainings lt ON lt.id = s.training_id
             JOIN users u ON u.id = a.user_id
             LEFT JOIN lookups d ON d.id = u.department_id",
            $q,
            "trim(u.first_name || ' ' || u.last_name) AS learner,
             d.name AS department,
             s.title AS session,
             lt.title AS training,
             s.starts_at AS \"startsAt\"",
            's.starts_at',
            'a.id',
        );
    }

    private function sessionUtilization(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 's')
            ->betweenDates($criteria, 's.starts_at')
            ->where('s.deleted_at IS NULL')
            // A session with no capacity set has no utilisation to compute —
            // dividing by it would read as 0% for a full room.
            ->where('s.venue_capacity IS NOT NULL AND s.venue_capacity > 0');

        $columns = [
            ReportColumn::text('session', 'Session'),
            ReportColumn::text('venue', 'Venue'),
            ReportColumn::number('capacity', 'Capacity'),
            ReportColumn::number('attended', 'Turned up'),
            ReportColumn::percent('utilization', 'Utilisation'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM training_sessions s',
            $q,
            "s.title AS session,
             COALESCE(s.venue_name, 'Unnamed') AS venue,
             s.venue_capacity AS capacity,
             (SELECT COUNT(*) FROM session_attendance a
              WHERE a.session_id = s.id AND a.status IN ('present', 'late')) AS attended,
             round(100.0 * (SELECT COUNT(*) FROM session_attendance a
                    WHERE a.session_id = s.id AND a.status IN ('present', 'late'))
                    / s.venue_capacity, 1) AS utilization",
            's.starts_at',
            's.id',
        );
    }

    private function instructorEffectiveness(
        ReportDefinition $definition,
        ReportCriteria $criteria,
    ): ReportResult {
        $q = ReportQuery::tenant($criteria, 'si')
            ->where("si.role = 'primary'");

        $columns = [
            ReportColumn::text('instructor', 'Instructor'),
            ReportColumn::number('sessions', 'Sessions'),
            ReportColumn::number('attended', 'Total attended'),
            ReportColumn::percent('avgAttendance', 'Avg attendance'),
        ];

        // One row per lead instructor: how many sessions, how many turned up,
        // and the average attendance rate across their sessions.
        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            "FROM session_instructors si
             JOIN users u ON u.id = si.user_id
             JOIN training_sessions s ON s.id = si.session_id AND s.deleted_at IS NULL
             LEFT JOIN session_attendance a ON a.session_id = s.id",
            $q,
            "trim(u.first_name || ' ' || u.last_name) AS instructor,
             COUNT(DISTINCT s.id) AS sessions,
             COUNT(*) FILTER (WHERE a.status IN ('present', 'late')) AS attended,
             CASE WHEN COUNT(a.id) = 0 THEN 0
                  ELSE round(100.0 * COUNT(*) FILTER (WHERE a.status IN ('present', 'late'))
                        / COUNT(a.id), 1)
             END AS \"avgAttendance\"",
            'u.id, u.first_name, u.last_name',
            'sessions DESC',
        );
    }

    private function venueUtilization(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 's')
            ->betweenDates($criteria, 's.starts_at')
            ->where('s.deleted_at IS NULL')
            ->where('s.venue_name IS NOT NULL');

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT s.venue_name AS venue,
                        COUNT(*) AS sessions,
                        SUM(s.venue_capacity) AS seats,
                        SUM((SELECT COUNT(*) FROM session_attendance a
                             WHERE a.session_id = s.id AND a.status IN ('present', 'late'))) AS attended
                 FROM training_sessions s
                 WHERE %s
                 GROUP BY s.venue_name
                 ORDER BY sessions DESC",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        return ReportResult::series(
            $definition,
            array_map(
                fn (array $r): array => [
                    'label'       => (string) $r['venue'],
                    'value'       => (int) $r['sessions'],
                    'attended'    => (int) $r['attended'],
                    // Null, not zero, when no capacity is recorded: an unknown
                    // fill rate is not an empty room.
                    'utilization' => $r['seats'] === null || (int) $r['seats'] === 0
                        ? null
                        : $this->percent((int) $r['attended'], (int) $r['seats']),
                ],
                $rows,
            ),
            ['venues' => count($rows)],
            $criteria,
        );
    }

    // --- onboarding -------------------------------------------------------
    //
    // "Required training" is the mandatory enrolments, the same set compliance
    // reads; the new schema has no separate onboarding flag, and inventing one
    // out of nothing would be a second answer to "what must this person do".
    // A "new hire" is somebody with a hire date — the reports about current
    // joiners narrow to a recent window, the cohort reports do not.

    private function onboardingByCohort(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'u')
            ->withScopeFilters($criteria, 'u')
            ->betweenDates($criteria, 'u.hire_date')
            ->where('u.deleted_at IS NULL')
            ->where('u.hire_date IS NOT NULL');

        $columns = [
            ReportColumn::text('cohort', 'Started'),
            ReportColumn::number('joiners', 'Joiners'),
            ReportColumn::number('requirements', 'Requirements'),
            ReportColumn::number('completed', 'Completed'),
            ReportColumn::percent('completionRate', 'Completion rate'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            "FROM users u
             LEFT JOIN enrollments e ON e.user_id = u.id AND e.deleted_at IS NULL
                                    AND (e.is_mandatory OR EXISTS (
                                        SELECT 1 FROM courses c
                                        WHERE c.id = e.course_id AND c.is_mandatory))",
            $q,
            "to_char(date_trunc('month', u.hire_date), 'YYYY-MM') AS cohort,
             COUNT(DISTINCT u.id) AS joiners,
             COUNT(e.id) AS requirements,
             COUNT(e.id) FILTER (WHERE e.status = 'completed') AS completed,
             CASE WHEN COUNT(e.id) = 0 THEN 0
                  ELSE round(100.0 * COUNT(e.id) FILTER (WHERE e.status = 'completed')
                        / COUNT(e.id), 1)
             END AS \"completionRate\"",
            "date_trunc('month', u.hire_date)",
            'cohort DESC',
        );
    }

    private function timeToCompetency(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'u')
            ->withScopeFilters($criteria, 'u')
            ->where('u.deleted_at IS NULL')
            ->where('u.hire_date IS NOT NULL')
            // Only people who have actually finished all their required
            // training have a time-to-competency; the rest have not got there.
            ->where("NOT EXISTS (
                SELECT 1 FROM enrollments e
                JOIN courses c ON c.id = e.course_id
                WHERE e.user_id = u.id AND e.deleted_at IS NULL
                  AND (e.is_mandatory OR c.is_mandatory) AND e.status <> 'completed')")
            ->where("EXISTS (
                SELECT 1 FROM enrollments e
                JOIN courses c ON c.id = e.course_id
                WHERE e.user_id = u.id AND e.deleted_at IS NULL
                  AND (e.is_mandatory OR c.is_mandatory))");

        $columns = [
            ReportColumn::text('learner', 'Learner'),
            ReportColumn::text('department', 'Department'),
            ReportColumn::date('hireDate', 'Started'),
            ReportColumn::date('competentAt', 'Finished required'),
            ReportColumn::number('days', 'Days'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            "FROM users u
             LEFT JOIN lookups d ON d.id = u.department_id",
            $q,
            "trim(u.first_name || ' ' || u.last_name) AS learner,
             d.name AS department,
             u.hire_date AS \"hireDate\",
             (SELECT MAX(e.completed_at) FROM enrollments e
              JOIN courses c ON c.id = e.course_id
              WHERE e.user_id = u.id AND e.deleted_at IS NULL
                AND (e.is_mandatory OR c.is_mandatory)) AS \"competentAt\",
             (SELECT EXTRACT(DAY FROM MAX(e.completed_at) - u.hire_date)::int FROM enrollments e
              JOIN courses c ON c.id = e.course_id
              WHERE e.user_id = u.id AND e.deleted_at IS NULL
                AND (e.is_mandatory OR c.is_mandatory)) AS days",
            'days',
            'u.id',
        );
    }

    private function onboardingByOrg(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'u')
            ->withScopeFilters($criteria, 'u')
            ->where('u.deleted_at IS NULL')
            ->where('u.hire_date IS NOT NULL');

        $columns = [
            ReportColumn::text('department', 'Department'),
            ReportColumn::number('joiners', 'Joiners'),
            ReportColumn::number('requirements', 'Requirements'),
            ReportColumn::number('completed', 'Completed'),
            ReportColumn::percent('completionRate', 'Completion rate'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            "FROM users u
             LEFT JOIN lookups d ON d.id = u.department_id
             LEFT JOIN enrollments e ON e.user_id = u.id AND e.deleted_at IS NULL
                                    AND (e.is_mandatory OR EXISTS (
                                        SELECT 1 FROM courses c
                                        WHERE c.id = e.course_id AND c.is_mandatory))",
            $q,
            "COALESCE(d.name, 'Unassigned') AS department,
             COUNT(DISTINCT u.id) AS joiners,
             COUNT(e.id) AS requirements,
             COUNT(e.id) FILTER (WHERE e.status = 'completed') AS completed,
             CASE WHEN COUNT(e.id) = 0 THEN 0
                  ELSE round(100.0 * COUNT(e.id) FILTER (WHERE e.status = 'completed')
                        / COUNT(e.id), 1)
             END AS \"completionRate\"",
            'd.id, d.name',
            '"completionRate" ASC NULLS FIRST',
        );
    }

    private function newHireDashboard(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        // Recent joiners: hired inside the window, ninety days unless the
        // caller narrows it with a date range on the hire date.
        $q = ReportQuery::tenant($criteria, 'u')
            ->withScopeFilters($criteria, 'u')
            ->where('u.deleted_at IS NULL')
            ->where('u.hire_date IS NOT NULL')
            ->where("u.hire_date >= CURRENT_DATE - INTERVAL '90 days'");

        $row = $this->connection->fetchAssociative(
            sprintf(
                "SELECT COUNT(DISTINCT u.id) AS joiners,
                        COUNT(DISTINCT u.id) FILTER (WHERE NOT EXISTS (
                            SELECT 1 FROM enrollments e JOIN courses c ON c.id = e.course_id
                            WHERE e.user_id = u.id AND e.deleted_at IS NULL
                              AND (e.is_mandatory OR c.is_mandatory) AND e.status <> 'completed'
                        ) AND EXISTS (
                            SELECT 1 FROM enrollments e JOIN courses c ON c.id = e.course_id
                            WHERE e.user_id = u.id AND e.deleted_at IS NULL
                              AND (e.is_mandatory OR c.is_mandatory)
                        )) AS done,
                        COUNT(DISTINCT u.id) FILTER (WHERE EXISTS (
                            SELECT 1 FROM enrollments e JOIN courses c ON c.id = e.course_id
                            WHERE e.user_id = u.id AND e.deleted_at IS NULL
                              AND (e.is_mandatory OR c.is_mandatory)
                              AND e.status <> 'completed'
                              AND e.due_date IS NOT NULL AND e.due_date < CURRENT_DATE
                        )) AS overdue
                 FROM users u
                 WHERE %s",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        $row = $row === false ? [] : $row;
        $joiners = (int) ($row['joiners'] ?? 0);
        $done = (int) ($row['done'] ?? 0);

        return ReportResult::summary(
            $definition,
            [
                'joiners'      => $joiners,
                'onTrack'      => $joiners - (int) ($row['overdue'] ?? 0),
                'finished'     => $done,
                'overdue'      => (int) ($row['overdue'] ?? 0),
                'finishedRate' => $this->percent($done, $joiners),
            ],
            [],
            $criteria,
        );
    }

    private function onboardingDropOff(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->where('e.deleted_at IS NULL')
            ->where('u.hire_date IS NOT NULL')
            ->where(self::MANDATORY);

        $columns = [
            ReportColumn::text('course', 'Course'),
            ReportColumn::number('joiners', 'Joiners on it'),
            ReportColumn::number('completed', 'Finished'),
            ReportColumn::percent('completionRate', 'Completion rate'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            "FROM enrollments e
             JOIN users u ON u.id = e.user_id
             JOIN courses c ON c.id = e.course_id",
            $q,
            "c.title AS course,
             COUNT(DISTINCT e.user_id) AS joiners,
             COUNT(*) FILTER (WHERE e.status = 'completed') AS completed,
             round(100.0 * COUNT(*) FILTER (WHERE e.status = 'completed')
                   / NULLIF(COUNT(*), 0), 1) AS \"completionRate\"",
            'c.id, c.title',
            '"completionRate" ASC NULLS FIRST',
        );
    }

    private function probationCompliance(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'u')
            ->withScopeFilters($criteria, 'u')
            ->where('u.deleted_at IS NULL')
            ->where('u.probation_end_date IS NOT NULL');

        $columns = [
            ReportColumn::text('learner', 'Learner'),
            ReportColumn::text('department', 'Department'),
            ReportColumn::date('probationEnd', 'Probation ends'),
            ReportColumn::number('requirements', 'Requirements'),
            ReportColumn::number('completed', 'Completed'),
            ReportColumn::status('verdict', 'By probation'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            "FROM users u
             LEFT JOIN lookups d ON d.id = u.department_id",
            $q,
            "trim(u.first_name || ' ' || u.last_name) AS learner,
             d.name AS department,
             u.probation_end_date AS \"probationEnd\",
             (SELECT COUNT(*) FROM enrollments e JOIN courses c ON c.id = e.course_id
              WHERE e.user_id = u.id AND e.deleted_at IS NULL
                AND (e.is_mandatory OR c.is_mandatory)) AS requirements,
             (SELECT COUNT(*) FROM enrollments e JOIN courses c ON c.id = e.course_id
              WHERE e.user_id = u.id AND e.deleted_at IS NULL
                AND (e.is_mandatory OR c.is_mandatory)
                AND e.status = 'completed' AND e.completed_at <= u.probation_end_date) AS completed,
             CASE WHEN (SELECT COUNT(*) FROM enrollments e JOIN courses c ON c.id = e.course_id
                        WHERE e.user_id = u.id AND e.deleted_at IS NULL
                          AND (e.is_mandatory OR c.is_mandatory)
                          AND (e.status <> 'completed' OR e.completed_at > u.probation_end_date)) = 0
                  THEN 'On time' ELSE 'Missed' END AS verdict",
            'u.probation_end_date',
            'u.id',
            'ASC',
        );
    }

    // --- gaps in existing families ----------------------------------------

    private function selfEnrollment(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->betweenDates($criteria, 'e.enrolled_at')
            ->where('e.deleted_at IS NULL')
            ->where("e.source = 'self'");

        $columns = [
            ReportColumn::text('course', 'Course'),
            ReportColumn::number('selfEnrolments', 'Self-enrolments'),
            ReportColumn::number('people', 'People'),
            ReportColumn::number('completed', 'Completed'),
            ReportColumn::percent('completionRate', 'Completion rate'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            'FROM enrollments e
             JOIN users u ON u.id = e.user_id
             JOIN courses c ON c.id = e.course_id',
            $q,
            "c.title AS course,
             COUNT(*) AS \"selfEnrolments\",
             COUNT(DISTINCT e.user_id) AS people,
             COUNT(*) FILTER (WHERE e.status = 'completed') AS completed,
             round(100.0 * COUNT(*) FILTER (WHERE e.status = 'completed')
                   / NULLIF(COUNT(*), 0), 1) AS \"completionRate\"",
            'c.id, c.title',
            'completed DESC',
        );
    }

    private function incompleteCourses(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->where('e.deleted_at IS NULL')
            ->where("e.status NOT IN ('completed', 'dropped')");

        $columns = [
            ReportColumn::text('learner', 'Learner'),
            ReportColumn::text('department', 'Department'),
            ReportColumn::text('course', 'Course'),
            ReportColumn::percent('progress', 'Progress'),
            ReportColumn::date('dueDate', 'Due'),
            ReportColumn::number('daysOverdue', 'Days overdue'),
            ReportColumn::text('manager', 'Manager'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            "FROM enrollments e
             JOIN users u ON u.id = e.user_id
             JOIN courses c ON c.id = e.course_id
             LEFT JOIN lookups d ON d.id = u.department_id
             LEFT JOIN users m ON m.id = u.manager_id",
            $q,
            "trim(u.first_name || ' ' || u.last_name) AS learner,
             d.name AS department,
             c.title AS course,
             e.progress_percentage AS progress,
             e.due_date AS \"dueDate\",
             CASE WHEN e.due_date IS NOT NULL AND e.due_date < CURRENT_DATE
                  THEN (CURRENT_DATE - e.due_date) ELSE 0 END AS \"daysOverdue\",
             trim(m.first_name || ' ' || m.last_name) AS manager",
            'e.due_date',
            'e.id',
            'ASC',
        );
    }

    private function completionByManager(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->where('e.deleted_at IS NULL')
            ->where('u.manager_id IS NOT NULL');

        $columns = [
            ReportColumn::text('manager', 'Manager'),
            ReportColumn::number('teamSize', 'Team'),
            ReportColumn::number('enrolments', 'Enrolments'),
            ReportColumn::number('completed', 'Completed'),
            ReportColumn::percent('completionRate', 'Completion rate'),
            ReportColumn::number('overdue', 'Overdue'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            "FROM enrollments e
             JOIN users u ON u.id = e.user_id
             JOIN courses c ON c.id = e.course_id
             JOIN users m ON m.id = u.manager_id",
            $q,
            "trim(m.first_name || ' ' || m.last_name) AS manager,
             COUNT(DISTINCT u.id) AS \"teamSize\",
             COUNT(*) AS enrolments,
             COUNT(*) FILTER (WHERE e.status = 'completed') AS completed,
             round(100.0 * COUNT(*) FILTER (WHERE e.status = 'completed')
                   / NULLIF(COUNT(*), 0), 1) AS \"completionRate\",
             COUNT(*) FILTER (WHERE e.status <> 'completed'
                              AND e.due_date IS NOT NULL AND e.due_date < CURRENT_DATE) AS overdue",
            'm.id, m.first_name, m.last_name',
            '"completionRate" ASC NULLS FIRST',
        );
    }

    private function learningHoursByDepartment(
        ReportDefinition $definition,
        ReportCriteria $criteria,
    ): ReportResult {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->betweenDates($criteria, 'e.last_activity_at')
            ->where('e.deleted_at IS NULL')
            ->where('e.time_spent_seconds > 0');

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT COALESCE(d.name, 'Unassigned') AS label,
                        round(SUM(e.time_spent_seconds) / 3600.0, 1) AS hours,
                        COUNT(DISTINCT e.user_id) AS people
                 FROM enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN courses c ON c.id = e.course_id
                 LEFT JOIN lookups d ON d.id = u.department_id
                 WHERE %s
                 GROUP BY d.name
                 ORDER BY hours DESC",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        return ReportResult::series(
            $definition,
            array_map(
                fn (array $r): array => [
                    'label'        => (string) $r['label'],
                    'value'        => (float) $r['hours'],
                    'people'       => (int) $r['people'],
                    'hoursPerHead' => $this->round(
                        (int) $r['people'] === 0 ? 0 : (float) $r['hours'] / (int) $r['people'],
                    ),
                ],
                $rows,
            ),
            ['departments' => count($rows)],
            $criteria,
        );
    }

    private function monthlyTrainingHours(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->betweenDates($criteria, 'e.last_activity_at')
            ->where('e.deleted_at IS NULL')
            ->where('e.time_spent_seconds > 0')
            ->where('e.last_activity_at IS NOT NULL');

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT to_char(date_trunc('month', e.last_activity_at), 'YYYY-MM') AS label,
                        round(SUM(e.time_spent_seconds) / 3600.0, 1) AS hours,
                        COUNT(DISTINCT e.user_id) AS people
                 FROM enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN courses c ON c.id = e.course_id
                 WHERE %s
                 GROUP BY date_trunc('month', e.last_activity_at)
                 ORDER BY date_trunc('month', e.last_activity_at)",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        return ReportResult::series(
            $definition,
            array_map(
                static fn (array $r): array => [
                    'label'  => (string) $r['label'],
                    'value'  => (float) $r['hours'],
                    'people' => (int) $r['people'],
                ],
                $rows,
            ),
            ['months' => count($rows)],
            $criteria,
        );
    }

    private function certificatesByCourse(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'cert')
            ->betweenDates($criteria, 'cert.issued_at')
            ->where("cert.status = 'active'")
            ->where('cert.course_id IS NOT NULL')
            ->maybe($criteria->courseId, 'cert.course_id = :courseId', 'courseId');

        $columns = [
            ReportColumn::text('course', 'Course'),
            ReportColumn::number('issued', 'Certificates'),
            ReportColumn::number('holders', 'People'),
            ReportColumn::date('mostRecent', 'Latest'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            'FROM certificates cert
             JOIN courses c ON c.id = cert.course_id',
            $q,
            "c.title AS course,
             COUNT(*) AS issued,
             COUNT(DISTINCT cert.user_id) AS holders,
             MAX(cert.issued_at) AS \"mostRecent\"",
            'c.id, c.title',
            'issued DESC',
        );
    }

    /**
     * The people behind a figure, filtered by the status clicked through from.
     *
     * The leaf of a drilldown: a summary or breakdown row carries the org ids
     * and a status, and this returns the named people it stands for. One
     * report rather than one per status, because the shape is identical and
     * the only difference is the clause the status maps to.
     */
    private function drilldownLearners(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $status = (string) ($criteria->filters['status'] ?? 'all');

        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->where('e.deleted_at IS NULL');

        // The status names a clause rather than being interpolated: a value
        // from a clicked link is still request input.
        match ($status) {
            'completed'   => $q->where("e.status = 'completed'"),
            'in_progress' => $q->where("e.status = 'in_progress'"),
            'not_started' => $q->where("e.status = 'not_started'"),
            'overdue'     => $q->where("e.status <> 'completed'
                AND e.due_date IS NOT NULL AND e.due_date < CURRENT_DATE"),
            'dropped'     => $q->where("e.status = 'dropped'"),
            default       => $q,
        };

        $columns = [
            ReportColumn::text('learner', 'Learner'),
            ReportColumn::text('email', 'Email'),
            ReportColumn::text('department', 'Department'),
            ReportColumn::text('course', 'Course'),
            ReportColumn::status('status', 'Status'),
            ReportColumn::percent('progress', 'Progress'),
            ReportColumn::date('dueDate', 'Due'),
            ReportColumn::number('daysOverdue', 'Days overdue'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            "FROM enrollments e
             JOIN users u ON u.id = e.user_id
             JOIN courses c ON c.id = e.course_id
             LEFT JOIN lookups d ON d.id = u.department_id",
            $q,
            "trim(u.first_name || ' ' || u.last_name) AS learner,
             u.email AS email,
             d.name AS department,
             c.title AS course,
             e.status AS status,
             e.progress_percentage AS progress,
             e.due_date AS \"dueDate\",
             CASE WHEN e.due_date IS NOT NULL AND e.due_date < CURRENT_DATE
                  THEN (CURRENT_DATE - e.due_date) ELSE 0 END AS \"daysOverdue\"",
            'e.due_date',
            'e.id',
            'ASC',
        );
    }

    // --- for managers -----------------------------------------------------
    //
    // "The manager" is the caller unless they name another — an administrator
    // looking at somebody else's team. Defaulting to the caller is what makes
    // these usable by a team lead with no filter to fill in.

    private function managerTeamDashboard(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $manager = $this->managerId($criteria);

        $q = ReportQuery::tenant($criteria, 'u')
            ->where('u.deleted_at IS NULL')
            ->where('u.manager_id = :manager', 'manager', $manager);

        $columns = [
            ReportColumn::text('learner', 'Team member'),
            ReportColumn::number('enrolments', 'Courses'),
            ReportColumn::number('completed', 'Completed'),
            ReportColumn::number('inProgress', 'In progress'),
            ReportColumn::number('overdue', 'Overdue'),
            ReportColumn::percent('completionRate', 'Completion rate'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM users u',
            $q,
            "trim(u.first_name || ' ' || u.last_name) AS learner,
             (SELECT COUNT(*) FROM enrollments e WHERE e.user_id = u.id AND e.deleted_at IS NULL) AS enrolments,
             (SELECT COUNT(*) FROM enrollments e
              WHERE e.user_id = u.id AND e.deleted_at IS NULL AND e.status = 'completed') AS completed,
             (SELECT COUNT(*) FROM enrollments e
              WHERE e.user_id = u.id AND e.deleted_at IS NULL AND e.status = 'in_progress') AS \"inProgress\",
             (SELECT COUNT(*) FROM enrollments e
              WHERE e.user_id = u.id AND e.deleted_at IS NULL AND e.status <> 'completed'
                AND e.due_date IS NOT NULL AND e.due_date < CURRENT_DATE) AS overdue,
             CASE WHEN (SELECT COUNT(*) FROM enrollments e
                        WHERE e.user_id = u.id AND e.deleted_at IS NULL) = 0 THEN 0
                  ELSE round(100.0 * (SELECT COUNT(*) FROM enrollments e
                        WHERE e.user_id = u.id AND e.deleted_at IS NULL AND e.status = 'completed')
                        / (SELECT COUNT(*) FROM enrollments e
                        WHERE e.user_id = u.id AND e.deleted_at IS NULL), 1)
             END AS \"completionRate\"",
            'completed',
            'u.id',
            'DESC',
        );
    }

    private function managerComplianceSummary(
        ReportDefinition $definition,
        ReportCriteria $criteria,
    ): ReportResult {
        $manager = $this->managerId($criteria);

        $q = ReportQuery::tenant($criteria, 'e')
            ->where('e.deleted_at IS NULL')
            ->where(self::MANDATORY)
            ->where("e.status <> 'dropped'")
            ->where('u.manager_id = :manager', 'manager', $manager);

        $row = $this->connection->fetchAssociative(
            sprintf(
                "SELECT COUNT(DISTINCT u.id) AS people,
                        COUNT(*) AS requirements,
                        COUNT(*) FILTER (WHERE e.status = 'completed') AS completed,
                        COUNT(*) FILTER (WHERE e.status <> 'completed') AS pending,
                        COUNT(*) FILTER (WHERE e.status <> 'completed'
                            AND e.due_date IS NOT NULL AND e.due_date < CURRENT_DATE) AS overdue
                 FROM enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN courses c ON c.id = e.course_id
                 WHERE %s",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        $row = $row === false ? [] : $row;
        $requirements = (int) ($row['requirements'] ?? 0);
        $completed = (int) ($row['completed'] ?? 0);

        return ReportResult::summary(
            $definition,
            [
                'people'         => (int) ($row['people'] ?? 0),
                'requirements'   => $requirements,
                'completed'      => $completed,
                'pending'        => (int) ($row['pending'] ?? 0),
                'overdue'        => (int) ($row['overdue'] ?? 0),
                'complianceRate' => $this->percent($completed, $requirements),
            ],
            [],
            $criteria,
        );
    }

    private function managerActionRequired(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $manager = $this->managerId($criteria);

        $q = ReportQuery::tenant($criteria, 'e')
            ->where('e.deleted_at IS NULL')
            ->where("e.status <> 'completed' AND e.status <> 'dropped'")
            ->where('e.due_date IS NOT NULL AND e.due_date < CURRENT_DATE')
            ->where('u.manager_id = :manager', 'manager', $manager);

        $columns = [
            ReportColumn::text('learner', 'Team member'),
            ReportColumn::text('course', 'Course'),
            ReportColumn::date('dueDate', 'Was due'),
            ReportColumn::number('daysOverdue', 'Days overdue'),
            ReportColumn::percent('progress', 'Progress'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            "FROM enrollments e
             JOIN users u ON u.id = e.user_id
             JOIN courses c ON c.id = e.course_id",
            $q,
            "trim(u.first_name || ' ' || u.last_name) AS learner,
             c.title AS course,
             e.due_date AS \"dueDate\",
             (CURRENT_DATE - e.due_date) AS \"daysOverdue\",
             e.progress_percentage AS progress",
            'e.due_date',
            'e.id',
            'ASC',
        );
    }

    private function teamVsOrgBenchmark(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $manager = $this->managerId($criteria);

        // Two figures on the same measure: the team, and the whole tenant. The
        // org line uses the tenant only — a manager comparing against "their
        // scope" would be comparing against themselves.
        $row = $this->connection->fetchAssociative(
            "SELECT
                COUNT(*) FILTER (WHERE u.manager_id = :manager) AS team_total,
                COUNT(*) FILTER (WHERE u.manager_id = :manager AND e.status = 'completed') AS team_done,
                COUNT(*) AS org_total,
                COUNT(*) FILTER (WHERE e.status = 'completed') AS org_done
             FROM enrollments e
             JOIN users u ON u.id = e.user_id
             WHERE e.tenant_id = :tenant AND e.deleted_at IS NULL",
            ['manager' => $manager, 'tenant' => $criteria->scope->tenantId],
        );

        $row = $row === false ? [] : $row;
        $teamRate = $this->percent((int) ($row['team_done'] ?? 0), (int) ($row['team_total'] ?? 0));
        $orgRate = $this->percent((int) ($row['org_done'] ?? 0), (int) ($row['org_total'] ?? 0));

        return ReportResult::summary(
            $definition,
            [
                'teamCompletionRate' => $teamRate,
                'orgCompletionRate'  => $orgRate,
                'difference'         => round($teamRate - $orgRate, 1),
                'teamEnrolments'     => (int) ($row['team_total'] ?? 0),
                'orgEnrolments'      => (int) ($row['org_total'] ?? 0),
            ],
            [],
            $criteria,
        );
    }

    // --- cohorts and groups -----------------------------------------------

    private function cohortProgress(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->betweenDates($criteria, 'e.enrolled_at')
            ->where('e.deleted_at IS NULL');

        $columns = [
            ReportColumn::text('cohort', 'Enrolled'),
            ReportColumn::number('learners', 'Learners'),
            ReportColumn::number('enrolments', 'Enrolments'),
            ReportColumn::number('completed', 'Completed'),
            ReportColumn::percent('completionRate', 'Completion rate'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            'FROM enrollments e
             JOIN users u ON u.id = e.user_id
             JOIN courses c ON c.id = e.course_id',
            $q,
            "to_char(date_trunc('month', e.enrolled_at), 'YYYY-MM') AS cohort,
             COUNT(DISTINCT e.user_id) AS learners,
             COUNT(*) AS enrolments,
             COUNT(*) FILTER (WHERE e.status = 'completed') AS completed,
             round(100.0 * COUNT(*) FILTER (WHERE e.status = 'completed')
                   / NULLIF(COUNT(*), 0), 1) AS \"completionRate\"",
            "date_trunc('month', e.enrolled_at)",
            'cohort DESC',
        );
    }

    private function groupPerformance(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->where('e.deleted_at IS NULL');

        $columns = [
            ReportColumn::text('department', 'Department'),
            ReportColumn::number('people', 'People'),
            ReportColumn::number('enrolments', 'Enrolments'),
            ReportColumn::percent('completionRate', 'Completion rate'),
            ReportColumn::number('avgScore', 'Avg score'),
        ];

        return $this->grouped(
            $definition,
            $criteria,
            $columns,
            "FROM enrollments e
             JOIN users u ON u.id = e.user_id
             JOIN courses c ON c.id = e.course_id
             LEFT JOIN lookups d ON d.id = u.department_id",
            $q,
            "COALESCE(d.name, 'Unassigned') AS department,
             COUNT(DISTINCT e.user_id) AS people,
             COUNT(*) AS enrolments,
             round(100.0 * COUNT(*) FILTER (WHERE e.status = 'completed')
                   / NULLIF(COUNT(*), 0), 1) AS \"completionRate\",
             round((SELECT AVG(a.percentage) FROM attempts a
                    JOIN users uu ON uu.id = a.user_id
                    WHERE uu.department_id = d.id AND a.percentage IS NOT NULL)::numeric, 1) AS \"avgScore\"",
            'd.id, d.name',
            '"completionRate" DESC NULLS LAST',
        );
    }

    private function peerBenchmarking(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $learnerId = $criteria->learnerId ?? '';

        // The learner and their peers are their department. Their figures set
        // against the department average, so "how am I doing" has a denominator.
        $row = $this->connection->fetchAssociative(
            "WITH peers AS (
                SELECT u.id FROM users u
                WHERE u.tenant_id = :tenant AND u.deleted_at IS NULL
                  AND u.department_id = (SELECT department_id FROM users WHERE id = :learner)
            )
            SELECT
                COUNT(*) FILTER (WHERE e.user_id = :learner) AS my_total,
                COUNT(*) FILTER (WHERE e.user_id = :learner AND e.status = 'completed') AS my_done,
                COUNT(*) AS peer_total,
                COUNT(*) FILTER (WHERE e.status = 'completed') AS peer_done,
                COUNT(DISTINCT e.user_id) AS peer_count
             FROM enrollments e
             WHERE e.user_id IN (SELECT id FROM peers) AND e.deleted_at IS NULL",
            ['tenant' => $criteria->scope->tenantId, 'learner' => $learnerId],
        );

        $row = $row === false ? [] : $row;

        return ReportResult::summary(
            $definition,
            [
                'myCompletionRate'   => $this->percent((int) ($row['my_done'] ?? 0), (int) ($row['my_total'] ?? 0)),
                'peerCompletionRate' => $this->percent((int) ($row['peer_done'] ?? 0), (int) ($row['peer_total'] ?? 0)),
                'peers'              => max(0, (int) ($row['peer_count'] ?? 0) - 1),
                'myEnrolments'       => (int) ($row['my_total'] ?? 0),
            ],
            [],
            $criteria,
        );
    }

    /**
     * The manager these "for managers" reports are about.
     *
     * The person named, or the caller. A team lead running one has no filter
     * to fill in and gets their own team; an administrator names somebody
     * else's.
     */
    private function managerId(ReportCriteria $criteria): string
    {
        $named = trim((string) ($criteria->filters['managerId'] ?? ''));

        return $named !== '' ? $named : $criteria->scope->viewerId;
    }

    // --- audit and records ------------------------------------------------

    private function fullAuditTrail(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'a')
            ->betweenDates($criteria, 'a.created_at');

        $columns = [
            ReportColumn::date('at', 'When'),
            ReportColumn::text('actor', 'Who'),
            ReportColumn::text('action', 'Action'),
            ReportColumn::text('method', 'Method'),
            ReportColumn::text('path', 'Path'),
            ReportColumn::text('status', 'Result'),
            ReportColumn::text('ip', 'From'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM audit_events a',
            $q,
            "a.created_at AS at,
             COALESCE(a.actor_email, 'system') AS actor,
             a.action AS action,
             a.method AS method,
             a.path AS path,
             a.status::text AS status,
             a.ip_address::text AS ip",
            'a.created_at',
            'a.id',
        );
    }

    private function dataRetention(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        // What is held, and how much of it has aged — the question a retention
        // policy is answered against. One row per kind of record.
        $tenant = $criteria->scope->tenantId;
        $params = ['tenant' => $tenant];

        $kinds = [
            'Users'          => 'users',
            'Enrolments'     => 'enrollments',
            'Assessment attempts' => 'attempts',
            'Certificates'   => 'certificates',
            'Audit events'   => 'audit_events',
        ];

        $rows = [];

        foreach ($kinds as $label => $table) {
            $row = $this->connection->fetchAssociative(
                sprintf(
                    "SELECT COUNT(*) AS total,
                            COUNT(*) FILTER (WHERE created_at < now() - INTERVAL '1 year') AS over_one_year,
                            COUNT(*) FILTER (WHERE created_at < now() - INTERVAL '2 years') AS over_two_years,
                            MIN(created_at) AS oldest
                     FROM %s WHERE tenant_id = :tenant",
                    $table,
                ),
                $params,
            );

            $row = $row === false ? [] : $row;
            $rows[] = [
                'record'      => $label,
                'total'       => (int) ($row['total'] ?? 0),
                'overOneYear' => (int) ($row['over_one_year'] ?? 0),
                'overTwoYears' => (int) ($row['over_two_years'] ?? 0),
                'oldest'      => $row['oldest'] ?? null,
            ];
        }

        return ReportResult::table(
            $definition,
            [
                ReportColumn::text('record', 'Record'),
                ReportColumn::number('total', 'Held'),
                ReportColumn::number('overOneYear', 'Over a year'),
                ReportColumn::number('overTwoYears', 'Over two years'),
                ReportColumn::date('oldest', 'Oldest'),
            ],
            $rows,
            count($rows),
            $criteria,
        );
    }

    private function trainingRecordsExport(
        ReportDefinition $definition,
        ReportCriteria $criteria,
    ): ReportResult {
        $learnerId = $criteria->learnerId ?? '';
        $tenant = $criteria->scope->tenantId;
        $params = ['tenant' => $tenant, 'user' => $learnerId];

        // One person's whole record, gathered into named sections — the shape
        // an HR request or a subject-access request comes in.
        $person = $this->connection->fetchAssociative(
            "SELECT trim(first_name || ' ' || last_name) AS name, email, hire_date
             FROM users WHERE id = :user AND tenant_id = :tenant",
            $params,
        );

        $enrolments = $this->connection->fetchAllAssociative(
            "SELECT c.title AS course, e.status, e.progress_percentage AS progress,
                    e.enrolled_at AS \"enrolledAt\", e.completed_at AS \"completedAt\"
             FROM enrollments e JOIN courses c ON c.id = e.course_id
             WHERE e.user_id = :user AND e.tenant_id = :tenant AND e.deleted_at IS NULL
             ORDER BY e.enrolled_at DESC",
            $params,
        );

        $certificates = $this->connection->fetchAllAssociative(
            "SELECT number, title, issued_at AS \"issuedAt\", valid_until AS \"validUntil\", status
             FROM certificates WHERE user_id = :user AND tenant_id = :tenant
             ORDER BY issued_at DESC",
            $params,
        );

        $attempts = $this->connection->fetchAllAssociative(
            "SELECT asm.title AS assessment, at.percentage, at.passed, at.submitted_at AS \"submittedAt\"
             FROM attempts at JOIN assessments asm ON asm.id = at.assessment_id
             WHERE at.user_id = :user AND at.tenant_id = :tenant
             ORDER BY at.submitted_at DESC",
            $params,
        );

        return (new ReportResult(
            definition: $definition,
            totals: [
                'name'      => $person === false ? null : (string) $person['name'],
                'email'     => $person === false ? null : (string) $person['email'],
                'hireDate'  => $person === false ? null : ($person['hire_date'] ?? null),
                'enrolments'   => $this->normalise($enrolments),
                'certificates' => $this->normalise($certificates),
                'attempts'     => $this->normalise($attempts),
            ],
            criteria: $criteria,
        ));
    }

    // --- system -----------------------------------------------------------

    private function systemUsage(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $tenant = $criteria->scope->tenantId;
        $params = ['tenant' => $tenant];

        $users = $this->connection->fetchAssociative(
            "SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE status = 'active') AS active,
                    COUNT(*) FILTER (WHERE last_login_at >= now() - INTERVAL '7 days') AS active_week,
                    COUNT(*) FILTER (WHERE last_login_at >= now() - INTERVAL '30 days') AS active_month
             FROM users WHERE tenant_id = :tenant AND deleted_at IS NULL",
            $params,
        );

        $content = $this->connection->fetchAssociative(
            "SELECT (SELECT COUNT(*) FROM courses WHERE tenant_id = :tenant AND deleted_at IS NULL) AS courses,
                    (SELECT COUNT(*) FROM live_trainings WHERE tenant_id = :tenant AND deleted_at IS NULL)
                        AS trainings,
                    (SELECT COUNT(*) FROM learning_paths WHERE tenant_id = :tenant AND deleted_at IS NULL) AS paths,
                    (SELECT COUNT(*) FROM assessments WHERE tenant_id = :tenant AND deleted_at IS NULL)
                        AS assessments,
                    (SELECT COUNT(*) FROM media WHERE tenant_id = :tenant AND deleted_at IS NULL) AS media",
            $params,
        );

        $users = $users === false ? [] : $users;
        $content = $content === false ? [] : $content;

        return ReportResult::summary(
            $definition,
            [
                'users'          => (int) ($users['total'] ?? 0),
                'activeUsers'    => (int) ($users['active'] ?? 0),
                'activeThisWeek' => (int) ($users['active_week'] ?? 0),
                'activeThisMonth' => (int) ($users['active_month'] ?? 0),
                'courses'        => (int) ($content['courses'] ?? 0),
                'trainings'      => (int) ($content['trainings'] ?? 0),
                'paths'          => (int) ($content['paths'] ?? 0),
                'assessments'    => (int) ($content['assessments'] ?? 0),
                'mediaFiles'     => (int) ($content['media'] ?? 0),
            ],
            [],
            $criteria,
        );
    }

    private function storageUsage(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT COALESCE(NULLIF(split_part(mime_type, '/', 1), ''), 'other') AS kind,
                    COUNT(*) AS files,
                    COALESCE(SUM(file_size), 0) AS bytes
             FROM media
             WHERE tenant_id = :tenant AND deleted_at IS NULL
             GROUP BY split_part(mime_type, '/', 1)
             ORDER BY bytes DESC",
            ['tenant' => $criteria->scope->tenantId],
        );

        return ReportResult::series(
            $definition,
            array_map(
                static fn (array $r): array => [
                    'label'      => ucfirst((string) $r['kind']),
                    'value'      => (int) $r['bytes'],
                    'files'      => (int) $r['files'],
                    'megabytes'  => round((int) $r['bytes'] / 1048576, 1),
                ],
                $rows,
            ),
            ['kinds' => count($rows)],
            $criteria,
        );
    }

    // --- my learning (self-service) ---------------------------------------
    //
    // Every one of these is locked to the caller's own id, whatever scope they
    // run under: a learner reading about themselves cannot be handed a filter
    // that widens to somebody else. `:self` is the viewer, always.

    private function myLearningSummary(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $self = $criteria->scope->viewerId;
        $params = ['self' => $self, 'tenant' => $criteria->scope->tenantId];

        $row = $this->connection->fetchAssociative(
            "SELECT
                (SELECT COUNT(*) FROM enrollments e
                 WHERE e.user_id = :self AND e.tenant_id = :tenant AND e.deleted_at IS NULL) AS enrolled,
                (SELECT COUNT(*) FROM enrollments e
                 WHERE e.user_id = :self AND e.tenant_id = :tenant AND e.deleted_at IS NULL
                   AND e.status = 'completed') AS completed,
                (SELECT COUNT(*) FROM enrollments e
                 WHERE e.user_id = :self AND e.tenant_id = :tenant AND e.deleted_at IS NULL
                   AND e.status = 'in_progress') AS in_progress,
                (SELECT COUNT(*) FROM certificates
                 WHERE user_id = :self AND tenant_id = :tenant AND status = 'active') AS certificates,
                (SELECT COALESCE(SUM(points), 0) FROM point_events
                 WHERE user_id = :self AND tenant_id = :tenant) AS points,
                (SELECT COUNT(*) FROM badge_awards
                 WHERE user_id = :self AND tenant_id = :tenant) AS badges",
            $params,
        );

        $row = $row === false ? [] : $row;

        return ReportResult::summary(
            $definition,
            [
                'enrolled'     => (int) ($row['enrolled'] ?? 0),
                'completed'    => (int) ($row['completed'] ?? 0),
                'inProgress'   => (int) ($row['in_progress'] ?? 0),
                'certificates' => (int) ($row['certificates'] ?? 0),
                'points'       => (int) ($row['points'] ?? 0),
                'badges'       => (int) ($row['badges'] ?? 0),
            ],
            [],
            $criteria,
        );
    }

    private function myCourses(ReportDefinition $definition, ReportCriteria $criteria, string $filter): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e')
            ->where('e.deleted_at IS NULL')
            ->where('e.user_id = :self', 'self', $criteria->scope->viewerId);

        match ($filter) {
            'completed'   => $q->where("e.status = 'completed'"),
            'in_progress' => $q->where("e.status = 'in_progress'"),
            'overdue'     => $q->where("e.status <> 'completed' AND e.status <> 'dropped'
                AND e.due_date IS NOT NULL AND e.due_date < CURRENT_DATE"),
            default       => $q,
        };

        $columns = [
            ReportColumn::text('course', 'Course'),
            ReportColumn::status('status', 'Status'),
            ReportColumn::percent('progress', 'Progress'),
            ReportColumn::date('dueDate', 'Due'),
            ReportColumn::date('completedAt', 'Completed'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM enrollments e JOIN courses c ON c.id = e.course_id',
            $q,
            "c.title AS course,
             e.status AS status,
             e.progress_percentage AS progress,
             e.due_date AS \"dueDate\",
             e.completed_at AS \"completedAt\"",
            'e.enrolled_at',
            'e.id',
        );
    }

    private function myCertificates(
        ReportDefinition $definition,
        ReportCriteria $criteria,
        bool $expiringOnly,
    ): ReportResult {
        $q = ReportQuery::tenant($criteria, 'cert')
            ->where('cert.user_id = :self', 'self', $criteria->scope->viewerId)
            ->where("cert.status = 'active'");

        if ($expiringOnly) {
            $q->where("cert.valid_until IS NOT NULL
                       AND cert.valid_until <= CURRENT_DATE + INTERVAL '90 days'");
        }

        $columns = [
            ReportColumn::text('certificate', 'Certificate'),
            ReportColumn::text('title', 'For'),
            ReportColumn::date('issuedAt', 'Issued'),
            ReportColumn::date('validUntil', 'Expires'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM certificates cert',
            $q,
            "cert.number AS certificate,
             cert.title AS title,
             cert.issued_at AS \"issuedAt\",
             cert.valid_until AS \"validUntil\"",
            $expiringOnly ? 'cert.valid_until' : 'cert.issued_at',
            'cert.id',
            $expiringOnly ? 'ASC' : 'DESC',
        );
    }

    private function myBadges(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'ba')
            ->where('ba.user_id = :self', 'self', $criteria->scope->viewerId);

        $columns = [
            ReportColumn::text('badge', 'Badge'),
            ReportColumn::text('type', 'Type'),
            ReportColumn::number('points', 'Points'),
            ReportColumn::date('earnedAt', 'Earned'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM badge_awards ba JOIN badges bg ON bg.id = ba.badge_id',
            $q,
            "bg.name AS badge,
             bg.type AS type,
             bg.points_value AS points,
             ba.awarded_at AS \"earnedAt\"",
            'ba.awarded_at',
            'ba.id',
        );
    }

    private function myPointsSummary(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $self = $criteria->scope->viewerId;
        $tenant = $criteria->scope->tenantId;

        $total = (int) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(points), 0) FROM point_events WHERE user_id = :self AND tenant_id = :tenant',
            ['self' => $self, 'tenant' => $tenant],
        );

        $byReason = $this->connection->fetchAllAssociative(
            'SELECT reason AS label, SUM(points) AS value, COUNT(*) AS awards
             FROM point_events WHERE user_id = :self AND tenant_id = :tenant
             GROUP BY reason ORDER BY value DESC',
            ['self' => $self, 'tenant' => $tenant],
        );

        // Where the caller stands: how many people in the tenant have more.
        // Nought when they have earned nothing — an unranked person is not
        // first, and a fresh account is not top of an empty board.
        $rank = $total === 0 ? 0 : (int) $this->connection->fetchOne(
            "SELECT COUNT(*) + 1 FROM (
                SELECT user_id, SUM(points) AS pts FROM point_events
                WHERE tenant_id = :tenant GROUP BY user_id HAVING SUM(points) > :total
            ) ahead",
            ['tenant' => $tenant, 'total' => $total],
        );

        return ReportResult::summary(
            $definition,
            ['total' => $total, 'rank' => $rank],
            array_map(
                fn (array $r): array => [
                    'label'  => $this->humanise((string) $r['label']),
                    'value'  => (int) $r['value'],
                    'awards' => (int) $r['awards'],
                ],
                $byReason,
            ),
            $criteria,
        );
    }

    private function myAssessmentResults(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'at')
            ->where('at.user_id = :self', 'self', $criteria->scope->viewerId)
            ->where("at.status = 'graded'");

        $columns = [
            ReportColumn::text('assessment', 'Assessment'),
            ReportColumn::percent('score', 'Score'),
            ReportColumn::status('verdict', 'Result'),
            ReportColumn::date('submittedAt', 'Sat'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM attempts at JOIN assessments asm ON asm.id = at.assessment_id',
            $q,
            "asm.title AS assessment,
             at.percentage AS score,
             CASE WHEN at.passed THEN 'Passed' ELSE 'Failed' END AS verdict,
             at.submitted_at AS \"submittedAt\"",
            'at.submitted_at',
            'at.id',
        );
    }

    private function myLearningPaths(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e')
            ->where('e.deleted_at IS NULL')
            ->where('e.user_id = :self', 'self', $criteria->scope->viewerId);

        $columns = [
            ReportColumn::text('path', 'Path'),
            ReportColumn::status('status', 'Status'),
            ReportColumn::number('itemsDone', 'Items done'),
            ReportColumn::number('itemsTotal', 'Items'),
            ReportColumn::percent('progress', 'Progress'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM learning_path_enrollments e JOIN learning_paths p ON p.id = e.path_id',
            $q,
            "p.title AS path,
             e.status AS status,
             (SELECT COUNT(*) FROM learning_path_item_completions c WHERE c.enrollment_id = e.id) AS \"itemsDone\",
             (SELECT COUNT(*) FROM learning_path_items i
              WHERE i.path_id = e.path_id AND i.deleted_at IS NULL) AS \"itemsTotal\",
             CASE WHEN (SELECT COUNT(*) FROM learning_path_items i
                        WHERE i.path_id = e.path_id AND i.deleted_at IS NULL) = 0 THEN 0
                  ELSE round(100.0 * (SELECT COUNT(*) FROM learning_path_item_completions c
                        WHERE c.enrollment_id = e.id)
                        / (SELECT COUNT(*) FROM learning_path_items i
                        WHERE i.path_id = e.path_id AND i.deleted_at IS NULL), 1)
             END AS progress",
            'e.enrolled_at',
            'e.id',
        );
    }

    private function myComplianceStatus(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $self = $criteria->scope->viewerId;

        $row = $this->connection->fetchAssociative(
            "SELECT COUNT(*) AS requirements,
                    COUNT(*) FILTER (WHERE e.status = 'completed') AS completed,
                    COUNT(*) FILTER (WHERE e.status <> 'completed') AS pending,
                    COUNT(*) FILTER (WHERE e.status <> 'completed'
                        AND e.due_date IS NOT NULL AND e.due_date < CURRENT_DATE) AS overdue
             FROM enrollments e JOIN courses c ON c.id = e.course_id
             WHERE e.user_id = :self AND e.tenant_id = :tenant AND e.deleted_at IS NULL
               AND (e.is_mandatory OR c.is_mandatory) AND e.status <> 'dropped'",
            ['self' => $self, 'tenant' => $criteria->scope->tenantId],
        );

        $row = $row === false ? [] : $row;
        $requirements = (int) ($row['requirements'] ?? 0);
        $completed = (int) ($row['completed'] ?? 0);

        return ReportResult::summary(
            $definition,
            [
                'requirements'   => $requirements,
                'completed'      => $completed,
                'pending'        => (int) ($row['pending'] ?? 0),
                'overdue'        => (int) ($row['overdue'] ?? 0),
                'complianceRate' => $this->percent($completed, $requirements),
            ],
            [],
            $criteria,
        );
    }

    private function myLearningTime(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $self = $criteria->scope->viewerId;

        $total = (int) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(time_spent_seconds), 0) FROM enrollments
             WHERE user_id = :self AND tenant_id = :tenant AND deleted_at IS NULL',
            ['self' => $self, 'tenant' => $criteria->scope->tenantId],
        );

        $byCourse = $this->connection->fetchAllAssociative(
            "SELECT c.title AS label, e.time_spent_seconds AS value
             FROM enrollments e JOIN courses c ON c.id = e.course_id
             WHERE e.user_id = :self AND e.tenant_id = :tenant AND e.deleted_at IS NULL
               AND e.time_spent_seconds > 0
             ORDER BY e.time_spent_seconds DESC
             LIMIT 20",
            ['self' => $self, 'tenant' => $criteria->scope->tenantId],
        );

        return ReportResult::summary(
            $definition,
            [
                'totalSeconds' => $total,
                'totalHours'   => round($total / 3600, 1),
            ],
            $this->normalise($byCourse),
            $criteria,
        );
    }

    private function myTranscript(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $self = $criteria->scope->viewerId;
        $params = ['self' => $self, 'tenant' => $criteria->scope->tenantId];

        $courses = $this->connection->fetchAllAssociative(
            "SELECT c.title AS course, e.status, e.completed_at AS \"completedAt\"
             FROM enrollments e JOIN courses c ON c.id = e.course_id
             WHERE e.user_id = :self AND e.tenant_id = :tenant AND e.deleted_at IS NULL
               AND e.status = 'completed'
             ORDER BY e.completed_at DESC",
            $params,
        );

        $certificates = $this->connection->fetchAllAssociative(
            "SELECT number, title, issued_at AS \"issuedAt\", valid_until AS \"validUntil\"
             FROM certificates WHERE user_id = :self AND tenant_id = :tenant AND status = 'active'
             ORDER BY issued_at DESC",
            $params,
        );

        $assessments = $this->connection->fetchAllAssociative(
            "SELECT asm.title AS assessment, at.percentage, at.passed
             FROM attempts at JOIN assessments asm ON asm.id = at.assessment_id
             WHERE at.user_id = :self AND at.tenant_id = :tenant AND at.status = 'graded'
             ORDER BY at.submitted_at DESC",
            $params,
        );

        return (new ReportResult(
            definition: $definition,
            totals: [
                'completedCourses' => $this->normalise($courses),
                'certificates'     => $this->normalise($certificates),
                'assessments'      => $this->normalise($assessments),
            ],
            criteria: $criteria,
        ));
    }

    private function myQuizHistory(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'at')
            ->where('at.user_id = :self', 'self', $criteria->scope->viewerId)
            ->where("at.status = 'graded'");

        $columns = [
            ReportColumn::text('assessment', 'Assessment'),
            ReportColumn::number('attempt', 'Attempt'),
            ReportColumn::percent('score', 'Score'),
            ReportColumn::status('verdict', 'Result'),
            ReportColumn::date('submittedAt', 'Sat'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM attempts at JOIN assessments asm ON asm.id = at.assessment_id',
            $q,
            "asm.title AS assessment,
             at.attempt_number AS attempt,
             at.percentage AS score,
             CASE WHEN at.passed THEN 'Passed' ELSE 'Failed' END AS verdict,
             at.submitted_at AS \"submittedAt\"",
            'at.submitted_at',
            'at.id',
        );
    }

    private function myMandatoryTraining(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e')
            ->where('e.deleted_at IS NULL')
            ->where('e.user_id = :self', 'self', $criteria->scope->viewerId)
            ->where(self::MANDATORY);

        $columns = [
            ReportColumn::text('course', 'Course'),
            ReportColumn::status('status', 'Status'),
            ReportColumn::percent('progress', 'Progress'),
            ReportColumn::date('dueDate', 'Due'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM enrollments e JOIN courses c ON c.id = e.course_id',
            $q,
            "c.title AS course,
             e.status AS status,
             e.progress_percentage AS progress,
             e.due_date AS \"dueDate\"",
            'e.due_date',
            'e.id',
            'ASC',
        );
    }

    private function myUpcomingDeadlines(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'e')
            ->where('e.deleted_at IS NULL')
            ->where('e.user_id = :self', 'self', $criteria->scope->viewerId)
            ->where("e.status <> 'completed' AND e.status <> 'dropped'")
            ->where('e.due_date IS NOT NULL AND e.due_date >= CURRENT_DATE');

        $columns = [
            ReportColumn::text('course', 'Course'),
            ReportColumn::date('dueDate', 'Due'),
            ReportColumn::number('daysLeft', 'Days left'),
            ReportColumn::percent('progress', 'Progress'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            'FROM enrollments e JOIN courses c ON c.id = e.course_id',
            $q,
            "c.title AS course,
             e.due_date AS \"dueDate\",
             (e.due_date - CURRENT_DATE) AS \"daysLeft\",
             e.progress_percentage AS progress",
            'e.due_date',
            'e.id',
            'ASC',
        );
    }

    private function myPathProgress(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $pathId = $criteria->pathId ?? '';

        $q = ReportQuery::tenant($criteria, 'i')
            ->where('i.path_id = :pathId', 'pathId', $pathId)
            ->where('i.deleted_at IS NULL');

        $columns = [
            ReportColumn::number('position', 'Position'),
            ReportColumn::text('item', 'Item'),
            ReportColumn::status('status', 'Status'),
            ReportColumn::date('completedAt', 'Completed'),
        ];

        // Each item on the path, marked done where this learner's enrolment
        // has a completion for it.
        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            "FROM learning_path_items i
             LEFT JOIN learning_path_enrollments e ON e.path_id = i.path_id
                                                  AND e.user_id = :self AND e.deleted_at IS NULL
             LEFT JOIN learning_path_item_completions c ON c.item_id = i.id AND c.enrollment_id = e.id",
            $q->where('1 = 1', 'self', $criteria->scope->viewerId),
            "i.sort_order AS position,
             i.title AS item,
             CASE WHEN c.completed_at IS NOT NULL THEN 'Completed' ELSE 'Outstanding' END AS status,
             c.completed_at AS \"completedAt\"",
            'i.sort_order',
            'i.id',
            'ASC',
        );
    }

    // --- assessment impact ------------------------------------------------

    private function repeatAssessmentTrend(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $q = ReportQuery::tenant($criteria, 'at')
            ->where("at.status = 'graded'")
            ->maybe($criteria->assessmentId, 'at.assessment_id = :assessmentId', 'assessmentId');

        // Averaged by attempt number: if later attempts score higher, retakes
        // are teaching something; if flat, they are just chances to guess.
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT at.attempt_number AS attempt,
                        round(AVG(at.percentage)::numeric, 1) AS avg_score,
                        COUNT(*) AS attempts,
                        round(100.0 * COUNT(*) FILTER (WHERE at.passed) / COUNT(*), 1) AS pass_rate
                 FROM attempts at
                 WHERE %s
                 GROUP BY at.attempt_number
                 ORDER BY at.attempt_number
                 LIMIT 20",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        return ReportResult::series(
            $definition,
            array_map(
                static fn (array $r): array => [
                    'label'    => 'Attempt ' . (int) $r['attempt'],
                    'value'    => (float) $r['avg_score'],
                    'attempts' => (int) $r['attempts'],
                    'passRate' => (float) $r['pass_rate'],
                ],
                $rows,
            ),
            ['attemptLevels' => count($rows)],
            $criteria,
        );
    }

    private function courseLearnerRoster(ReportDefinition $definition, ReportCriteria $criteria): ReportResult
    {
        $courseId = $criteria->courseId ?? '';

        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u')
            ->where('e.deleted_at IS NULL')
            ->where('e.course_id = :rosterCourse', 'rosterCourse', $courseId);

        $columns = [
            ReportColumn::text('learner', 'Learner'),
            ReportColumn::text('email', 'Email'),
            ReportColumn::text('department', 'Department'),
            ReportColumn::status('status', 'Status'),
            ReportColumn::percent('progress', 'Progress'),
            ReportColumn::date('enrolledAt', 'Enrolled'),
            ReportColumn::date('completedAt', 'Completed'),
        ];

        return $this->paginated(
            $definition,
            $criteria,
            $columns,
            "FROM enrollments e
             JOIN users u ON u.id = e.user_id
             LEFT JOIN lookups d ON d.id = u.department_id",
            $q,
            "trim(u.first_name || ' ' || u.last_name) AS learner,
             u.email AS email,
             d.name AS department,
             e.status AS status,
             e.progress_percentage AS progress,
             e.enrolled_at AS \"enrolledAt\",
             e.completed_at AS \"completedAt\"",
            'u.first_name',
            'e.id',
            'ASC',
        );
    }

    // --- shared plumbing --------------------------------------------------

    /**
     * A paginated table: one count, one page.
     *
     * @param list<ReportColumn> $columns
     */
    private function paginated(
        ReportDefinition $definition,
        ReportCriteria $criteria,
        array $columns,
        string $from,
        ReportQuery $q,
        string $select,
        string $orderColumn,
        string $tieBreak = 'e.id',
        ?string $fixedDirection = null,
    ): ReportResult {
        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) %s WHERE %s', $from, $q->sql()),
            $q->params(),
            $q->types(),
        );

        // Every page ends with a unique column. Without a tiebreaker, rows
        // that tie on the sort column come back in whatever order the planner
        // chose that time, so paging through a report shows one row twice and
        // misses another — the kind of wrong that looks like missing data.
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s %s WHERE %s ORDER BY %s %s NULLS LAST, %s LIMIT :limit OFFSET :offset',
                $select,
                $from,
                $q->sql(),
                $orderColumn,
                $fixedDirection ?? $criteria->sortOrder(),
                $tieBreak,
            ),
            $q->paramsWith(['limit' => $criteria->perPage, 'offset' => $criteria->offset()]),
            $q->types(),
        );

        return ReportResult::table($definition, $columns, $this->normalise($rows), $total, $criteria);
    }

    /**
     * A grouped table, paginated over the groups.
     *
     * @param list<ReportColumn> $columns
     */
    private function grouped(
        ReportDefinition $definition,
        ReportCriteria $criteria,
        array $columns,
        string $from,
        ReportQuery $q,
        string $select,
        string $groupBy,
        string $orderBy,
    ): ReportResult {
        $total = (int) $this->connection->fetchOne(
            sprintf(
                'SELECT COUNT(*) FROM (SELECT 1 %s WHERE %s GROUP BY %s) AS groups',
                $from,
                $q->sql(),
                $groupBy,
            ),
            $q->params(),
            $q->types(),
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s %s WHERE %s GROUP BY %s ORDER BY %s, %s LIMIT :limit OFFSET :offset',
                $select,
                $from,
                $q->sql(),
                $groupBy,
                $orderBy,
                // The group key is unique by definition, so it is the
                // tiebreaker that keeps paging stable.
                $groupBy,
            ),
            $q->paramsWith(['limit' => $criteria->perPage, 'offset' => $criteria->offset()]),
            $q->types(),
        );

        return ReportResult::table($definition, $columns, $this->normalise($rows), $total, $criteria);
    }

    /**
     * Numbers come back from PDO as strings; a report is mostly numbers.
     *
     * Left as strings they serialise into JSON quoted, and every client has to
     * remember to coerce before it can sort a column or draw a bar.
     *
     * @param  list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function normalise(array $rows): array
    {
        return array_map(
            static function (array $row): array {
                foreach ($row as $key => $value) {
                    if (is_string($value) && is_numeric($value)) {
                        $row[$key] = str_contains($value, '.') ? (float) $value : (int) $value;
                    }
                }

                return $row;
            },
            $rows,
        );
    }

    private function percent(int $part, int $whole): float
    {
        return $whole === 0 ? 0.0 : round(100 * $part / $whole, 1);
    }

    private function round(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 1);
    }

    private function humanise(string $value): string
    {
        return ucfirst(str_replace('_', ' ', $value));
    }

    // --- scope ------------------------------------------------------------

    public function selectableLearners(ReportScope $scope, ?string $search, int $page, int $perPage): array
    {
        $where  = ['u.tenant_id = :tenant', 'u.deleted_at IS NULL'];
        $params = ['tenant' => $scope->tenantId];

        if (!$scope->tenantWide) {
            $where[]            = '(u.id = :viewerId OR u.manager_id = :viewerId)';
            $params['viewerId'] = $scope->viewerId;
        }

        if ($search !== null && trim($search) !== '') {
            $where[]          = "(u.first_name || ' ' || u.last_name ILIKE :search OR u.email ILIKE :search)";
            $params['search'] = '%' . trim($search) . '%';
        }

        $clause = implode(' AND ', $where);

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM users u WHERE %s', $clause),
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT u.id, trim(u.first_name || ' ' || u.last_name) AS name, u.email,
                        d.name AS department
                 FROM users u
                 LEFT JOIN lookups d ON d.id = u.department_id
                 WHERE %s
                 ORDER BY u.first_name, u.last_name
                 LIMIT :limit OFFSET :offset",
                $clause,
            ),
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return ['items' => $rows, 'total' => $total];
    }

    public function scopeCovers(ReportScope $scope, string $userId): bool
    {
        $row = $this->connection->fetchAssociative(
            'SELECT u.manager_id FROM users u
             WHERE u.id = :id AND u.tenant_id = :tenant AND u.deleted_at IS NULL',
            ['id' => $userId, 'tenant' => $scope->tenantId],
        );

        // Not in the tenant at all. Reported as "not covered" rather than as a
        // separate answer, so probing ids cannot distinguish "no such person"
        // from "not yours".
        if ($row === false) {
            return false;
        }

        return $scope->covers($userId, $row['manager_id'] === null ? null : (string) $row['manager_id']);
    }
}
