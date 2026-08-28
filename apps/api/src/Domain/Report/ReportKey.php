<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

/**
 * Every report that can be run.
 *
 * A closed enum rather than the legacy's string keys into an array literal.
 * The difference matters: `runReport($_POST['report_key'])` against an array
 * means an unknown key is a runtime surprise, and there is no way for anything
 * — a route, a saved report, a schedule — to be checked against the catalogue
 * before it runs.
 */
enum ReportKey: string
{
    // --- enrolment --------------------------------------------------------
    case EnrollmentSummary    = 'enrollment_summary';
    case EnrollmentTrends     = 'enrollment_trends';
    case EnrollmentDetail     = 'enrollment_detail_list';
    case PendingEnrollments   = 'pending_enrollments';
    case EnrollmentBySource   = 'enrollment_by_source';
    case DropOff              = 'drop_off_report';
    case EnrollmentByCourse   = 'enrollment_by_course';

    // --- completion -------------------------------------------------------
    case CompletionSummary      = 'completion_summary';
    case IndividualProgress     = 'individual_progress';
    case CompletionByDepartment = 'completion_by_department';
    case CompletionByTeam       = 'completion_by_team';
    case CompletionByBranch     = 'completion_by_branch';
    case OverdueTraining        = 'overdue_training';
    case TimeToCompletion       = 'time_to_completion';
    case CompletionTrend        = 'completion_trend';

    // --- compliance -------------------------------------------------------
    case MandatoryCompliance     = 'mandatory_training_compliance';
    case NonCompliantUsers       = 'non_compliant_users';
    case ComplianceByDepartment  = 'compliance_by_department';
    case ComplianceGapAnalysis   = 'compliance_gap_analysis';
    case CertificationExpiry     = 'certification_expiry';
    case RecertificationDue      = 'recertification_due';

    // --- assessment -------------------------------------------------------
    case AssessmentSummary    = 'assessment_results_summary';
    case AssessmentDetail     = 'assessment_results_detail';
    case IndividualAssessment = 'individual_assessment_report';
    case FailedAssessments    = 'failed_assessments';
    case QuestionAnalysis     = 'question_analysis';
    case ScoreDistribution    = 'score_distribution';
    case AssessmentAttempts   = 'assessment_attempts';
    case TopPerformers        = 'top_performers';
    case StrugglingLearners   = 'struggling_learners';
    case ProctoringViolations = 'proctoring_violations';

    // --- courses ----------------------------------------------------------
    case CourseCatalog       = 'course_catalog';
    case CourseEffectiveness = 'course_effectiveness';
    case CourseUsage         = 'course_usage';
    case ModulePerformance   = 'module_performance';
    case ContentEngagement   = 'content_engagement';
    case UnusedCourses       = 'unused_courses';

    // --- people -----------------------------------------------------------
    case ActiveUsers         = 'active_users';
    case InactiveUsers       = 'inactive_users';
    case NewUsers            = 'new_users';
    case UserActivitySummary = 'user_activity_summary';
    case DepartmentTraining  = 'department_training_summary';
    case RoleBasedTraining   = 'role_based_training';

    // --- time and engagement ---------------------------------------------
    case TimeSpent       = 'time_spent';
    case PeakUsage       = 'peak_usage';
    case SessionDuration = 'session_duration';
    case EngagementScore = 'engagement_score';
    case ActivityReport  = 'activity_report';
    case PlatformUsage   = 'platform_usage';

    // --- certificates -----------------------------------------------------
    case CertificatesIssued   = 'certificates_issued';
    case VerificationLog      = 'verification_log';
    case ExpiringCertificates = 'expiring_certificates';
    case ReprintHistory       = 'reprint_history';
    case TemplateUsage        = 'template_usage';

    // --- executive --------------------------------------------------------
    case ExecutiveDashboard = 'executive_dashboard';
    case MonthlyTraining    = 'monthly_training_summary';
    case YearOverYear       = 'year_over_year';

    // --- gamification -----------------------------------------------------
    case Leaderboard              = 'leaderboard';
    case BadgesEarned             = 'badges_earned';
    case PointsSummary            = 'points_summary';
    case DepartmentLeaderboard    = 'department_leaderboard';
    case GamificationParticipation = 'gamification_participation';

    // --- learning paths ---------------------------------------------------
    case PathEnrollment    = 'path_enrollment';
    case PathProgress      = 'path_progress';
    case PathCompletion    = 'path_completion';
    case PathDropOff       = 'path_drop_off';
    case PathEffectiveness = 'path_effectiveness';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }
}
