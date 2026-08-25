<?php

require_once __DIR__ . '/BaseReport.php';
require_once __DIR__ . '/EnrollmentReports.php';
require_once __DIR__ . '/CompletionReports.php';
require_once __DIR__ . '/ComplianceReports.php';
require_once __DIR__ . '/AssessmentReports.php';
require_once __DIR__ . '/UserReports.php';
require_once __DIR__ . '/CourseReports.php';
require_once __DIR__ . '/TimeEngagementReports.php';
require_once __DIR__ . '/GamificationReports.php';
require_once __DIR__ . '/CertificateReports.php';
require_once __DIR__ . '/SystemReports.php';
require_once __DIR__ . '/ExecutiveReports.php';
require_once __DIR__ . '/AuditReports.php';
require_once __DIR__ . '/LearnerReports.php';

/**
 * ReportService - Factory and facade for all LMS reports
 */
class ReportService {
    private mysqli $conn;
    private int $tenant_id;
    private ?int $user_id = null;
    private array $reportInstances = [];

    // Report catalog with metadata
    private array $reportCatalog = [
        // Enrollment Reports
        'course_enrollment_summary' => [
            'class' => 'EnrollmentReports',
            'method' => 'courseEnrollmentSummary',
            'name' => 'Course Enrollment Summary',
            'category' => 'enrollment',
            'description' => 'Overview of enrollments across all courses',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],
        'enrollment_trends' => [
            'class' => 'EnrollmentReports',
            'method' => 'enrollmentTrends',
            'name' => 'Enrollment Trends',
            'category' => 'enrollment',
            'description' => 'Enrollment patterns over time',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],
        'pending_enrollments' => [
            'class' => 'EnrollmentReports',
            'method' => 'pendingEnrollments',
            'name' => 'Pending Enrollments',
            'category' => 'enrollment',
            'description' => 'Users who haven\'t started their assigned courses',
            'audience' => ['admin', 'hr', 'manager'],
            'exportable' => true
        ],
        'self_enrollment_report' => [
            'class' => 'EnrollmentReports',
            'method' => 'selfEnrollmentReport',
            'name' => 'Self-Enrollment Report',
            'category' => 'enrollment',
            'description' => 'Courses with voluntary enrollments',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],
        'waitlist_report' => [
            'class' => 'EnrollmentReports',
            'method' => 'waitlistReport',
            'name' => 'Waitlist Report',
            'category' => 'enrollment',
            'description' => 'Courses with capacity limits and waitlists',
            'audience' => ['admin'],
            'exportable' => true
        ],

        // Completion Reports
        'course_completion_summary' => [
            'class' => 'CompletionReports',
            'method' => 'courseCompletionSummary',
            'name' => 'Course Completion Summary',
            'category' => 'completion',
            'description' => 'Completion rates and statistics by course',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],
        'individual_progress_report' => [
            'class' => 'CompletionReports',
            'method' => 'individualProgressReport',
            'name' => 'Individual Progress Report',
            'category' => 'completion',
            'description' => 'Detailed progress for a specific learner',
            'audience' => ['admin', 'hr', 'manager'],
            'params' => ['user_id'],
            'exportable' => true
        ],
        'incomplete_courses_report' => [
            'class' => 'CompletionReports',
            'method' => 'incompleteCoursesReport',
            'name' => 'Incomplete Courses Report',
            'category' => 'completion',
            'description' => 'All incomplete enrollments with progress details',
            'audience' => ['admin', 'hr', 'manager'],
            'exportable' => true
        ],
        'completion_by_department' => [
            'class' => 'CompletionReports',
            'method' => 'completionByDepartment',
            'name' => 'Completion by Department',
            'category' => 'completion',
            'description' => 'Training completion metrics grouped by department',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],
        'completion_by_manager' => [
            'class' => 'CompletionReports',
            'method' => 'completionByManager',
            'name' => 'Completion by Manager',
            'category' => 'completion',
            'description' => 'Team completion metrics grouped by manager',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],
        'time_to_completion_analysis' => [
            'class' => 'CompletionReports',
            'method' => 'timeToCompletionAnalysis',
            'name' => 'Time to Completion Analysis',
            'category' => 'completion',
            'description' => 'How long learners take to complete courses',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],
        'overdue_training_report' => [
            'class' => 'CompletionReports',
            'method' => 'overdueTrainingReport',
            'name' => 'Overdue Training Report',
            'category' => 'completion',
            'description' => 'All overdue training assignments',
            'audience' => ['admin', 'hr', 'manager'],
            'exportable' => true
        ],

        // Compliance Reports
        'compliance_status_dashboard' => [
            'class' => 'ComplianceReports',
            'method' => 'complianceStatusDashboard',
            'name' => 'Compliance Status Dashboard',
            'category' => 'compliance',
            'description' => 'Overall compliance status overview',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],
        'mandatory_training_compliance' => [
            'class' => 'ComplianceReports',
            'method' => 'mandatoryTrainingCompliance',
            'name' => 'Mandatory Training Compliance',
            'category' => 'compliance',
            'description' => 'User-level mandatory training status',
            'audience' => ['admin', 'hr', 'manager'],
            'exportable' => true
        ],
        'certification_expiry_report' => [
            'class' => 'ComplianceReports',
            'method' => 'certificationExpiryReport',
            'name' => 'Certification Expiry Report',
            'category' => 'compliance',
            'description' => 'Certificates expiring within specified period',
            'audience' => ['admin', 'hr'],
            'params' => ['days_ahead'],
            'exportable' => true
        ],
        'recertification_due_report' => [
            'class' => 'ComplianceReports',
            'method' => 'recertificationDueReport',
            'name' => 'Recertification Due Report',
            'category' => 'compliance',
            'description' => 'Users needing to recertify',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],
        'compliance_by_department' => [
            'class' => 'ComplianceReports',
            'method' => 'complianceByDepartment',
            'name' => 'Compliance by Department',
            'category' => 'compliance',
            'description' => 'Department-level compliance metrics',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],
        'regulatory_training_audit' => [
            'class' => 'ComplianceReports',
            'method' => 'regulatoryTrainingAudit',
            'name' => 'Regulatory Training Audit',
            'category' => 'compliance',
            'description' => 'Detailed audit trail for mandatory training',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],
        'non_compliant_users_report' => [
            'class' => 'ComplianceReports',
            'method' => 'nonCompliantUsersReport',
            'name' => 'Non-Compliant Users Report',
            'category' => 'compliance',
            'description' => 'Users with overdue mandatory training',
            'audience' => ['admin', 'hr', 'manager'],
            'exportable' => true
        ],

        // Assessment Reports
        'assessment_results_summary' => [
            'class' => 'AssessmentReports',
            'method' => 'assessmentResultsSummary',
            'name' => 'Assessment Results Summary',
            'category' => 'assessment',
            'description' => 'Overall assessment performance metrics',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],
        'individual_assessment_report' => [
            'class' => 'AssessmentReports',
            'method' => 'individualAssessmentReport',
            'name' => 'Individual Assessment Report',
            'category' => 'assessment',
            'description' => 'Assessment history for a specific learner',
            'audience' => ['admin', 'hr', 'manager'],
            'params' => ['user_id'],
            'exportable' => true
        ],
        'failed_assessments_report' => [
            'class' => 'AssessmentReports',
            'method' => 'failedAssessmentsReport',
            'name' => 'Failed Assessments Report',
            'category' => 'assessment',
            'description' => 'Learners who failed their most recent attempt',
            'audience' => ['admin', 'hr', 'manager'],
            'exportable' => true
        ],
        'question_analysis_report' => [
            'class' => 'AssessmentReports',
            'method' => 'questionAnalysisReport',
            'name' => 'Question Analysis Report',
            'category' => 'assessment',
            'description' => 'Question-level performance analysis',
            'audience' => ['admin'],
            'params' => ['assessment_id'],
            'exportable' => true
        ],
        'score_distribution_report' => [
            'class' => 'AssessmentReports',
            'method' => 'scoreDistributionReport',
            'name' => 'Score Distribution Report',
            'category' => 'assessment',
            'description' => 'Distribution of scores across assessments',
            'audience' => ['admin', 'hr'],
            'params' => ['assessment_id'],
            'exportable' => true
        ],
        'assessment_attempts_report' => [
            'class' => 'AssessmentReports',
            'method' => 'assessmentAttemptsReport',
            'name' => 'Assessment Attempts Report',
            'category' => 'assessment',
            'description' => 'Number of attempts per assessment',
            'audience' => ['admin'],
            'exportable' => true
        ],
        'top_performers_report' => [
            'class' => 'AssessmentReports',
            'method' => 'topPerformersReport',
            'name' => 'Top Performers Report',
            'category' => 'assessment',
            'description' => 'Highest scoring learners',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],
        'struggling_learners_report' => [
            'class' => 'AssessmentReports',
            'method' => 'strugglingLearnersReport',
            'name' => 'Struggling Learners Report',
            'category' => 'assessment',
            'description' => 'Learners needing additional support',
            'audience' => ['admin', 'hr', 'manager'],
            'exportable' => true
        ],

        // User Reports
        'active_users_report' => [
            'class' => 'UserReports',
            'method' => 'activeUsersReport',
            'name' => 'Active Users Report',
            'category' => 'user',
            'description' => 'Recently active learners',
            'audience' => ['admin', 'hr'],
            'params' => ['days'],
            'exportable' => true
        ],
        'inactive_users_report' => [
            'class' => 'UserReports',
            'method' => 'inactiveUsersReport',
            'name' => 'Inactive Users Report',
            'category' => 'user',
            'description' => 'Users who haven\'t logged in recently',
            'audience' => ['admin', 'hr'],
            'params' => ['days'],
            'exportable' => true
        ],
        'new_users_report' => [
            'class' => 'UserReports',
            'method' => 'newUsersReport',
            'name' => 'New Users Report',
            'category' => 'user',
            'description' => 'Recently onboarded users',
            'audience' => ['admin', 'hr'],
            'params' => ['days'],
            'exportable' => true
        ],
        'user_activity_summary' => [
            'class' => 'UserReports',
            'method' => 'userActivitySummary',
            'name' => 'User Activity Summary',
            'category' => 'user',
            'description' => 'Detailed activity for a specific user',
            'audience' => ['admin', 'hr', 'manager'],
            'params' => ['user_id'],
            'exportable' => true
        ],
        'department_training_summary' => [
            'class' => 'UserReports',
            'method' => 'departmentTrainingSummary',
            'name' => 'Department Training Summary',
            'category' => 'user',
            'description' => 'Training metrics by department',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],
        'managers_team_report' => [
            'class' => 'UserReports',
            'method' => 'managersTeamReport',
            'name' => 'Manager\'s Team Report',
            'category' => 'user',
            'description' => 'Team training status for a manager',
            'audience' => ['admin', 'hr', 'manager'],
            'params' => ['manager_id'],
            'exportable' => true
        ],
        'user_login_history' => [
            'class' => 'UserReports',
            'method' => 'userLoginHistory',
            'name' => 'User Login History',
            'category' => 'user',
            'description' => 'Login activity log',
            'audience' => ['admin'],
            'params' => ['user_id'],
            'exportable' => true
        ],
        'role_based_training_report' => [
            'class' => 'UserReports',
            'method' => 'roleBasedTrainingReport',
            'name' => 'Role-based Training Report',
            'category' => 'user',
            'description' => 'Training metrics by job role',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],

        // Course Reports
        'course_catalog_report' => [
            'class' => 'CourseReports',
            'method' => 'courseCatalogReport',
            'name' => 'Course Catalog Report',
            'category' => 'course',
            'description' => 'Complete course catalog with metrics',
            'audience' => ['admin'],
            'exportable' => true
        ],
        'course_effectiveness_report' => [
            'class' => 'CourseReports',
            'method' => 'courseEffectivenessReport',
            'name' => 'Course Effectiveness Report',
            'category' => 'course',
            'description' => 'Course performance and effectiveness scores',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],
        'course_usage_report' => [
            'class' => 'CourseReports',
            'method' => 'courseUsageReport',
            'name' => 'Course Usage Report',
            'category' => 'course',
            'description' => 'How courses are being used',
            'audience' => ['admin'],
            'exportable' => true
        ],
        'module_performance_report' => [
            'class' => 'CourseReports',
            'method' => 'modulePerformanceReport',
            'name' => 'Module Performance Report',
            'category' => 'course',
            'description' => 'Performance breakdown by module',
            'audience' => ['admin'],
            'params' => ['course_id'],
            'exportable' => true
        ],
        'content_engagement_report' => [
            'class' => 'CourseReports',
            'method' => 'contentEngagementReport',
            'name' => 'Content Engagement Report',
            'category' => 'course',
            'description' => 'Lesson-level engagement metrics',
            'audience' => ['admin'],
            'exportable' => true
        ],
        'course_ratings_feedback' => [
            'class' => 'CourseReports',
            'method' => 'courseRatingsFeedback',
            'name' => 'Course Ratings & Feedback',
            'category' => 'course',
            'description' => 'Course ratings and review summary',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],
        'unused_courses_report' => [
            'class' => 'CourseReports',
            'method' => 'unusedCoursesReport',
            'name' => 'Unused Courses Report',
            'category' => 'course',
            'description' => 'Courses with no recent activity',
            'audience' => ['admin'],
            'params' => ['days'],
            'exportable' => true
        ],
        'course_version_history' => [
            'class' => 'CourseReports',
            'method' => 'courseVersionHistory',
            'name' => 'Course Version History',
            'category' => 'course',
            'description' => 'Version history for a course',
            'audience' => ['admin'],
            'params' => ['course_id'],
            'exportable' => true
        ],

        // Time & Engagement Reports
        'time_spent_report' => [
            'class' => 'TimeEngagementReports',
            'method' => 'timeSpentReport',
            'name' => 'Time Spent Report',
            'category' => 'time',
            'description' => 'Learning time by user',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],
        'learning_hours_by_department' => [
            'class' => 'TimeEngagementReports',
            'method' => 'learningHoursByDepartment',
            'name' => 'Learning Hours by Department',
            'category' => 'time',
            'description' => 'Total learning hours by department',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],
        'peak_usage_report' => [
            'class' => 'TimeEngagementReports',
            'method' => 'peakUsageReport',
            'name' => 'Peak Usage Report',
            'category' => 'time',
            'description' => 'When learners are most active',
            'audience' => ['admin'],
            'exportable' => true
        ],
        'session_duration_report' => [
            'class' => 'TimeEngagementReports',
            'method' => 'sessionDurationReport',
            'name' => 'Session Duration Report',
            'category' => 'time',
            'description' => 'Learning session duration statistics',
            'audience' => ['admin'],
            'exportable' => true
        ],
        'monthly_training_hours' => [
            'class' => 'TimeEngagementReports',
            'method' => 'monthlyTrainingHours',
            'name' => 'Monthly Training Hours',
            'category' => 'time',
            'description' => 'Training hours trend by month',
            'audience' => ['admin', 'hr'],
            'params' => ['months'],
            'exportable' => true
        ],
        'engagement_score_report' => [
            'class' => 'TimeEngagementReports',
            'method' => 'engagementScoreReport',
            'name' => 'Engagement Score Report',
            'category' => 'time',
            'description' => 'User engagement levels',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],

        // Gamification Reports
        'leaderboard_report' => [
            'class' => 'GamificationReports',
            'method' => 'leaderboardReport',
            'name' => 'Leaderboard Report',
            'category' => 'gamification',
            'description' => 'Top learners by points',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],
        'badge_earned_report' => [
            'class' => 'GamificationReports',
            'method' => 'badgeEarnedReport',
            'name' => 'Badge Earned Report',
            'category' => 'gamification',
            'description' => 'Recently earned badges',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],
        'points_summary_report' => [
            'class' => 'GamificationReports',
            'method' => 'pointsSummaryReport',
            'name' => 'Points Summary Report',
            'category' => 'gamification',
            'description' => 'Points earned by users',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],
        'achievement_milestones' => [
            'class' => 'GamificationReports',
            'method' => 'achievementMilestones',
            'name' => 'Achievement Milestones',
            'category' => 'gamification',
            'description' => 'Milestone achievements',
            'audience' => ['admin'],
            'exportable' => true
        ],
        'gamification_participation' => [
            'class' => 'GamificationReports',
            'method' => 'gamificationParticipation',
            'name' => 'Gamification Participation',
            'category' => 'gamification',
            'description' => 'Overall gamification engagement',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],
        'department_leaderboard' => [
            'class' => 'GamificationReports',
            'method' => 'departmentLeaderboard',
            'name' => 'Department Leaderboard',
            'category' => 'gamification',
            'description' => 'Departments ranked by points',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],

        // Certificate Reports
        'certificates_issued_report' => [
            'class' => 'CertificateReports',
            'method' => 'certificatesIssuedReport',
            'name' => 'Certificates Issued Report',
            'category' => 'certificate',
            'description' => 'All issued certificates',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],
        'certificate_verification_log' => [
            'class' => 'CertificateReports',
            'method' => 'certificateVerificationLog',
            'name' => 'Certificate Verification Log',
            'category' => 'certificate',
            'description' => 'Certificate verification activity',
            'audience' => ['admin'],
            'exportable' => true
        ],
        'expiring_certificates_report' => [
            'class' => 'CertificateReports',
            'method' => 'expiringCertificatesReport',
            'name' => 'Expiring Certificates Report',
            'category' => 'certificate',
            'description' => 'Certificates expiring soon',
            'audience' => ['admin', 'hr'],
            'params' => ['days_ahead'],
            'exportable' => true
        ],
        'certificate_reprint_history' => [
            'class' => 'CertificateReports',
            'method' => 'certificateReprintHistory',
            'name' => 'Certificate Reprint History',
            'category' => 'certificate',
            'description' => 'Certificate reprint log',
            'audience' => ['admin'],
            'exportable' => true
        ],
        'certificates_by_course' => [
            'class' => 'CertificateReports',
            'method' => 'certificatesByCourse',
            'name' => 'Certificates by Course',
            'category' => 'certificate',
            'description' => 'Certificate distribution by course',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],

        // System Reports
        'system_usage_report' => [
            'class' => 'SystemReports',
            'method' => 'systemUsageReport',
            'name' => 'System Usage Report',
            'category' => 'system',
            'description' => 'Overall system usage statistics',
            'audience' => ['admin'],
            'exportable' => true
        ],
        'error_issue_log' => [
            'class' => 'SystemReports',
            'method' => 'errorIssueLog',
            'name' => 'Error & Issue Log',
            'category' => 'system',
            'description' => 'System errors and issues',
            'audience' => ['admin'],
            'exportable' => true
        ],
        'admin_activity_log' => [
            'class' => 'SystemReports',
            'method' => 'adminActivityLog',
            'name' => 'Admin Activity Log',
            'category' => 'system',
            'description' => 'Administrator activity history',
            'audience' => ['admin'],
            'exportable' => true
        ],
        'storage_usage_report' => [
            'class' => 'SystemReports',
            'method' => 'storageUsageReport',
            'name' => 'Storage Usage Report',
            'category' => 'system',
            'description' => 'Storage consumption details',
            'audience' => ['admin'],
            'exportable' => true
        ],
        'integration_sync_report' => [
            'class' => 'SystemReports',
            'method' => 'integrationSyncReport',
            'name' => 'Integration Sync Report',
            'category' => 'system',
            'description' => 'External integration sync status',
            'audience' => ['admin'],
            'exportable' => true
        ],
        'tenant_usage_report' => [
            'class' => 'SystemReports',
            'method' => 'tenantUsageReport',
            'name' => 'Tenant Usage Report',
            'category' => 'system',
            'description' => 'Multi-tenant usage overview',
            'audience' => ['superadmin'],
            'exportable' => true
        ],

        // Executive Reports
        'executive_training_dashboard' => [
            'class' => 'ExecutiveReports',
            'method' => 'executiveTrainingDashboard',
            'name' => 'Executive Training Dashboard',
            'category' => 'executive',
            'description' => 'High-level KPIs and trends',
            'audience' => ['admin', 'hr', 'executive'],
            'exportable' => true
        ],
        'monthly_training_summary' => [
            'class' => 'ExecutiveReports',
            'method' => 'monthlyTrainingSummary',
            'name' => 'Monthly Training Summary',
            'category' => 'executive',
            'description' => 'Monthly training metrics summary',
            'audience' => ['admin', 'hr', 'executive'],
            'params' => ['month'],
            'exportable' => true
        ],
        'training_roi_report' => [
            'class' => 'ExecutiveReports',
            'method' => 'trainingROIReport',
            'name' => 'Training ROI Report',
            'category' => 'executive',
            'description' => 'Return on training investment analysis',
            'audience' => ['admin', 'hr', 'executive'],
            'exportable' => true
        ],
        'year_over_year_comparison' => [
            'class' => 'ExecutiveReports',
            'method' => 'yearOverYearComparison',
            'name' => 'Year-over-Year Comparison',
            'category' => 'executive',
            'description' => 'Compare metrics across years',
            'audience' => ['admin', 'hr', 'executive'],
            'exportable' => true
        ],
        'training_budget_utilization' => [
            'class' => 'ExecutiveReports',
            'method' => 'trainingBudgetUtilization',
            'name' => 'Training Budget Utilization',
            'category' => 'executive',
            'description' => 'Budget spending analysis',
            'audience' => ['admin', 'hr', 'executive'],
            'exportable' => true
        ],

        // Audit Reports
        'full_audit_trail' => [
            'class' => 'AuditReports',
            'method' => 'fullAuditTrail',
            'name' => 'Full Audit Trail',
            'category' => 'audit',
            'description' => 'Complete system audit log',
            'audience' => ['admin'],
            'exportable' => true
        ],
        'data_access_log' => [
            'class' => 'AuditReports',
            'method' => 'dataAccessLog',
            'name' => 'Data Access Log',
            'category' => 'audit',
            'description' => 'Who accessed what data',
            'audience' => ['admin'],
            'exportable' => true
        ],
        'gdpr_data_privacy_report' => [
            'class' => 'AuditReports',
            'method' => 'gdprDataPrivacyReport',
            'name' => 'GDPR/Data Privacy Report',
            'category' => 'audit',
            'description' => 'Privacy compliance status',
            'audience' => ['admin'],
            'exportable' => true
        ],
        'training_records_export' => [
            'class' => 'AuditReports',
            'method' => 'trainingRecordsExport',
            'name' => 'Training Records Export',
            'category' => 'audit',
            'description' => 'Complete training record for a user',
            'audience' => ['admin', 'hr'],
            'params' => ['user_id'],
            'exportable' => true
        ],
        'compliance_training_audit_trail' => [
            'class' => 'AuditReports',
            'method' => 'complianceTrainingAuditTrail',
            'name' => 'Compliance Training Audit Trail',
            'category' => 'audit',
            'description' => 'Audit trail for compliance training',
            'audience' => ['admin', 'hr'],
            'exportable' => true
        ],
        'security_audit_report' => [
            'class' => 'AuditReports',
            'method' => 'securityAuditReport',
            'name' => 'Security Audit Report',
            'category' => 'audit',
            'description' => 'Security-related activity',
            'audience' => ['admin'],
            'exportable' => true
        ],

        // Learner Reports
        'my_learning_summary' => [
            'class' => 'LearnerReports',
            'method' => 'myLearningSummary',
            'name' => 'My Learning Summary',
            'category' => 'learner',
            'description' => 'Personal learning overview',
            'audience' => ['learner'],
            'exportable' => true
        ],
        'my_enrolled_courses' => [
            'class' => 'LearnerReports',
            'method' => 'myEnrolledCourses',
            'name' => 'My Enrolled Courses',
            'category' => 'learner',
            'description' => 'All enrolled courses',
            'audience' => ['learner'],
            'exportable' => true
        ],
        'my_completed_courses' => [
            'class' => 'LearnerReports',
            'method' => 'myCompletedCourses',
            'name' => 'My Completed Courses',
            'category' => 'learner',
            'description' => 'All completed courses',
            'audience' => ['learner'],
            'exportable' => true
        ],
        'my_assessment_results' => [
            'class' => 'LearnerReports',
            'method' => 'myAssessmentResults',
            'name' => 'My Assessment Results',
            'category' => 'learner',
            'description' => 'Personal assessment history',
            'audience' => ['learner'],
            'exportable' => true
        ],
        'my_certificates' => [
            'class' => 'LearnerReports',
            'method' => 'myCertificates',
            'name' => 'My Certificates',
            'category' => 'learner',
            'description' => 'Earned certificates',
            'audience' => ['learner'],
            'exportable' => true
        ],
        'my_badges' => [
            'class' => 'LearnerReports',
            'method' => 'myBadges',
            'name' => 'My Badges',
            'category' => 'learner',
            'description' => 'Earned badges',
            'audience' => ['learner'],
            'exportable' => true
        ],
        'my_compliance_status' => [
            'class' => 'LearnerReports',
            'method' => 'myComplianceStatus',
            'name' => 'My Compliance Status',
            'category' => 'learner',
            'description' => 'Personal compliance training status',
            'audience' => ['learner'],
            'exportable' => true
        ],
        'my_learning_time' => [
            'class' => 'LearnerReports',
            'method' => 'myLearningTime',
            'name' => 'My Learning Time',
            'category' => 'learner',
            'description' => 'Personal learning time analytics',
            'audience' => ['learner'],
            'params' => ['days'],
            'exportable' => true
        ],
        'my_leaderboard_position' => [
            'class' => 'LearnerReports',
            'method' => 'myLeaderboardPosition',
            'name' => 'My Leaderboard Position',
            'category' => 'learner',
            'description' => 'Ranking among peers',
            'audience' => ['learner'],
            'exportable' => false
        ],
        'my_training_transcript' => [
            'class' => 'LearnerReports',
            'method' => 'myTrainingTranscript',
            'name' => 'My Training Transcript',
            'category' => 'learner',
            'description' => 'Official training transcript',
            'audience' => ['learner'],
            'exportable' => true
        ],
        'my_upcoming_deadlines' => [
            'class' => 'LearnerReports',
            'method' => 'myUpcomingDeadlines',
            'name' => 'My Upcoming Deadlines',
            'category' => 'learner',
            'description' => 'Upcoming training deadlines',
            'audience' => ['learner'],
            'params' => ['days'],
            'exportable' => true
        ]
    ];

