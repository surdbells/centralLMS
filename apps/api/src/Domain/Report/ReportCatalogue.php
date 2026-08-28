<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;

/**
 * Every report, what it is, and who may run it.
 *
 * The legacy carried 84 entries. Fifty-seven are here. The other twenty-seven
 * report on modules this port has not reached, and a report over a table that
 * does not exist is not a report:
 *
 *   * **gamification (6)** — points, badges, streaks, leaderboards. The module
 *     is not ported.
 *   * **audit (6)** — `full_audit_trail`, `data_access_log`, `privacy_report`,
 *     `security_audit`, `data_retention`, `training_records_export`. There is
 *     no audit log yet. These are the ones a regulator asks for, so they
 *     should follow the audit module closely.
 *   * **system (6)** — error log, admin activity, storage, integration sync,
 *     tenant usage. Operational reporting over modules not yet ported.
 *   * **course (3)** — `course_ratings`, `scorm_activity`,
 *     `course_version_history`: ratings, SCORM and versioning do not exist.
 *   * **user (1)** — `user_login_history` needs the login-history table; the
 *     users table carries only `last_login_at` and `login_count`.
 *   * **assessment, compliance, executive (6)** — `regulatory_training_audit`,
 *     `executive_compliance_summary` (folded into `mandatory_compliance`),
 *     `training_roi` and `budget_utilization` need cost data that no table
 *     holds.
 *
 * Listing them here rather than dropping them silently is the point: the gap
 * is visible, and each one names what it is waiting for.
 */