    public function __construct(mysqli $db) {
        $this->conn = $db;
    }

    public function setContext(int $tenant_id, ?int $user_id = null): self {
        $this->tenant_id = $tenant_id;
        $this->user_id = $user_id;
        return $this;
    }

    /**
     * Get list of available reports
     */
    public function getReportCatalog(?string $category = null, ?string $audience = null): array {
        $reports = [];
        foreach ($this->reportCatalog as $key => $report) {
            if ($category && $report['category'] !== $category) continue;
            if ($audience && !in_array($audience, $report['audience'])) continue;

            $reports[] = [
                'report_key' => $key,
                'name' => $report['name'],
                'category' => $report['category'],
                'description' => $report['description'],
                'audience' => $report['audience'],
                'params' => $report['params'] ?? [],
                'exportable' => $report['exportable']
            ];
        }
        return $reports;
    }

    /**
     * Get report categories
     */
    public function getCategories(): array {
        return [
            ['key' => 'enrollment', 'name' => 'Enrollment & Participation', 'icon' => 'bi-person-plus'],
            ['key' => 'completion', 'name' => 'Completion & Progress', 'icon' => 'bi-check-circle'],
            ['key' => 'compliance', 'name' => 'Compliance & Mandatory', 'icon' => 'bi-shield-check'],
            ['key' => 'assessment', 'name' => 'Assessment & Performance', 'icon' => 'bi-clipboard-data'],
            ['key' => 'user', 'name' => 'User & Department', 'icon' => 'bi-people'],
            ['key' => 'course', 'name' => 'Course & Content', 'icon' => 'bi-book'],
            ['key' => 'time', 'name' => 'Time & Engagement', 'icon' => 'bi-clock'],
            ['key' => 'gamification', 'name' => 'Gamification & Achievement', 'icon' => 'bi-trophy'],
            ['key' => 'certificate', 'name' => 'Certificate Reports', 'icon' => 'bi-award'],
            ['key' => 'system', 'name' => 'System & Admin', 'icon' => 'bi-gear'],
            ['key' => 'executive', 'name' => 'Executive & Summary', 'icon' => 'bi-graph-up'],
            ['key' => 'audit', 'name' => 'Audit & Compliance', 'icon' => 'bi-journal-text'],
            ['key' => 'learner', 'name' => 'My Reports', 'icon' => 'bi-person-badge']
        ];
    }

    /**
     * Execute a report
     */
    public function runReport(string $reportKey, array $params = []): array {
        if (!isset($this->reportCatalog[$reportKey])) {
            return ['error' => 'Report not found', 'report_key' => $reportKey];
        }

        $config = $this->reportCatalog[$reportKey];
        $instance = $this->getReportInstance($config['class']);

        // Configure common settings
        if (isset($params['date_from']) || isset($params['date_to'])) {
            $instance->setDateRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        }
        if (isset($params['page']) || isset($params['per_page'])) {
            $instance->setPagination($params['page'] ?? 1, $params['per_page'] ?? 25);
        }
        if (isset($params['sort_by'])) {
            $instance->setSorting($params['sort_by'], $params['sort_dir'] ?? 'DESC');
        }
        if (isset($params['filters'])) {
            $instance->setFilters($params['filters']);
        }

        // Get method parameters
        $method = $config['method'];
        $methodParams = [];

        // Map params to method arguments
        if (!empty($config['params'])) {
            foreach ($config['params'] as $paramName) {
                if (isset($params[$paramName])) {
                    $methodParams[] = $params[$paramName];
                }
            }
        }

        try {
            $result = empty($methodParams) 
                ? $instance->$method() 
                : $instance->$method(...$methodParams);

            return [
                'success' => true,
                'report_key' => $reportKey,
                'report_name' => $config['name'],
                'category' => $config['category'],
                'generated_at' => date('Y-m-d H:i:s'),
                'result' => $result
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'report_key' => $reportKey
            ];
        }
    }