final readonly class ReportCatalogue
{
    /** @var array<string, ReportDefinition> */
    private array $definitions;

    public function __construct()
    {
        $definitions = [];

        foreach (self::build() as $definition) {
            $definitions[$definition->key->value] = $definition;
        }

        $this->definitions = $definitions;
    }

    public function find(ReportKey $key): ReportDefinition
    {
        return $this->definitions[$key->value]
            ?? throw new \LogicException(sprintf('No definition for report "%s".', $key->value));
    }

    /**
     * Reports this caller may actually run.
     *
     * The listing is derived from the same permission the run checks, so a
     * report cannot appear in someone's catalogue and then refuse to run —
     * which is precisely what the legacy's separate `audience` field allowed,
     * in the other direction.
     *
     * @return list<ReportDefinition>
     */
    public function availableTo(AuthenticatedUser $caller, ?ReportCategory $category = null): array
    {
        $available = [];

        foreach ($this->definitions as $definition) {
            if (!$caller->can($definition->permission)) {
                continue;
            }

            if ($category !== null && $definition->category !== $category) {
                continue;
            }

            $available[] = $definition;
        }

        return $available;
    }

    /**
     * @return list<ReportDefinition>
     */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    /**
     * @return list<ReportDefinition>
     */
    private static function build(): array
    {
        return [
            // --- enrolment ------------------------------------------------
            new ReportDefinition(
                ReportKey::EnrollmentSummary,
                ReportCategory::Enrollment,
                'Enrolment summary',
                'Totals and a status breakdown across every enrolment in range.',
                ReportShape::Summary,
            ),
            new ReportDefinition(
                ReportKey::EnrollmentTrends,
                ReportCategory::Enrollment,
                'Enrolment trend',
                'How many people were enrolled, month by month.',
                ReportShape::Series,
            ),
            new ReportDefinition(
                ReportKey::EnrollmentDetail,
                ReportCategory::Enrollment,
                'Enrolment detail',
                'One row per enrolment: learner, course, status, progress and score.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::PendingEnrollments,
                ReportCategory::Enrollment,
                'Not started',
                'People enrolled on something they have not opened.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::EnrollmentBySource,
                ReportCategory::Enrollment,
                'Enrolment by source',
                'How people came to be enrolled — assigned, self-enrolled, imported.',
                ReportShape::Breakdown,
            ),
            new ReportDefinition(
                ReportKey::DropOff,
                ReportCategory::Enrollment,
                'Drop-off',
                'People who started and stopped, and those taken off a course.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::EnrollmentByCourse,
                ReportCategory::Enrollment,
                'Enrolment by course',
                'Per-course enrolment, completion and average score.',
                ReportShape::Table,
                exportable: true,
            ),

            // --- completion -----------------------------------------------
            new ReportDefinition(
                ReportKey::CompletionSummary,
                ReportCategory::Completion,
                'Completion summary',
                'Completion rate, average progress and how many are overdue.',
                ReportShape::Summary,
            ),
            new ReportDefinition(
                ReportKey::IndividualProgress,
                ReportCategory::Completion,
                'Individual progress',
                'One learner across everything they are enrolled on.',
                ReportShape::Detail,
                exportable: true,
                requires: ['learnerId'],
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::CompletionByDepartment,
                ReportCategory::Completion,
                'Completion by department',
                'Completion rate and average score for each department.',
                ReportShape::Table,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::CompletionByTeam,
                ReportCategory::Completion,
                'Completion by team',
                'Completion rate and average score for each team.',
                ReportShape::Table,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::CompletionByBranch,
                ReportCategory::Completion,
                'Completion by branch',
                'Completion rate and average score for each branch.',
                ReportShape::Table,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::OverdueTraining,
                ReportCategory::Completion,
                'Overdue training',
                'Everything past its due date, with how late it is.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::TimeToCompletion,
                ReportCategory::Completion,
                'Time to completion',
                'How long each course takes people to finish.',
                ReportShape::Table,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::CompletionTrend,
                ReportCategory::Completion,
                'Completion trend',
                'Completions per month.',
                ReportShape::Series,
            ),

            // --- compliance -----------------------------------------------
            new ReportDefinition(
                ReportKey::MandatoryCompliance,
                ReportCategory::Compliance,
                'Mandatory training compliance',
                'Where the organisation stands on training it requires.',
                ReportShape::Summary,
            ),
            new ReportDefinition(
                ReportKey::NonCompliantUsers,
                ReportCategory::Compliance,
                'Not compliant',
                'People with mandatory training outstanding.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::ComplianceByDepartment,
                ReportCategory::Compliance,
                'Compliance by department',
                'Mandatory completion rate for each department.',
                ReportShape::Table,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::ComplianceByBranch,
                ReportCategory::Compliance,
                'Compliance by branch',
                'Mandatory completion rate for each branch.',
                ReportShape::Table,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::ComplianceByTeam,
                ReportCategory::Compliance,
                'Compliance by team',
                'Mandatory completion rate for each team.',
                ReportShape::Table,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::ComplianceStatusDashboard,
                ReportCategory::Compliance,
                'Compliance status dashboard',
                'The one-glance picture: how many people are covered, pending and overdue.',
                ReportShape::Summary,
            ),
            new ReportDefinition(
                ReportKey::RegulatoryTrainingAudit,
                ReportCategory::Compliance,
                'Regulatory training audit',
                'Every completed mandatory course with its certificate — the record an auditor asks for.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::ComplianceExecutiveSummary,
                ReportCategory::Compliance,
                'Compliance executive summary',
                'The organisation rolled up, with the branches and departments furthest behind.',
                ReportShape::Summary,
            ),
            new ReportDefinition(
                ReportKey::ComplianceGapAnalysis,
                ReportCategory::Compliance,
                'Compliance gaps',
                'Which mandatory courses are furthest from complete.',
                ReportShape::Table,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::CertificationExpiry,
                ReportCategory::Compliance,
                'Certificates expiring',
                'Certificates that lapse inside the window.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::RecertificationDue,
                ReportCategory::Compliance,
                'Recertification due',
                'People whose certificate has lapsed and who are not re-enrolled.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),

            // --- assessment -----------------------------------------------
            new ReportDefinition(
                ReportKey::AssessmentSummary,
                ReportCategory::Assessment,
                'Assessment summary',
                'Attempts, pass rate and average score across assessments.',
                ReportShape::Summary,
            ),
            new ReportDefinition(
                ReportKey::AssessmentDetail,
                ReportCategory::Assessment,
                'Assessment results',
                'One row per attempt: learner, assessment, score, outcome.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::IndividualAssessment,
                ReportCategory::Assessment,
                'One learner’s assessments',
                'Every attempt one person has made.',
                ReportShape::Detail,
                exportable: true,
                requires: ['learnerId'],
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::FailedAssessments,
                ReportCategory::Assessment,
                'Failed attempts',
                'Attempts that did not reach the pass mark.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::QuestionAnalysis,
                ReportCategory::Assessment,
                'Question analysis',
                'Which questions people get wrong — and which nobody does.',
                ReportShape::Table,
                exportable: true,
                requires: ['assessmentId'],
            ),
            new ReportDefinition(
                ReportKey::ScoreDistribution,
                ReportCategory::Assessment,
                'Score distribution',
                'How marks are spread, in bands of ten.',
                ReportShape::Breakdown,
            ),
            new ReportDefinition(
                ReportKey::AssessmentAttempts,
                ReportCategory::Assessment,
                'Attempts per assessment',
                'How many goes people need, and how many run out.',
                ReportShape::Table,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::TopPerformers,
                ReportCategory::Assessment,
                'Top performers',
                'The highest average scores.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::StrugglingLearners,
                ReportCategory::Assessment,
                'Learners struggling',
                'Repeated failures and low averages — who needs help.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::ProctoringViolations,
                ReportCategory::Assessment,
                'Proctoring violations',
                'Flagged attempts and what was seen.',
                ReportShape::Table,
                // Not `report.view`: these rows describe a named person being
                // suspected of cheating, sourced from webcam and screen
                // monitoring. The permission that gates the recordings gates
                // the report about them.
                permission: Permission::ProctoringReview,
                exportable: true,
                namesPeople: true,
            ),

            // --- courses --------------------------------------------------
            new ReportDefinition(
                ReportKey::CourseCatalog,
                ReportCategory::Course,
                'Course catalogue',
                'Every course with its structure and status.',
                ReportShape::Table,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::CourseEffectiveness,
                ReportCategory::Course,
                'Course effectiveness',
                'Completion rate, average score and time taken, per course.',
                ReportShape::Table,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::CourseUsage,
                ReportCategory::Course,
                'Course usage',
                'Which courses are being taken, and how much.',
                ReportShape::Table,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::ModulePerformance,
                ReportCategory::Course,
                'Module performance',
                'Where inside a course people stall.',
                ReportShape::Table,
                exportable: true,
                requires: ['courseId'],
            ),
            new ReportDefinition(
                ReportKey::ContentEngagement,
                ReportCategory::Course,
                'Lesson engagement',
                'Time spent and completion for each lesson.',
                ReportShape::Table,
                exportable: true,
                requires: ['courseId'],
            ),
            new ReportDefinition(
                ReportKey::UnusedCourses,
                ReportCategory::Course,
                'Unused courses',
                'Published courses nobody is enrolled on.',
                ReportShape::Table,
                exportable: true,
            ),

            // --- people ---------------------------------------------------
            new ReportDefinition(
                ReportKey::ActiveUsers,
                ReportCategory::User,
                'Active people',
                'Who has been on the platform in the period.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::InactiveUsers,
                ReportCategory::User,
                'Dormant accounts',
                'Active accounts that have not signed in for a long time.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::NewUsers,
                ReportCategory::User,
                'New joiners',
                'Accounts created in the period, and whether they got going.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::UserActivitySummary,
                ReportCategory::User,
                'People summary',
                'Headcount, activity and how much training is under way.',
                ReportShape::Summary,
            ),
            new ReportDefinition(
                ReportKey::DepartmentTraining,
                ReportCategory::User,
                'Training by department',
                'Headcount, enrolments and completion for each department.',
                ReportShape::Table,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::RoleBasedTraining,
                ReportCategory::User,
                'Training by role',
                'What each role is enrolled on and how far they have got.',
                ReportShape::Table,
                exportable: true,
            ),

            // --- time and engagement --------------------------------------
            new ReportDefinition(
                ReportKey::TimeSpent,
                ReportCategory::Time,
                'Time spent',
                'Hours of learning, by person.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::PeakUsage,
                ReportCategory::Time,
                'When people learn',
                'Activity by hour of the day.',
                ReportShape::Breakdown,
            ),
            new ReportDefinition(
                ReportKey::SessionDuration,
                ReportCategory::Time,
                'Time per lesson',
                'How long lessons take, and which run long.',
                ReportShape::Table,
                exportable: true,
                requires: ['courseId'],
            ),
            new ReportDefinition(
                ReportKey::EngagementScore,
                ReportCategory::Time,
                'Engagement',
                'A single figure per person from recency, breadth and progress.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::ActivityReport,
                ReportCategory::Time,
                'Activity over time',
                'Learning activity per day.',
                ReportShape::Series,
            ),
            new ReportDefinition(
                ReportKey::PlatformUsage,
                ReportCategory::Time,
                'Platform usage',
                'Totals for the organisation: people, hours, courses touched.',
                ReportShape::Summary,
            ),

            // --- certificates ---------------------------------------------
            new ReportDefinition(
                ReportKey::CertificatesIssued,
                ReportCategory::Certificate,
                'Certificates issued',
                'What has been issued, to whom, and for what.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::VerificationLog,
                ReportCategory::Certificate,
                'Verification activity',
                'Which certificates are being checked, and how often.',
                ReportShape::Table,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::ExpiringCertificates,
                ReportCategory::Certificate,
                'Certificates expiring',
                'What lapses next, soonest first.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::ReprintHistory,
                ReportCategory::Certificate,
                'Certificate history',
                'Issues, revocations, reinstatements and downloads.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::TemplateUsage,
                ReportCategory::Certificate,
                'Template usage',
                'Which certificate templates are actually used.',
                ReportShape::Table,
                exportable: true,
            ),

            // --- executive ------------------------------------------------
            // Tenant-wide by nature, so they carry the wider permission
            // rather than relying on row scoping to make sense of them.
            new ReportDefinition(
                ReportKey::ExecutiveDashboard,
                ReportCategory::Executive,
                'Executive dashboard',
                'The organisation at a glance.',
                ReportShape::Summary,
                permission: Permission::ReportExport,
            ),
            new ReportDefinition(
                ReportKey::MonthlyTraining,
                ReportCategory::Executive,
                'Monthly summary',
                'Enrolments, completions and hours, month by month.',
                ReportShape::Series,
                permission: Permission::ReportExport,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::YearOverYear,
                ReportCategory::Executive,
                'Year on year',
                'This year against last, on the measures that matter.',
                ReportShape::Table,
                permission: Permission::ReportExport,
                exportable: true,
            ),

            // --- gamification ---------------------------------------------
            new ReportDefinition(
                ReportKey::Leaderboard,
                ReportCategory::Gamification,
                'Leaderboard',
                'Who has earned the most points, with badges and completions beside.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::BadgesEarned,
                ReportCategory::Gamification,
                'Badges earned',
                'Every badge awarded in range, and to whom.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::PointsSummary,
                ReportCategory::Gamification,
                'Points summary',
                'Points per person — all time, this month and this week.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::DepartmentLeaderboard,
                ReportCategory::Gamification,
                'Department leaderboard',
                'Points and badges totalled by department.',
                ReportShape::Breakdown,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::GamificationParticipation,
                ReportCategory::Gamification,
                'Gamification participation',
                'How many people are taking part, and where the points come from.',
                ReportShape::Summary,
            ),

            // --- learning paths -------------------------------------------
            new ReportDefinition(
                ReportKey::PathEnrollment,
                ReportCategory::Path,
                'Path enrolment',
                'Every path, and how many are on it, active on it and finished.',
                ReportShape::Table,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::PathProgress,
                ReportCategory::Path,
                'Path progress',
                'Each learner on one path, and how far through its items they are.',
                ReportShape::Table,
                exportable: true,
                requires: ['pathId'],
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::PathCompletion,
                ReportCategory::Path,
                'Path completion',
                'The rate each path is finished at, and how long it takes.',
                ReportShape::Table,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::PathDropOff,
                ReportCategory::Path,
                'Path drop-off',
                'The item on one path where learners stop — where to look first.',
                ReportShape::Table,
                exportable: true,
                requires: ['pathId'],
            ),
            new ReportDefinition(
                ReportKey::PathEffectiveness,
                ReportCategory::Path,
                'Path effectiveness',
                'Completion set against how much of each path people actually reach.',
                ReportShape::Table,
                exportable: true,
            ),

            // --- live training --------------------------------------------
            new ReportDefinition(
                ReportKey::SessionAttendance,
                ReportCategory::Ilt,
                'Session attendance',
                'Each session, how many were marked and how many turned up.',
                ReportShape::Table,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::NoShow,
                ReportCategory::Ilt,
                'No-shows',
                'The people marked absent from a session, and where they sit.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::SessionUtilization,
                ReportCategory::Ilt,
                'Session utilisation',
                'How full each session was against the room it booked.',
                ReportShape::Table,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::InstructorEffectiveness,
                ReportCategory::Ilt,
                'Instructor effectiveness',
                'Sessions delivered per instructor, and the attendance they drew.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::VenueUtilization,
                ReportCategory::Ilt,
                'Venue utilisation',
                'How hard each venue is worked, and how full it runs.',
                ReportShape::Breakdown,
                exportable: true,
            ),

            // --- onboarding -----------------------------------------------
            new ReportDefinition(
                ReportKey::OnboardingByCohort,
                ReportCategory::Onboarding,
                'Onboarding by cohort',
                'New joiners grouped by the month they started, and how far through required training they are.',
                ReportShape::Table,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::TimeToCompetency,
                ReportCategory::Onboarding,
                'Time to competency',
                'How long each new joiner took from their first day to finishing required training.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::OnboardingByOrg,
                ReportCategory::Onboarding,
                'Onboarding by department',
                'How well each department gets its new people through required training.',
                ReportShape::Table,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::NewHireDashboard,
                ReportCategory::Onboarding,
                'New hire dashboard',
                'Everyone who started recently, and where they are with required training.',
                ReportShape::Summary,
            ),
            new ReportDefinition(
                ReportKey::OnboardingDropOff,
                ReportCategory::Onboarding,
                'Onboarding drop-off',
                'The required course new joiners stall on most.',
                ReportShape::Table,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::ProbationCompliance,
                ReportCategory::Onboarding,
                'Probation compliance',
                'Who finished their required training before their probation ended — and who did not.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),

            // --- gaps in existing families --------------------------------
            new ReportDefinition(
                ReportKey::SelfEnrollment,
                ReportCategory::Enrollment,
                'Self-enrolment',
                'What people chose for themselves — the courses pulling learners in unbidden.',
                ReportShape::Table,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::IncompleteCourses,
                ReportCategory::Completion,
                'Incomplete courses',
                'Everyone with a course still open, with their manager and how far past due it is.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
            new ReportDefinition(
                ReportKey::CompletionByManager,
                ReportCategory::Completion,
                'Completion by manager',
                'How each manager\'s team is doing, for the people they answer for.',
                ReportShape::Table,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::LearningHoursByDepartment,
                ReportCategory::Time,
                'Learning hours by department',
                'Where the hours are being spent, totalled by department.',
                ReportShape::Breakdown,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::MonthlyTrainingHours,
                ReportCategory::Time,
                'Monthly training hours',
                'Hours spent learning, month by month.',
                ReportShape::Series,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::CertificatesByCourse,
                ReportCategory::Certificate,
                'Certificates by course',
                'How many certificates each course has issued, and to how many people.',
                ReportShape::Table,
                exportable: true,
            ),
            new ReportDefinition(
                ReportKey::DrilldownLearners,
                ReportCategory::User,
                'Learner drilldown',
                'The people behind a figure — filtered by the status you clicked through from.',
                ReportShape::Table,
                exportable: true,
                namesPeople: true,
            ),
        ];
    }
}