    /**
     * Export report to CSV
     */
    public function exportReport(string $reportKey, array $params = []): array {
        $result = $this->runReport($reportKey, $params);

        if (!$result['success']) {
            return $result;
        }

        $data = $result['result']['data'] ?? [];
        if (empty($data)) {
            return ['error' => 'No data to export'];
        }

        $csv = $this->arrayToCsv($data);

        return [
            'success' => true,
            'filename' => $reportKey . '_' . date('Y-m-d_His') . '.csv',
            'content' => $csv,
            'content_type' => 'text/csv'
        ];
    }

    /**
     * Get report instance (cached)
     */
    private function getReportInstance(string $className): BaseReport {
        if (!isset($this->reportInstances[$className])) {
            $instance = new $className($this->conn);
            $instance->setContext($this->tenant_id);

            if ($className === 'LearnerReports' && $this->user_id) {
                $instance->setUser($this->user_id);
            }

            $this->reportInstances[$className] = $instance;
        }

        return $this->reportInstances[$className];
    }

    /**
     * Convert array to CSV string
     */
    private function arrayToCsv(array $data): string {
        if (empty($data)) return '';

        $output = fopen('php://temp', 'r+');
        fputcsv($output, array_keys($data[0]));

        foreach ($data as $row) {
            $flatRow = array_map(function($val) {
                if (is_array($val)) return json_encode($val);
                if (is_bool($val)) return $val ? 'Yes' : 'No';
                return $val;
            }, $row);
            fputcsv($output, $flatRow);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Get dashboard summary with key metrics
     */
    public function getDashboardSummary(): array {
        $summaries = [];

        $classes = [
            'enrollment' => 'EnrollmentReports',
            'completion' => 'CompletionReports',
            'compliance' => 'ComplianceReports',
            'assessment' => 'AssessmentReports',
            'gamification' => 'GamificationReports'
        ];

        foreach ($classes as $key => $class) {
            try {
                $instance = $this->getReportInstance($class);
                $summaries[$key] = $instance->getSummary();
            } catch (Exception $e) {
                $summaries[$key] = ['error' => $e->getMessage()];
            }
        }

        return $summaries;
    }

    /**
     * Schedule a report for email delivery
     */
    public function scheduleReport(string $reportKey, array $schedule): array {
        // This would integrate with a scheduler/queue system
        return [
            'scheduled' => true,
            'report_key' => $reportKey,
            'schedule' => $schedule
        ];
    }
}
