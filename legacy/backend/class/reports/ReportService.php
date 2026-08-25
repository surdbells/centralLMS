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
require_once __DIR__ . '/DrilldownReports.php';
require_once __DIR__ . '/LearningPathReports.php';
require_once __DIR__ . '/ILTReports.php';
require_once __DIR__ . '/CustomReportBuilder.php';
require_once __DIR__ . '/OnboardingReports.php';
require_once __DIR__ . '/SkillsReports.php';
require_once __DIR__ . '/FeedbackReports.php';
require_once __DIR__ . '/RetentionReports.php';
require_once __DIR__ . '/ManagerReports.php';
require_once __DIR__ . '/CohortCostReports.php';
require_once __DIR__ . '/ContentReports.php';
require_once __DIR__ . '/ReportScheduler.php';

/**
 * ReportService - Factory and facade for all LMS reports
 *
 * Phase 1: Foundation org-unit filter infrastructure
 * Phase 2: 12 broken reports rewritten across 8 classes (88 → 100)
 * Phase 3/3b: Executive compliance drilldown + drilldown/profiles (→ 109)
 * Phase 3c: Multi-format export (CSV + XLSX + PDF)
 * Phase 4: Org-unit filter retrofit to 60 existing methods
 * Phase 5: Learning Path (5) + ILT (6) → 120 total
 * Phase 6: Learner self-service expanded 11 → 44 across 9 sub-categories → 153 total
 * Phase 7: Custom Report Builder — ad-hoc report creation, save/load, 8 data sources
 * Phase 8: Dropdown Data Endpoints — enhanced search/pagination/cascading filters for all dropdowns
 *
 * Features:
 *  - runReport() wires org-unit filters + learner filter params
 *  - exportReport() supports csv, xlsx, pdf formats
 *  - getOrgUnits(), getCoursesList(), getLearnersList(), getManagersList(), getAssessmentsList()
 *  - getReportInstance() resets org filters on cached instances to prevent leaking
 *  - Learner sub-categories with parent 'learner' for UI grouping
 */
class ReportService {
    private mysqli $conn;
    private int $tenant_id;
    private ?int $user_id = null;
    private array $reportInstances = [];

    private array $reportCatalog = [
        // Enrollment (5)
        'course_enrollment_summary' => ['class' => 'EnrollmentReports', 'method' => 'courseEnrollmentSummary', 'name' => 'Course Enrollment Summary', 'category' => 'enrollment', 'description' => 'Overview of enrollments across all courses', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'enrollment_trends' => ['class' => 'EnrollmentReports', 'method' => 'enrollmentTrends', 'name' => 'Enrollment Trends', 'category' => 'enrollment', 'description' => 'Enrollment patterns over time', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'pending_enrollments' => ['class' => 'EnrollmentReports', 'method' => 'pendingEnrollments', 'name' => 'Pending Enrollments', 'category' => 'enrollment', 'description' => 'Users who have not started their assigned courses', 'audience' => ['admin', 'hr', 'manager'], 'exportable' => true],
        'self_enrollment_report' => ['class' => 'EnrollmentReports', 'method' => 'selfEnrollmentReport', 'name' => 'Self-Enrollment Report', 'category' => 'enrollment', 'description' => 'Courses with voluntary enrollments', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'waitlist_report' => ['class' => 'EnrollmentReports', 'method' => 'waitlistReport', 'name' => 'Waitlist Report', 'category' => 'enrollment', 'description' => 'Courses with capacity limits and waitlists', 'audience' => ['admin'], 'exportable' => true],
        'enrollment_by_source' => ['class' => 'EnrollmentReports', 'method' => 'enrollmentBySource', 'name' => 'Enrollment by Source', 'category' => 'enrollment', 'description' => 'Enrollment breakdown by source (admin, self, bulk, manager)', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'drop_off_report' => ['class' => 'EnrollmentReports', 'method' => 'dropOffReport', 'name' => 'Drop-off Report', 'category' => 'enrollment', 'description' => 'Learners who enrolled but never started with risk levels', 'audience' => ['admin', 'hr', 'manager'], 'exportable' => true],

        // Completion (7)
        'course_completion_summary' => ['class' => 'CompletionReports', 'method' => 'courseCompletionSummary', 'name' => 'Course Completion Summary', 'category' => 'completion', 'description' => 'Completion rates and statistics by course', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'individual_progress_report' => ['class' => 'CompletionReports', 'method' => 'individualProgressReport', 'name' => 'Individual Progress Report', 'category' => 'completion', 'description' => 'Detailed progress for a specific learner', 'audience' => ['admin', 'hr', 'manager'], 'params' => ['learner_id'], 'exportable' => true],
        'incomplete_courses_report' => ['class' => 'CompletionReports', 'method' => 'incompleteCoursesReport', 'name' => 'Incomplete Courses Report', 'category' => 'completion', 'description' => 'All incomplete enrollments with progress details', 'audience' => ['admin', 'hr', 'manager'], 'exportable' => true],
        'completion_by_department' => ['class' => 'CompletionReports', 'method' => 'completionByDepartment', 'name' => 'Completion by Department', 'category' => 'completion', 'description' => 'Training completion metrics grouped by department', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'completion_by_manager' => ['class' => 'CompletionReports', 'method' => 'completionByManager', 'name' => 'Completion by Manager', 'category' => 'completion', 'description' => 'Team completion metrics grouped by manager', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'time_to_completion_analysis' => ['class' => 'CompletionReports', 'method' => 'timeToCompletionAnalysis', 'name' => 'Time to Completion Analysis', 'category' => 'completion', 'description' => 'How long learners take to complete courses', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'overdue_training_report' => ['class' => 'CompletionReports', 'method' => 'overdueTrainingReport', 'name' => 'Overdue Training Report', 'category' => 'completion', 'description' => 'All overdue training assignments', 'audience' => ['admin', 'hr', 'manager'], 'exportable' => true],
        'completion_trend' => ['class' => 'CompletionReports', 'method' => 'completionTrend', 'name' => 'Completion Trend', 'category' => 'completion', 'description' => 'Monthly completion volume and averages over time', 'audience' => ['admin', 'hr'], 'exportable' => true],

        // Compliance (7)
        'compliance_status_dashboard' => ['class' => 'ComplianceReports', 'method' => 'complianceStatusDashboard', 'name' => 'Compliance Status Dashboard', 'category' => 'compliance', 'description' => 'Overall compliance status overview', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'mandatory_training_compliance' => ['class' => 'ComplianceReports', 'method' => 'mandatoryTrainingCompliance', 'name' => 'Mandatory Training Compliance', 'category' => 'compliance', 'description' => 'User-level mandatory training status', 'audience' => ['admin', 'hr', 'manager'], 'exportable' => true],
        'certification_expiry_report' => ['class' => 'ComplianceReports', 'method' => 'certificationExpiryReport', 'name' => 'Certification Expiry Report', 'category' => 'compliance', 'description' => 'Certificates expiring within specified period', 'audience' => ['admin', 'hr'], 'params' => ['days_ahead'], 'exportable' => true],
        'recertification_due_report' => ['class' => 'ComplianceReports', 'method' => 'recertificationDueReport', 'name' => 'Recertification Due Report', 'category' => 'compliance', 'description' => 'Users needing to recertify', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'compliance_by_department' => ['class' => 'ComplianceReports', 'method' => 'complianceByDepartment', 'name' => 'Compliance by Department', 'category' => 'compliance', 'description' => 'Department-level compliance metrics', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'regulatory_training_audit' => ['class' => 'ComplianceReports', 'method' => 'regulatoryTrainingAudit', 'name' => 'Regulatory Training Audit', 'category' => 'compliance', 'description' => 'Detailed audit trail for mandatory training', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'non_compliant_users_report' => ['class' => 'ComplianceReports', 'method' => 'nonCompliantUsersReport', 'name' => 'Non-Compliant Users Report', 'category' => 'compliance', 'description' => 'Users with overdue mandatory training', 'audience' => ['admin', 'hr', 'manager'], 'exportable' => true],
        'compliance_gap_analysis' => ['class' => 'ComplianceReports', 'method' => 'complianceGapAnalysis', 'name' => 'Compliance Gap Analysis', 'category' => 'compliance', 'description' => 'Mandatory training coverage gaps by department with severity levels', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'compliance_executive_summary' => ['class' => 'ComplianceReports', 'method' => 'complianceExecutiveSummary', 'name' => 'Compliance Executive Summary', 'category' => 'compliance', 'description' => 'Hierarchical compliance drilldown: org → branch → department → team → learner', 'audience' => ['admin', 'hr', 'executive'], 'exportable' => true],
        'compliance_by_branch' => ['class' => 'ComplianceReports', 'method' => 'complianceByBranch', 'name' => 'Compliance by Branch', 'category' => 'compliance', 'description' => 'Branch-level mandatory training compliance metrics', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'compliance_by_team' => ['class' => 'ComplianceReports', 'method' => 'complianceByTeam', 'name' => 'Compliance by Team', 'category' => 'compliance', 'description' => 'Team-level mandatory training compliance with department and branch context', 'audience' => ['admin', 'hr', 'manager'], 'exportable' => true],

        // Assessment (8)
        'assessment_results_summary' => ['class' => 'AssessmentReports', 'method' => 'assessmentResultsSummary', 'name' => 'Assessment Results Summary', 'category' => 'assessment', 'description' => 'Overall assessment performance metrics', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'individual_assessment_report' => ['class' => 'AssessmentReports', 'method' => 'individualAssessmentReport', 'name' => 'Individual Assessment Report', 'category' => 'assessment', 'description' => 'Assessment history for a specific learner', 'audience' => ['admin', 'hr', 'manager'], 'params' => ['learner_id'], 'exportable' => true],
        'failed_assessments_report' => ['class' => 'AssessmentReports', 'method' => 'failedAssessmentsReport', 'name' => 'Failed Assessments Report', 'category' => 'assessment', 'description' => 'Learners who failed their most recent attempt', 'audience' => ['admin', 'hr', 'manager'], 'exportable' => true],
        'question_analysis_report' => ['class' => 'AssessmentReports', 'method' => 'questionAnalysisReport', 'name' => 'Question Analysis Report', 'category' => 'assessment', 'description' => 'Question-level performance analysis', 'audience' => ['admin'], 'params' => ['assessment_id'], 'exportable' => true],
        'score_distribution_report' => ['class' => 'AssessmentReports', 'method' => 'scoreDistributionReport', 'name' => 'Score Distribution Report', 'category' => 'assessment', 'description' => 'Distribution of scores across assessments', 'audience' => ['admin', 'hr'], 'params' => ['assessment_id'], 'exportable' => true],
        'assessment_attempts_report' => ['class' => 'AssessmentReports', 'method' => 'assessmentAttemptsReport', 'name' => 'Assessment Attempts Report', 'category' => 'assessment', 'description' => 'Number of attempts per assessment', 'audience' => ['admin'], 'exportable' => true],
        'top_performers_report' => ['class' => 'AssessmentReports', 'method' => 'topPerformersReport', 'name' => 'Top Performers Report', 'category' => 'assessment', 'description' => 'Highest scoring learners', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'struggling_learners_report' => ['class' => 'AssessmentReports', 'method' => 'strugglingLearnersReport', 'name' => 'Struggling Learners Report', 'category' => 'assessment', 'description' => 'Learners needing additional support', 'audience' => ['admin', 'hr', 'manager'], 'exportable' => true],
        'proctoring_violation_report' => ['class' => 'AssessmentReports', 'method' => 'proctoringViolationReport', 'name' => 'Proctoring Violation Report', 'category' => 'assessment', 'description' => 'Flagged proctoring violations with severity and resolution status', 'audience' => ['admin'], 'exportable' => true],
        'question_difficulty_index' => ['class' => 'AssessmentReports', 'method' => 'questionDifficultyIndex', 'name' => 'Question Difficulty Index', 'category' => 'assessment', 'description' => 'Question-level difficulty and discrimination analysis', 'audience' => ['admin'], 'params' => ['assessment_id'], 'exportable' => true],

        // User (8)
        'active_users_report' => ['class' => 'UserReports', 'method' => 'activeUsersReport', 'name' => 'Active Users Report', 'category' => 'user', 'description' => 'Recently active learners', 'audience' => ['admin', 'hr'], 'params' => ['days'], 'exportable' => true],
        'inactive_users_report' => ['class' => 'UserReports', 'method' => 'inactiveUsersReport', 'name' => 'Inactive Users Report', 'category' => 'user', 'description' => 'Users who have not logged in recently', 'audience' => ['admin', 'hr'], 'params' => ['days'], 'exportable' => true],
        'new_users_report' => ['class' => 'UserReports', 'method' => 'newUsersReport', 'name' => 'New Users Report', 'category' => 'user', 'description' => 'Recently onboarded users', 'audience' => ['admin', 'hr'], 'params' => ['days'], 'exportable' => true],
        'user_activity_summary' => ['class' => 'UserReports', 'method' => 'userActivitySummary', 'name' => 'User Activity Summary', 'category' => 'user', 'description' => 'Detailed activity for a specific user', 'audience' => ['admin', 'hr', 'manager'], 'params' => ['user_id'], 'exportable' => true],
        'department_training_summary' => ['class' => 'UserReports', 'method' => 'departmentTrainingSummary', 'name' => 'Department Training Summary', 'category' => 'user', 'description' => 'Training metrics by department', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'managers_team_report' => ['class' => 'UserReports', 'method' => 'managersTeamReport', 'name' => 'Managers Team Report', 'category' => 'user', 'description' => 'Team training status for a manager', 'audience' => ['admin', 'hr', 'manager'], 'params' => ['manager_id'], 'exportable' => true],
        'user_login_history' => ['class' => 'UserReports', 'method' => 'userLoginHistory', 'name' => 'User Login History', 'category' => 'user', 'description' => 'Login activity log', 'audience' => ['admin'], 'params' => ['user_id'], 'exportable' => true],
        'role_based_training_report' => ['class' => 'UserReports', 'method' => 'roleBasedTrainingReport', 'name' => 'Role-based Training Report', 'category' => 'user', 'description' => 'Training metrics by job role', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'department_profile' => ['class' => 'UserReports', 'method' => 'departmentProfile', 'name' => 'Department Profile', 'category' => 'user', 'description' => 'Complete department overview: compliance, courses, learners', 'audience' => ['admin', 'hr', 'manager'], 'exportable' => true, 'params' => ['department_id']],
        'branch_profile' => ['class' => 'UserReports', 'method' => 'branchProfile', 'name' => 'Branch Profile', 'category' => 'user', 'description' => 'Complete branch overview: compliance, departments, courses, overdue learners', 'audience' => ['admin', 'hr'], 'exportable' => true, 'params' => ['branch_id']],
        'team_profile' => ['class' => 'UserReports', 'method' => 'teamProfile', 'name' => 'Team Profile', 'category' => 'user', 'description' => 'Complete team overview: compliance, courses, individual member stats', 'audience' => ['admin', 'hr', 'manager'], 'exportable' => true, 'params' => ['team_id']],
        'drilldown_learner_list' => ['class' => 'DrilldownReports', 'method' => 'drilldownLearnerList', 'name' => 'Drilldown Learner List', 'category' => 'drilldown', 'description' => 'Universal learner-level drilldown from any summary report, filterable by status and all org dimensions', 'audience' => ['admin', 'hr', 'manager'], 'exportable' => true, 'params' => ['status']],

        // Course (8)
        'course_catalog_report' => ['class' => 'CourseReports', 'method' => 'courseCatalogReport', 'name' => 'Course Catalog Report', 'category' => 'course', 'description' => 'Complete course catalog with metrics', 'audience' => ['admin'], 'exportable' => true],
        'course_effectiveness_report' => ['class' => 'CourseReports', 'method' => 'courseEffectivenessReport', 'name' => 'Course Effectiveness Report', 'category' => 'course', 'description' => 'Course performance and effectiveness scores', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'course_usage_report' => ['class' => 'CourseReports', 'method' => 'courseUsageReport', 'name' => 'Course Usage Report', 'category' => 'course', 'description' => 'How courses are being used', 'audience' => ['admin'], 'exportable' => true],
        'module_performance_report' => ['class' => 'CourseReports', 'method' => 'modulePerformanceReport', 'name' => 'Module Performance Report', 'category' => 'course', 'description' => 'Performance breakdown by module', 'audience' => ['admin'], 'params' => ['course_id'], 'exportable' => true],
        'content_engagement_report' => ['class' => 'CourseReports', 'method' => 'contentEngagementReport', 'name' => 'Content Engagement Report', 'category' => 'course', 'description' => 'Lesson-level engagement metrics', 'audience' => ['admin'], 'exportable' => true],
        'course_ratings_feedback' => ['class' => 'CourseReports', 'method' => 'courseRatingsFeedback', 'name' => 'Course Ratings and Feedback', 'category' => 'course', 'description' => 'Course ratings and review summary', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'unused_courses_report' => ['class' => 'CourseReports', 'method' => 'unusedCoursesReport', 'name' => 'Unused Courses Report', 'category' => 'course', 'description' => 'Courses with no recent activity', 'audience' => ['admin'], 'params' => ['days'], 'exportable' => true],
        'course_version_history' => ['class' => 'CourseReports', 'method' => 'courseVersionHistory', 'name' => 'Course Version History', 'category' => 'course', 'description' => 'Version history for a course', 'audience' => ['admin'], 'params' => ['course_id'], 'exportable' => true],
        'scorm_xapi_activity_report' => ['class' => 'CourseReports', 'method' => 'scormXapiActivityReport', 'name' => 'SCORM/xAPI Activity Report', 'category' => 'course', 'description' => 'Learning session activity grouped by course and content type', 'audience' => ['admin'], 'exportable' => true],
        'course_profile' => ['class' => 'CourseReports', 'method' => 'courseProfile', 'name' => 'Course Profile', 'category' => 'course', 'description' => 'Complete single-course overview with org breakdowns, stats, and learner list', 'audience' => ['admin', 'hr', 'manager'], 'exportable' => true, 'params' => ['course_id']],
        'course_learner_roster' => ['class' => 'CourseReports', 'method' => 'courseLearnerRoster', 'name' => 'Course Learner Roster', 'category' => 'course', 'description' => 'Full paginated learner list for a course with all enrollment details', 'audience' => ['admin', 'hr', 'manager'], 'exportable' => true, 'params' => ['course_id']],

        // Time & Engagement (6)
        'time_spent_report' => ['class' => 'TimeEngagementReports', 'method' => 'timeSpentReport', 'name' => 'Time Spent Report', 'category' => 'time', 'description' => 'Learning time by user', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'learning_hours_by_department' => ['class' => 'TimeEngagementReports', 'method' => 'learningHoursByDepartment', 'name' => 'Learning Hours by Department', 'category' => 'time', 'description' => 'Total learning hours by department', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'peak_usage_report' => ['class' => 'TimeEngagementReports', 'method' => 'peakUsageReport', 'name' => 'Peak Usage Report', 'category' => 'time', 'description' => 'When learners are most active', 'audience' => ['admin'], 'exportable' => true],
        'session_duration_report' => ['class' => 'TimeEngagementReports', 'method' => 'sessionDurationReport', 'name' => 'Session Duration Report', 'category' => 'time', 'description' => 'Learning session duration statistics', 'audience' => ['admin'], 'exportable' => true],
        'monthly_training_hours' => ['class' => 'TimeEngagementReports', 'method' => 'monthlyTrainingHours', 'name' => 'Monthly Training Hours', 'category' => 'time', 'description' => 'Training hours trend by month', 'audience' => ['admin', 'hr'], 'params' => ['months'], 'exportable' => true],
        'engagement_score_report' => ['class' => 'TimeEngagementReports', 'method' => 'engagementScoreReport', 'name' => 'Engagement Score Report', 'category' => 'time', 'description' => 'User engagement levels', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'daily_weekly_activity_report' => ['class' => 'TimeEngagementReports', 'method' => 'dailyWeeklyActivityReport', 'name' => 'Daily/Weekly Activity Report', 'category' => 'time', 'description' => 'Daily login and learning session activity trends', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'mobile_vs_desktop_usage' => ['class' => 'TimeEngagementReports', 'method' => 'mobileVsDesktopUsage', 'name' => 'Mobile vs Desktop Usage', 'category' => 'time', 'description' => 'Platform breakdown by device type', 'audience' => ['admin'], 'exportable' => true],

        // Gamification (6)
        'leaderboard_report' => ['class' => 'GamificationReports', 'method' => 'leaderboardReport', 'name' => 'Leaderboard Report', 'category' => 'gamification', 'description' => 'Top learners by points', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'badge_earned_report' => ['class' => 'GamificationReports', 'method' => 'badgeEarnedReport', 'name' => 'Badge Earned Report', 'category' => 'gamification', 'description' => 'Recently earned badges', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'points_summary_report' => ['class' => 'GamificationReports', 'method' => 'pointsSummaryReport', 'name' => 'Points Summary Report', 'category' => 'gamification', 'description' => 'Points earned by users', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'achievement_milestones' => ['class' => 'GamificationReports', 'method' => 'achievementMilestones', 'name' => 'Achievement Milestones', 'category' => 'gamification', 'description' => 'Milestone achievements', 'audience' => ['admin'], 'exportable' => true],
        'gamification_participation' => ['class' => 'GamificationReports', 'method' => 'gamificationParticipation', 'name' => 'Gamification Participation', 'category' => 'gamification', 'description' => 'Overall gamification engagement', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'department_leaderboard' => ['class' => 'GamificationReports', 'method' => 'departmentLeaderboard', 'name' => 'Department Leaderboard', 'category' => 'gamification', 'description' => 'Departments ranked by points', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'streak_report' => ['class' => 'GamificationReports', 'method' => 'streakReport', 'name' => 'Streak Report', 'category' => 'gamification', 'description' => 'Learning streak rankings with status tiers', 'audience' => ['admin', 'hr'], 'exportable' => true],

        // Certificate (5)
        'certificates_issued_report' => ['class' => 'CertificateReports', 'method' => 'certificatesIssuedReport', 'name' => 'Certificates Issued Report', 'category' => 'certificate', 'description' => 'All issued certificates', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'certificate_verification_log' => ['class' => 'CertificateReports', 'method' => 'certificateVerificationLog', 'name' => 'Certificate Verification Log', 'category' => 'certificate', 'description' => 'Certificate verification activity', 'audience' => ['admin'], 'exportable' => true],
        'expiring_certificates_report' => ['class' => 'CertificateReports', 'method' => 'expiringCertificatesReport', 'name' => 'Expiring Certificates Report', 'category' => 'certificate', 'description' => 'Certificates expiring soon', 'audience' => ['admin', 'hr'], 'params' => ['days_ahead'], 'exportable' => true],
        'certificate_reprint_history' => ['class' => 'CertificateReports', 'method' => 'certificateReprintHistory', 'name' => 'Certificate Reprint History', 'category' => 'certificate', 'description' => 'Certificate reprint log', 'audience' => ['admin'], 'exportable' => true],
        'certificates_by_course' => ['class' => 'CertificateReports', 'method' => 'certificatesByCourse', 'name' => 'Certificates by Course', 'category' => 'certificate', 'description' => 'Certificate distribution by course', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'certificate_template_usage' => ['class' => 'CertificateReports', 'method' => 'certificateTemplateUsage', 'name' => 'Certificate Template Usage', 'category' => 'certificate', 'description' => 'Certificate issuance volumes by course with expiry tracking', 'audience' => ['admin'], 'exportable' => true],

        // System (6)
        'system_usage_report' => ['class' => 'SystemReports', 'method' => 'systemUsageReport', 'name' => 'System Usage Report', 'category' => 'system', 'description' => 'Overall system usage statistics', 'audience' => ['admin'], 'exportable' => true],
        'error_issue_log' => ['class' => 'SystemReports', 'method' => 'errorIssueLog', 'name' => 'Error and Issue Log', 'category' => 'system', 'description' => 'System errors and issues', 'audience' => ['admin'], 'exportable' => true],
        'admin_activity_log' => ['class' => 'SystemReports', 'method' => 'adminActivityLog', 'name' => 'Admin Activity Log', 'category' => 'system', 'description' => 'Administrator activity history', 'audience' => ['admin'], 'exportable' => true],
        'storage_usage_report' => ['class' => 'SystemReports', 'method' => 'storageUsageReport', 'name' => 'Storage Usage Report', 'category' => 'system', 'description' => 'Storage consumption details', 'audience' => ['admin'], 'exportable' => true],
        'integration_sync_report' => ['class' => 'SystemReports', 'method' => 'integrationSyncReport', 'name' => 'Integration Sync Report', 'category' => 'system', 'description' => 'External integration sync status', 'audience' => ['admin'], 'exportable' => true],
        'tenant_usage_report' => ['class' => 'SystemReports', 'method' => 'tenantUsageReport', 'name' => 'Tenant Usage Report', 'category' => 'system', 'description' => 'Multi-tenant usage overview', 'audience' => ['superadmin'], 'exportable' => true],

        // Executive (5)
        'executive_training_dashboard' => ['class' => 'ExecutiveReports', 'method' => 'executiveTrainingDashboard', 'name' => 'Executive Training Dashboard', 'category' => 'executive', 'description' => 'High-level KPIs and trends', 'audience' => ['admin', 'hr', 'executive'], 'exportable' => true],
        'monthly_training_summary' => ['class' => 'ExecutiveReports', 'method' => 'monthlyTrainingSummary', 'name' => 'Monthly Training Summary', 'category' => 'executive', 'description' => 'Monthly training metrics summary', 'audience' => ['admin', 'hr', 'executive'], 'params' => ['month'], 'exportable' => true],
        'training_roi_report' => ['class' => 'ExecutiveReports', 'method' => 'trainingROIReport', 'name' => 'Training ROI Report', 'category' => 'executive', 'description' => 'Return on training investment analysis', 'audience' => ['admin', 'hr', 'executive'], 'exportable' => true],
        'year_over_year_comparison' => ['class' => 'ExecutiveReports', 'method' => 'yearOverYearComparison', 'name' => 'Year-over-Year Comparison', 'category' => 'executive', 'description' => 'Compare metrics across years', 'audience' => ['admin', 'hr', 'executive'], 'exportable' => true],
        'training_budget_utilization' => ['class' => 'ExecutiveReports', 'method' => 'trainingBudgetUtilization', 'name' => 'Training Budget Utilization', 'category' => 'executive', 'description' => 'Budget spending analysis', 'audience' => ['admin', 'hr', 'executive'], 'exportable' => true],

        // Audit (6)
        'full_audit_trail' => ['class' => 'AuditReports', 'method' => 'fullAuditTrail', 'name' => 'Full Audit Trail', 'category' => 'audit', 'description' => 'Complete system audit log', 'audience' => ['admin'], 'exportable' => true],
        'data_access_log' => ['class' => 'AuditReports', 'method' => 'dataAccessLog', 'name' => 'Data Access Log', 'category' => 'audit', 'description' => 'Who accessed what data', 'audience' => ['admin'], 'exportable' => true],
        'gdpr_data_privacy_report' => ['class' => 'AuditReports', 'method' => 'gdprDataPrivacyReport', 'name' => 'GDPR/Data Privacy Report', 'category' => 'audit', 'description' => 'Privacy compliance status', 'audience' => ['admin'], 'exportable' => true],
        'training_records_export' => ['class' => 'AuditReports', 'method' => 'trainingRecordsExport', 'name' => 'Training Records Export', 'category' => 'audit', 'description' => 'Complete training record for a user', 'audience' => ['admin', 'hr'], 'params' => ['user_id'], 'exportable' => true],
        'compliance_training_audit_trail' => ['class' => 'AuditReports', 'method' => 'complianceTrainingAuditTrail', 'name' => 'Compliance Training Audit Trail', 'category' => 'audit', 'description' => 'Audit trail for compliance training', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'security_audit_report' => ['class' => 'AuditReports', 'method' => 'securityAuditReport', 'name' => 'Security Audit Report', 'category' => 'audit', 'description' => 'Security-related activity', 'audience' => ['admin'], 'exportable' => true],
        'data_retention_report' => ['class' => 'AuditReports', 'method' => 'dataRetentionReport', 'name' => 'Data Retention Report', 'category' => 'audit', 'description' => 'Data aging analysis across users, training, assessments, and audit logs', 'audience' => ['admin'], 'exportable' => true],

        // ──────────────────────────────────────────────────────
        // LEARNER SELF-SERVICE (44 reports across 9 sub-categories) — Phase 6
        // ──────────────────────────────────────────────────────

        // Cat 15: My Courses (7)
        'my_learning_summary' => ['class' => 'LearnerReports', 'method' => 'myLearningSummary', 'name' => 'My Learning Summary', 'category' => 'my_courses', 'description' => 'Personal learning overview with key stats', 'audience' => ['learner'], 'exportable' => true],
        'my_enrolled_courses' => ['class' => 'LearnerReports', 'method' => 'myEnrolledCourses', 'name' => 'My Enrolled Courses', 'category' => 'my_courses', 'description' => 'All courses currently enrolled in', 'audience' => ['learner'], 'exportable' => true],
        'my_completed_courses' => ['class' => 'LearnerReports', 'method' => 'myCompletedCourses', 'name' => 'My Completed Courses', 'category' => 'my_courses', 'description' => 'Finished courses with scores and certificates', 'audience' => ['learner'], 'exportable' => true],
        'my_in_progress_courses' => ['class' => 'LearnerReports', 'method' => 'myInProgressCourses', 'name' => 'My In-Progress Courses', 'category' => 'my_courses', 'description' => 'Courses currently being worked on', 'audience' => ['learner'], 'exportable' => true],
        'my_overdue_courses' => ['class' => 'LearnerReports', 'method' => 'myOverdueCourses', 'name' => 'My Overdue Courses', 'category' => 'my_courses', 'description' => 'Courses past their due date', 'audience' => ['learner'], 'exportable' => true],
        'my_recommended_courses' => ['class' => 'LearnerReports', 'method' => 'myRecommendedCourses', 'name' => 'My Recommended Courses', 'category' => 'my_courses', 'description' => 'Courses popular among department peers', 'audience' => ['learner'], 'exportable' => false],
        'my_course_ratings' => ['class' => 'LearnerReports', 'method' => 'myCourseRatings', 'name' => 'My Course Ratings', 'category' => 'my_courses', 'description' => 'Ratings and reviews you have submitted', 'audience' => ['learner'], 'exportable' => true],

        // Cat 16: My Assessments (7)
        'my_assessment_results' => ['class' => 'LearnerReports', 'method' => 'myAssessmentResults', 'name' => 'My Assessment Results', 'category' => 'my_assessments', 'description' => 'All assessment attempts with scores', 'audience' => ['learner'], 'exportable' => true],
        'my_quiz_history' => ['class' => 'LearnerReports', 'method' => 'myQuizHistory', 'name' => 'My Quiz History', 'category' => 'my_assessments', 'description' => 'Completed quizzes with scores', 'audience' => ['learner'], 'exportable' => true],
        'my_exam_results' => ['class' => 'LearnerReports', 'method' => 'myExamResults', 'name' => 'My Exam Results', 'category' => 'my_assessments', 'description' => 'Final exam results and certificates', 'audience' => ['learner'], 'exportable' => true],
        'my_assessment_attempts' => ['class' => 'LearnerReports', 'method' => 'myAssessmentAttempts', 'name' => 'My Assessment Attempts', 'category' => 'my_assessments', 'description' => 'Full attempt history with retake tracking', 'audience' => ['learner'], 'exportable' => true],
        'my_strongest_topics' => ['class' => 'LearnerReports', 'method' => 'myStrongestTopics', 'name' => 'My Strongest Topics', 'category' => 'my_assessments', 'description' => 'Categories where you score highest', 'audience' => ['learner'], 'exportable' => false],
        'my_weak_areas' => ['class' => 'LearnerReports', 'method' => 'myWeakAreas', 'name' => 'My Weak Areas', 'category' => 'my_assessments', 'description' => 'Categories needing improvement', 'audience' => ['learner'], 'exportable' => false],
        'my_pending_assessments' => ['class' => 'LearnerReports', 'method' => 'myPendingAssessments', 'name' => 'My Pending Assessments', 'category' => 'my_assessments', 'description' => 'Assessments not yet passed', 'audience' => ['learner'], 'exportable' => true],

        // Cat 17: My Certificates & Achievements (5)
        'my_certificates' => ['class' => 'LearnerReports', 'method' => 'myCertificates', 'name' => 'My Certificates', 'category' => 'my_certificates', 'description' => 'All earned certificates with status', 'audience' => ['learner'], 'exportable' => true],
        'my_expiring_certificates' => ['class' => 'LearnerReports', 'method' => 'myExpiringCertificates', 'name' => 'My Expiring Certificates', 'category' => 'my_certificates', 'description' => 'Certificates expiring soon', 'audience' => ['learner'], 'exportable' => true],
        'my_badges' => ['class' => 'LearnerReports', 'method' => 'myBadges', 'name' => 'My Badges', 'category' => 'my_certificates', 'description' => 'All earned badges', 'audience' => ['learner'], 'exportable' => true],
        'my_skills' => ['class' => 'LearnerReports', 'method' => 'mySkills', 'name' => 'My Skills & Competencies', 'category' => 'my_certificates', 'description' => 'Skills derived from completed courses', 'audience' => ['learner'], 'exportable' => true],
        'my_achievements_timeline' => ['class' => 'LearnerReports', 'method' => 'myAchievementsTimeline', 'name' => 'My Achievements Timeline', 'category' => 'my_certificates', 'description' => 'Chronological list of all achievements', 'audience' => ['learner'], 'exportable' => true],

        // Cat 18: My Gamification (5)
        'my_points_summary' => ['class' => 'LearnerReports', 'method' => 'myPointsSummary', 'name' => 'My Points Summary', 'category' => 'my_gamification', 'description' => 'Points breakdown by activity type', 'audience' => ['learner'], 'exportable' => true],
        'my_leaderboard_position' => ['class' => 'LearnerReports', 'method' => 'myLeaderboardPosition', 'name' => 'My Leaderboard Position', 'category' => 'my_gamification', 'description' => 'Current rank among all learners', 'audience' => ['learner'], 'exportable' => false],
        'my_streak_stats' => ['class' => 'LearnerReports', 'method' => 'myStreakStats', 'name' => 'My Streak Stats', 'category' => 'my_gamification', 'description' => 'Current and longest learning streaks', 'audience' => ['learner'], 'exportable' => false],
        'my_milestones' => ['class' => 'LearnerReports', 'method' => 'myMilestones', 'name' => 'My Milestones', 'category' => 'my_gamification', 'description' => 'Achievement milestones — earned and pending', 'audience' => ['learner'], 'exportable' => true],
        'my_level_progress' => ['class' => 'LearnerReports', 'method' => 'myLevelProgress', 'name' => 'My Level Progress', 'category' => 'my_gamification', 'description' => 'Current level and points to next', 'audience' => ['learner'], 'exportable' => false],

        // Cat 19: My Compliance (4)
        'my_compliance_status' => ['class' => 'LearnerReports', 'method' => 'myComplianceStatus', 'name' => 'My Compliance Status', 'category' => 'my_compliance', 'description' => 'Mandatory training compliance overview', 'audience' => ['learner'], 'exportable' => true],
        'my_mandatory_training' => ['class' => 'LearnerReports', 'method' => 'myMandatoryTraining', 'name' => 'My Mandatory Training', 'category' => 'my_compliance', 'description' => 'All mandatory courses with status', 'audience' => ['learner'], 'exportable' => true],
        'my_compliance_calendar' => ['class' => 'LearnerReports', 'method' => 'myComplianceCalendar', 'name' => 'My Compliance Calendar', 'category' => 'my_compliance', 'description' => 'Upcoming compliance deadlines', 'audience' => ['learner'], 'exportable' => true],
        'my_compliance_history' => ['class' => 'LearnerReports', 'method' => 'myComplianceHistory', 'name' => 'My Compliance History', 'category' => 'my_compliance', 'description' => 'Year-over-year mandatory training completions', 'audience' => ['learner'], 'exportable' => true],

        // Cat 20: My Learning Paths (4)
        'my_learning_paths' => ['class' => 'LearnerReports', 'method' => 'myLearningPaths', 'name' => 'My Learning Paths', 'category' => 'my_paths', 'description' => 'All enrolled learning paths with progress', 'audience' => ['learner'], 'exportable' => true],
        'my_learning_path_progress' => ['class' => 'LearnerReports', 'method' => 'myLearningPathProgress', 'name' => 'My Learning Path Progress', 'category' => 'my_paths', 'description' => 'Item-by-item progress within a learning path', 'audience' => ['learner'], 'exportable' => true],
        'my_recommended_paths' => ['class' => 'LearnerReports', 'method' => 'myRecommendedPaths', 'name' => 'My Recommended Paths', 'category' => 'my_paths', 'description' => 'Popular paths you haven\'t started', 'audience' => ['learner'], 'exportable' => false],
        'my_skill_gap_analysis' => ['class' => 'LearnerReports', 'method' => 'mySkillGapAnalysis', 'name' => 'My Skill Gap Analysis', 'category' => 'my_paths', 'description' => 'Mandatory training gaps by category', 'audience' => ['learner'], 'exportable' => true],

        // Cat 21: My Time & Activity (4)
        'my_learning_time' => ['class' => 'LearnerReports', 'method' => 'myLearningTime', 'name' => 'My Learning Time', 'category' => 'my_activity', 'description' => 'Total and per-course time analytics', 'audience' => ['learner'], 'exportable' => true],
        'my_weekly_activity' => ['class' => 'LearnerReports', 'method' => 'myWeeklyActivity', 'name' => 'My Weekly Activity', 'category' => 'my_activity', 'description' => 'Last 7 days learning activity', 'audience' => ['learner'], 'exportable' => true],
        'my_login_history' => ['class' => 'LearnerReports', 'method' => 'myLoginHistory', 'name' => 'My Login History', 'category' => 'my_activity', 'description' => 'Recent login sessions', 'audience' => ['learner'], 'exportable' => true],
        'my_engagement_score' => ['class' => 'LearnerReports', 'method' => 'myEngagementScore', 'name' => 'My Engagement Score', 'category' => 'my_activity', 'description' => 'Composite engagement metric with breakdown', 'audience' => ['learner'], 'exportable' => false],

        // Cat 22: My Performance (4)
        'my_performance_trend' => ['class' => 'LearnerReports', 'method' => 'myPerformanceTrend', 'name' => 'My Performance Trend', 'category' => 'my_performance', 'description' => 'Monthly score and completion trends', 'audience' => ['learner'], 'exportable' => true],
        'my_vs_peer_comparison' => ['class' => 'LearnerReports', 'method' => 'myVsPeerComparison', 'name' => 'My vs Peer Comparison', 'category' => 'my_performance', 'description' => 'Compare your stats against department peers', 'audience' => ['learner'], 'exportable' => false],
        'my_learning_velocity' => ['class' => 'LearnerReports', 'method' => 'myLearningVelocity', 'name' => 'My Learning Velocity', 'category' => 'my_performance', 'description' => 'Average days to complete vs peers', 'audience' => ['learner'], 'exportable' => false],
        'my_improvement_areas' => ['class' => 'LearnerReports', 'method' => 'myImprovementAreas', 'name' => 'My Improvement Areas', 'category' => 'my_performance', 'description' => 'Low-score categories and overdue items', 'audience' => ['learner'], 'exportable' => true],

        // Cat 23: My Exports (4)
        'my_training_transcript' => ['class' => 'LearnerReports', 'method' => 'myTrainingTranscript', 'name' => 'My Training Transcript', 'category' => 'my_exports', 'description' => 'Official training record', 'audience' => ['learner'], 'exportable' => true],
        'my_learning_portfolio' => ['class' => 'LearnerReports', 'method' => 'myLearningPortfolio', 'name' => 'My Learning Portfolio', 'category' => 'my_exports', 'description' => 'Summary + courses + certs + badges + skills', 'audience' => ['learner'], 'exportable' => true],
        'my_upcoming_deadlines' => ['class' => 'LearnerReports', 'method' => 'myUpcomingDeadlines', 'name' => 'My Upcoming Deadlines', 'category' => 'my_exports', 'description' => 'All upcoming training deadlines', 'audience' => ['learner'], 'params' => ['days'], 'exportable' => true],
        'my_compliance_cert_pack' => ['class' => 'LearnerReports', 'method' => 'myComplianceCertPack', 'name' => 'My Compliance Certificate Pack', 'category' => 'my_exports', 'description' => 'All mandatory training certificates', 'audience' => ['learner'], 'exportable' => true],

        // Learning Path (5) — Phase 5
        'learning_path_enrollment' => ['class' => 'LearningPathReports', 'method' => 'learningPathEnrollment', 'name' => 'Learning Path Enrollment Report', 'category' => 'learning_path', 'description' => 'Overview of enrollments across all learning paths', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'learning_path_progress' => ['class' => 'LearningPathReports', 'method' => 'learningPathProgress', 'name' => 'Learning Path Progress Report', 'category' => 'learning_path', 'description' => 'Detailed per-learner progress within learning paths', 'audience' => ['admin', 'hr', 'manager'], 'params' => ['path_id'], 'exportable' => true],
        'learning_path_completion' => ['class' => 'LearningPathReports', 'method' => 'learningPathCompletion', 'name' => 'Learning Path Completion Report', 'category' => 'learning_path', 'description' => 'Completion rates and timing analysis by path', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'path_drop_off_analysis' => ['class' => 'LearningPathReports', 'method' => 'pathDropOffAnalysis', 'name' => 'Path Drop-Off Analysis', 'category' => 'learning_path', 'description' => 'Identifies where learners abandon learning paths', 'audience' => ['admin', 'hr'], 'params' => ['path_id'], 'exportable' => true],
        'path_effectiveness_report' => ['class' => 'LearningPathReports', 'method' => 'pathEffectivenessReport', 'name' => 'Path Effectiveness Report', 'category' => 'learning_path', 'description' => 'Measures path effectiveness via completion rates and assessment scores', 'audience' => ['admin', 'hr'], 'exportable' => true],

        // ILT - Instructor-Led Training (6) — Phase 5
        'session_attendance_report' => ['class' => 'ILTReports', 'method' => 'sessionAttendanceReport', 'name' => 'Session Attendance Report', 'category' => 'ilt', 'description' => 'Attendance tracking for all ILT sessions', 'audience' => ['admin', 'hr'], 'params' => ['session_id', 'instructor_id'], 'exportable' => true],
        'no_show_report' => ['class' => 'ILTReports', 'method' => 'noShowReport', 'name' => 'No-Show Report', 'category' => 'ilt', 'description' => 'Learners who enrolled but did not attend ILT sessions', 'audience' => ['admin', 'hr', 'manager'], 'exportable' => true],
        'session_utilization_report' => ['class' => 'ILTReports', 'method' => 'sessionUtilizationReport', 'name' => 'Session Utilization Report', 'category' => 'ilt', 'description' => 'Session capacity utilisation and available seats', 'audience' => ['admin', 'hr'], 'params' => ['venue_id'], 'exportable' => true],
        'instructor_effectiveness' => ['class' => 'ILTReports', 'method' => 'instructorEffectiveness', 'name' => 'Instructor Effectiveness Report', 'category' => 'ilt', 'description' => 'Instructor performance: sessions delivered, attendance rates, hours', 'audience' => ['admin', 'hr'], 'params' => ['instructor_id'], 'exportable' => true],
        'ilt_cost_report' => ['class' => 'ILTReports', 'method' => 'iltCostReport', 'name' => 'ILT Cost Report', 'category' => 'ilt', 'description' => 'Cost tracking per session with cost-per-learner analysis', 'audience' => ['admin'], 'exportable' => true],
        'venue_utilization_report' => ['class' => 'ILTReports', 'method' => 'venueUtilizationReport', 'name' => 'Venue Utilization Report', 'category' => 'ilt', 'description' => 'Venue usage, capacity utilisation, and session history', 'audience' => ['admin'], 'exportable' => true],

        // Custom Report Builder — Phase 7
        'custom_report' => ['class' => 'CustomReportBuilder', 'method' => 'generate', 'name' => 'Custom Report Builder', 'category' => 'custom', 'description' => 'Build your own report: select data source, columns, filters, and grouping', 'audience' => ['admin', 'hr'], 'exportable' => true],

        // ── Phase 9: Onboarding & New Hire Reports ──────────────────────
        'onboarding_progress_by_cohort' => ['class' => 'OnboardingReports', 'method' => 'onboardingProgressByCohort', 'name' => 'Onboarding Progress by Cohort', 'category' => 'onboarding', 'description' => 'New hire onboarding completion rates grouped by hire month or quarter', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'time_to_competency' => ['class' => 'OnboardingReports', 'method' => 'timeToCompetency', 'name' => 'Time-to-Competency Report', 'category' => 'onboarding', 'description' => 'Measures days from hire date until all mandatory onboarding courses are completed', 'audience' => ['admin', 'hr', 'manager'], 'exportable' => true],
        'onboarding_completion_by_org' => ['class' => 'OnboardingReports', 'method' => 'onboardingCompletionByOrg', 'name' => 'Onboarding Completion by Org Unit', 'category' => 'onboarding', 'description' => 'Onboarding completion rates broken down by department, branch, or team', 'audience' => ['admin', 'hr'], 'params' => ['breakdown'], 'exportable' => true],
        'new_hire_training_dashboard' => ['class' => 'OnboardingReports', 'method' => 'newHireTrainingDashboard', 'name' => 'New Hire Training Status Dashboard', 'category' => 'onboarding', 'description' => 'Real-time onboarding status for all current new hires with risk indicators', 'audience' => ['admin', 'hr', 'manager'], 'params' => ['onboarding_status', 'new_hire_window_days'], 'exportable' => true],
        'onboarding_drop_off' => ['class' => 'OnboardingReports', 'method' => 'onboardingDropOff', 'name' => 'Onboarding Drop-Off Analysis', 'category' => 'onboarding', 'description' => 'Identifies where in the onboarding flow new hires stall or disengage', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'probation_training_compliance' => ['class' => 'OnboardingReports', 'method' => 'probationTrainingCompliance', 'name' => 'Probation Period Training Compliance', 'category' => 'onboarding', 'description' => 'Tracks whether new hires complete all required training before probation ends', 'audience' => ['admin', 'hr', 'manager'], 'params' => ['probation_days'], 'exportable' => true],

        // ── Phase 10: Skills & Competency Reports ───────────────────────
        'skill_gap_analysis' => ['class' => 'SkillsReports', 'method' => 'skillGapAnalysis', 'name' => 'Skill Gap Analysis', 'category' => 'skills', 'description' => 'Required skills per role vs acquired skills per employee, by department/role/team', 'audience' => ['admin', 'hr'], 'params' => ['breakdown', 'skill_category'], 'exportable' => true],
        'competency_matrix' => ['class' => 'SkillsReports', 'method' => 'competencyMatrix', 'name' => 'Competency Matrix', 'category' => 'skills', 'description' => 'Grid of employees × required competencies showing met/gap/not-attempted', 'audience' => ['admin', 'hr', 'manager'], 'params' => ['role_id'], 'exportable' => true],
        'skills_coverage_by_role' => ['class' => 'SkillsReports', 'method' => 'skillsCoverageByRole', 'name' => 'Skills Coverage by Role', 'category' => 'skills', 'description' => 'Percentage of required skills covered by available training courses per role', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'competency_development_trend' => ['class' => 'SkillsReports', 'method' => 'competencyDevelopmentTrend', 'name' => 'Competency Development Trend', 'category' => 'skills', 'description' => 'Skill acquisition growth over time (monthly or quarterly)', 'audience' => ['admin', 'hr'], 'params' => ['period', 'skill_category'], 'exportable' => true],
        'role_requirements_vs_actual' => ['class' => 'SkillsReports', 'method' => 'roleRequirementsVsActual', 'name' => 'Role Requirements vs Actual', 'category' => 'skills', 'description' => 'For a specific role: every employee\'s skill compliance status', 'audience' => ['admin', 'hr', 'manager'], 'params' => ['role_id'], 'exportable' => true],
        'skills_inventory' => ['class' => 'SkillsReports', 'method' => 'skillsInventory', 'name' => 'Skills Inventory Report', 'category' => 'skills', 'description' => 'Complete catalog of all skills with holder counts, coverage, and course gaps', 'audience' => ['admin', 'hr'], 'params' => ['skill_category', 'search'], 'exportable' => true],

        // ═══════ Phase 11: Survey & Feedback (6 reports) ═══════
        'learner_satisfaction_nps' => ['class' => 'FeedbackReports', 'method' => 'learnerSatisfactionNps', 'name' => 'Learner Satisfaction NPS', 'category' => 'feedback', 'description' => 'Net Promoter Score per course and overall', 'audience' => ['admin', 'hr'], 'params' => ['course_id'], 'exportable' => true],
        'course_feedback_summary' => ['class' => 'FeedbackReports', 'method' => 'courseFeedbackSummary', 'name' => 'Course Feedback Summary', 'category' => 'feedback', 'description' => 'Ratings and qualitative feedback per course', 'audience' => ['admin', 'hr', 'manager'], 'params' => ['course_id'], 'exportable' => true],
        'instructor_evaluation' => ['class' => 'FeedbackReports', 'method' => 'instructorEvaluation', 'name' => 'Instructor Evaluation', 'category' => 'feedback', 'description' => 'Learner ratings and feedback for ILT instructors', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'training_effectiveness_survey' => ['class' => 'FeedbackReports', 'method' => 'trainingEffectivenessSurvey', 'name' => 'Training Effectiveness Survey', 'category' => 'feedback', 'description' => 'Post-training survey results by course and department', 'audience' => ['admin', 'hr', 'executive'], 'exportable' => true],
        'feedback_trends' => ['class' => 'FeedbackReports', 'method' => 'feedbackTrends', 'name' => 'Feedback Trends', 'category' => 'feedback', 'description' => 'Satisfaction scores trending over time', 'audience' => ['admin', 'hr', 'executive'], 'params' => ['period'], 'exportable' => true],
        'learner_sentiment' => ['class' => 'FeedbackReports', 'method' => 'learnerSentiment', 'name' => 'Learner Sentiment Analysis', 'category' => 'feedback', 'description' => 'Aggregate feedback themes from text responses', 'audience' => ['admin', 'hr'], 'exportable' => true],

        // ═══════ Phase 12: Knowledge Retention & Impact (9 reports) ═══════
        'pre_post_comparison' => ['class' => 'RetentionReports', 'method' => 'prePostComparison', 'name' => 'Pre/Post Test Comparison', 'category' => 'retention', 'description' => 'Pre-test vs post-test score comparison per course', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'knowledge_retention' => ['class' => 'RetentionReports', 'method' => 'knowledgeRetention', 'name' => 'Knowledge Retention', 'category' => 'retention', 'description' => 'Retest scores at 30/60/90 days after initial pass', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'knowledge_decay' => ['class' => 'RetentionReports', 'method' => 'knowledgeDecay', 'name' => 'Knowledge Decay Analysis', 'category' => 'retention', 'description' => 'Courses where knowledge retention drops fastest', 'audience' => ['admin', 'hr', 'executive'], 'exportable' => true],
        'repeat_assessment_trend' => ['class' => 'RetentionReports', 'method' => 'repeatAssessmentTrend', 'name' => 'Repeat Assessment Trend', 'category' => 'retention', 'description' => 'Score improvement across multiple assessment attempts', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'kirkpatrick_summary' => ['class' => 'RetentionReports', 'method' => 'kirkpatrickSummary', 'name' => 'Kirkpatrick Training Evaluation', 'category' => 'retention', 'description' => 'Level 1-4 evaluation per training program', 'audience' => ['admin', 'hr', 'executive'], 'exportable' => true],
        'training_impact' => ['class' => 'RetentionReports', 'method' => 'trainingImpact', 'name' => 'Training Impact Analysis', 'category' => 'retention', 'description' => 'Correlate training completion with assessment performance', 'audience' => ['admin', 'executive'], 'exportable' => true],
        'training_effectiveness_index' => ['class' => 'RetentionReports', 'method' => 'trainingEffectivenessIndex', 'name' => 'Training Effectiveness Index', 'category' => 'retention', 'description' => 'Composite effectiveness score per course', 'audience' => ['admin', 'hr', 'executive'], 'exportable' => true],
        'time_to_proficiency' => ['class' => 'RetentionReports', 'method' => 'timeToProficiency', 'name' => 'Time to Proficiency', 'category' => 'retention', 'description' => 'Days from enrollment to first assessment pass', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'post_training_performance' => ['class' => 'RetentionReports', 'method' => 'postTrainingPerformance', 'name' => 'Post-Training Performance', 'category' => 'retention', 'description' => 'Assessment score changes before vs after training', 'audience' => ['admin', 'executive'], 'exportable' => true],

        // ═══════ Phase 13: Manager (4) + Cohort (3) + Cost (4) = 11 reports ═══════
        'manager_team_dashboard' => ['class' => 'ManagerReports', 'method' => 'managerTeamDashboard', 'name' => 'Manager Team Dashboard', 'category' => 'manager', 'description' => 'Training status for all direct reports', 'audience' => ['admin', 'manager'], 'params' => ['manager_id'], 'exportable' => true],
        'manager_compliance_summary' => ['class' => 'ManagerReports', 'method' => 'managerComplianceSummary', 'name' => 'Manager Compliance Summary', 'category' => 'manager', 'description' => 'Mandatory training compliance for manager team', 'audience' => ['admin', 'manager'], 'params' => ['manager_id'], 'exportable' => true],
        'manager_action_required' => ['class' => 'ManagerReports', 'method' => 'managerActionRequired', 'name' => 'Manager Action Required', 'category' => 'manager', 'description' => 'Items requiring manager attention: overdue training, expiring certs', 'audience' => ['admin', 'manager'], 'params' => ['manager_id'], 'exportable' => true],
        'team_vs_org_benchmark' => ['class' => 'ManagerReports', 'method' => 'teamVsOrgBenchmark', 'name' => 'Team vs Org Benchmark', 'category' => 'manager', 'description' => 'Team performance compared to organization-wide averages', 'audience' => ['admin', 'manager'], 'params' => ['manager_id'], 'exportable' => true],
        'cohort_progress' => ['class' => 'CohortCostReports', 'method' => 'cohortProgress', 'name' => 'Cohort Progress', 'category' => 'cohort', 'description' => 'Batch-level completion comparison by enrollment cohort', 'audience' => ['admin', 'hr'], 'params' => ['group_by'], 'exportable' => true],
        'group_performance' => ['class' => 'CohortCostReports', 'method' => 'groupPerformance', 'name' => 'Group Performance', 'category' => 'cohort', 'description' => 'Collective progress by department/team/branch', 'audience' => ['admin', 'hr', 'manager'], 'params' => ['breakdown'], 'exportable' => true],
        'peer_benchmarking' => ['class' => 'CohortCostReports', 'method' => 'peerBenchmarking', 'name' => 'Peer Benchmarking', 'category' => 'cohort', 'description' => 'Individual performance vs department peer average', 'audience' => ['admin', 'hr', 'manager'], 'params' => ['learner_id'], 'exportable' => true],
        'training_cost_per_employee' => ['class' => 'CohortCostReports', 'method' => 'trainingCostPerEmployee', 'name' => 'Training Cost Per Employee', 'category' => 'cost', 'description' => 'Training investment per employee by department', 'audience' => ['admin', 'executive'], 'exportable' => true],
        'cost_per_completion' => ['class' => 'CohortCostReports', 'method' => 'costPerCompletion', 'name' => 'Cost Per Completion', 'category' => 'cost', 'description' => 'Training cost per course completion', 'audience' => ['admin', 'executive'], 'exportable' => true],
        'department_training_spend' => ['class' => 'CohortCostReports', 'method' => 'departmentTrainingSpend', 'name' => 'Department Training Spend', 'category' => 'cost', 'description' => 'Training budget allocation by department', 'audience' => ['admin', 'executive'], 'exportable' => true],
        'cost_efficiency' => ['class' => 'CohortCostReports', 'method' => 'costEfficiency', 'name' => 'Cost Efficiency Analysis', 'category' => 'cost', 'description' => 'Online vs ILT delivery efficiency comparison', 'audience' => ['admin', 'executive'], 'exportable' => true],

        // ═══════ Phase 15: Content & Resource Analytics (3 reports) ═══════
        'resource_utilization' => ['class' => 'ContentReports', 'method' => 'resourceUtilization', 'name' => 'Resource Utilization', 'category' => 'content', 'description' => 'Access frequency and time per individual resource', 'audience' => ['admin', 'hr'], 'exportable' => true],
        'content_freshness' => ['class' => 'ContentReports', 'method' => 'contentFreshness', 'name' => 'Content Freshness', 'category' => 'content', 'description' => 'Flag stale content not updated in X months', 'audience' => ['admin'], 'params' => ['months'], 'exportable' => true],
        'media_type_engagement' => ['class' => 'ContentReports', 'method' => 'mediaTypeEngagement', 'name' => 'Media Type Engagement', 'category' => 'content', 'description' => 'Engagement comparison across video/PDF/SCORM/ILT', 'audience' => ['admin', 'hr'], 'exportable' => true],
    ];

    public function __construct(mysqli $db) { $this->conn = $db; }

    public function setContext(int $tenant_id, ?int $user_id = null): self {
        $this->tenant_id = $tenant_id;
        $this->user_id = $user_id;
        return $this;
    }

    public function getReportCatalog(?string $category = null, ?string $audience = null): array {
        $reports = [];
        foreach ($this->reportCatalog as $key => $report) {
            if ($category && $report['category'] !== $category) continue;
            if ($audience && !in_array($audience, $report['audience'])) continue;
            $reports[] = ['report_key' => $key, 'name' => $report['name'], 'category' => $report['category'], 'description' => $report['description'], 'audience' => $report['audience'], 'params' => $report['params'] ?? [], 'exportable' => $report['exportable']];
        }
        return $reports;
    }

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
            ['key' => 'learning_path', 'name' => 'Learning Paths', 'icon' => 'bi-signpost-split'],
            ['key' => 'ilt', 'name' => 'Instructor-Led Training', 'icon' => 'bi-easel'],
            ['key' => 'onboarding', 'name' => 'Onboarding & New Hire', 'icon' => 'bi-person-badge-fill'],
            ['key' => 'skills', 'name' => 'Skills & Competency', 'icon' => 'bi-diagram-3-fill'],
            ['key' => 'feedback', 'name' => 'Survey & Feedback', 'icon' => 'bi-chat-square-text-fill'],
            ['key' => 'retention', 'name' => 'Retention & Impact', 'icon' => 'bi-graph-up-arrow'],
            ['key' => 'manager', 'name' => 'Manager Dashboard', 'icon' => 'bi-people-fill'],
            ['key' => 'cohort', 'name' => 'Cohort & Group', 'icon' => 'bi-collection-fill'],
            ['key' => 'cost', 'name' => 'Cost Analytics', 'icon' => 'bi-currency-dollar'],
            ['key' => 'content', 'name' => 'Content Analytics', 'icon' => 'bi-file-earmark-bar-graph'],
            ['key' => 'learner', 'name' => 'My Reports', 'icon' => 'bi-person-badge'],

            // ── Phase 6: Learner sub-categories ──
            ['key' => 'my_courses', 'name' => 'My Courses', 'icon' => 'bi-book', 'parent' => 'learner'],
            ['key' => 'my_assessments', 'name' => 'My Assessments', 'icon' => 'bi-clipboard-check', 'parent' => 'learner'],
            ['key' => 'my_certificates', 'name' => 'My Certificates & Achievements', 'icon' => 'bi-award', 'parent' => 'learner'],
            ['key' => 'my_gamification', 'name' => 'My Gamification', 'icon' => 'bi-trophy', 'parent' => 'learner'],
            ['key' => 'my_compliance', 'name' => 'My Compliance', 'icon' => 'bi-shield-check', 'parent' => 'learner'],
            ['key' => 'my_paths', 'name' => 'My Learning Paths', 'icon' => 'bi-signpost-split', 'parent' => 'learner'],
            ['key' => 'my_activity', 'name' => 'My Time & Activity', 'icon' => 'bi-clock-history', 'parent' => 'learner'],
            ['key' => 'my_performance', 'name' => 'My Performance', 'icon' => 'bi-graph-up-arrow', 'parent' => 'learner'],
            ['key' => 'my_exports', 'name' => 'My Exports & Sharing', 'icon' => 'bi-download', 'parent' => 'learner'],

            // Phase 7: Custom Report Builder
            ['key' => 'custom', 'name' => 'Custom Report Builder', 'icon' => 'bi-sliders']
        ];
    }

    /** Execute a report — PHASE 1: Now wires org-unit filters */
    public function runReport(string $reportKey, array $params = []): array {
        if (!isset($this->reportCatalog[$reportKey])) {
            return ['error' => 'Report not found', 'report_key' => $reportKey];
        }

        $config = $this->reportCatalog[$reportKey];
        $instance = $this->getReportInstance($config['class']);

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

        // ── PHASE 6: Wire learner-specific filter params (path_id, days, days_ahead) ──
        $learnerFilterKeys = ['path_id', 'days', 'days_ahead'];
        $extraFilters = [];
        foreach ($learnerFilterKeys as $fk) {
            if (isset($params[$fk])) $extraFilters[$fk] = $params[$fk];
        }
        if (!empty($extraFilters)) {
            $existing = $instance instanceof LearnerReports ? [] : [];
            $instance->setFilters(array_merge($params['filters'] ?? [], $extraFilters));
        }

        // ── PHASE 6: Wire user_id for learner reports called via runReport ──
        if ($instance instanceof LearnerReports && $this->user_id) {
            $instance->setUser($this->user_id);
        }

        // ── PHASE 1: Wire org-unit filters ──
        if (isset($params['department_id'])) $instance->setDepartmentFilter((int)$params['department_id']);
        if (isset($params['team_id'])) $instance->setTeamFilter((int)$params['team_id']);
        if (isset($params['branch_id'])) $instance->setBranchFilter((int)$params['branch_id']);
        if (isset($params['course_id'])) $instance->setCourseFilter((int)$params['course_id']);
        if (isset($params['learner_id'])) $instance->setLearnerFilter((int)$params['learner_id']);
        if (isset($params['manager_id'])) $instance->setManagerFilter((int)$params['manager_id']);

        $method = $config['method'];
        $methodParams = [];
        if (!empty($config['params'])) {
            foreach ($config['params'] as $paramName) {
                if (isset($params[$paramName])) {
                    $methodParams[] = $params[$paramName];
                }
            }
            // Validate: if config requires params but none were provided, return error
            if (empty($methodParams) && count($config['params']) > 0) {
                $missing = implode(', ', array_diff($config['params'], array_keys($params)));
                return [
                    'success' => false,
                    'error' => 'Required parameter(s) missing: ' . $missing,
                    'report_key' => $reportKey,
                    'required_params' => $config['params']
                ];
            }
        }

        try {
            $result = empty($methodParams) ? $instance->$method() : $instance->$method(...$methodParams);
            return ['success' => true, 'report_key' => $reportKey, 'report_name' => $config['name'], 'category' => $config['category'], 'generated_at' => date('Y-m-d H:i:s'), 'result' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'report_key' => $reportKey];
        }
    }

    /** Export — PHASE 1: supports csv/xlsx/pdf with fallback */
    // =========================================================================
    // Phase 3c: Real Multi-Format Export (CSV + Excel + PDF)
    // =========================================================================

    /**
     * Export a report in CSV, XLSX, or PDF format.
     *
     * Handles both flat reports (with 'data' array) and nested/multi-section
     * reports (compliance_executive_summary, course_profile, branch_profile, etc.)
     *
     * CSV: multi-section reports export each section separated by blank rows
     * XLSX: multi-section reports get one sheet per section (with PhpSpreadsheet)
     * PDF: multi-section reports render summary KPIs + section tables (with TCPDF)
     *
     * Falls back to CSV if XLSX/PDF libraries are not installed.
     */
    public function exportReport(string $reportKey, array $params = [], string $format = 'csv'): array
    {
        $params['per_page'] = 10000;
        $params['page'] = 1;
        $result = $this->runReport($reportKey, $params);
        if (!$result['success']) return $result;

        $reportData = $result['result'];
        $reportName = $result['report_name'] ?? $reportKey;
        $ts = date('Y-m-d_His');

        // Extract structured export data: sections (named table arrays) + summary (KPI scalars)
        $extracted = $this->extractExportData($reportData);
        $sections = $extracted['sections'];   // ['Section Name' => [ [row], [row], ... ]]
        $summary  = $extracted['summary'];    // ['key' => 'value', ...] (scalar KPIs)
        $filters  = $extracted['filters'];    // Applied filter info

        if (empty($sections) && empty($summary)) {
            return ['success' => false, 'error' => 'No data to export'];
        }

        switch ($format) {
            case 'xlsx':
                return $this->exportXlsx($sections, $summary, $filters, $reportName, $reportKey, $ts);
            case 'pdf':
                return $this->exportPdf($sections, $summary, $filters, $reportName, $reportKey, $ts);
            default:
                return $this->exportCsv($sections, $summary, $filters, $reportName, $reportKey, $ts);
        }
    }

    /**
     * Extract structured export data from any report result.
     *
     * Handles three report structures:
     * 1. Flat: { data: [...], meta: {...}, summary: {...} }
     * 2. Multi-section: { by_branch: [...], by_department: [...], non_compliant_learners: [...] }
     * 3. Profile: { unit_info: {...}, compliance: {...}, course_breakdown: [...], learners: [...] }
     */
    private function extractExportData(array $reportData): array
    {
        $sections = [];
        $summary = [];
        $filters = [];

        // Known scalar/KPI keys (not table data)
        $scalarKeys = [
            'report_name', 'generated_at', 'meta', 'applied_filters',
            'status_filter', 'course_id', 'risk_breakdown'
        ];

        // Known summary object keys (flatten to KPI rows)
        $summaryKeys = [
            'summary', 'org_summary', 'unit_info', 'course_info',
            'compliance', 'status_breakdown'
        ];

        foreach ($reportData as $key => $value) {
            // Skip non-data keys
            if (in_array($key, $scalarKeys)) {
                if ($key === 'applied_filters' && is_array($value)) {
                    $filters = $value;
                }
                if ($key === 'risk_breakdown' && is_array($value)) {
                    $summary['Risk Breakdown'] = $value;
                }
                continue;
            }

            // Summary/KPI objects → flatten to key-value pairs
            if (in_array($key, $summaryKeys) && is_array($value) && !$this->isIndexedArray($value)) {
                $sectionName = ucwords(str_replace('_', ' ', $key));
                $summary[$sectionName] = $value;
                continue;
            }

            // Indexed arrays of assoc arrays → table sections
            if (is_array($value) && $this->isIndexedArray($value) && !empty($value) && is_array($value[0])) {
                $sectionName = ucwords(str_replace('_', ' ', $key));
                $sections[$sectionName] = $value;
                continue;
            }

            // Single flat 'data' key → primary section
            if ($key === 'data' && is_array($value) && !empty($value)) {
                $sections['Data'] = $value;
            }
        }

        return ['sections' => $sections, 'summary' => $summary, 'filters' => $filters];
    }

    /**
     * Check if array is numerically indexed (list of rows) vs associative (object)
     */
    private function isIndexedArray(array $arr): bool
    {
        if (empty($arr)) return true;
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    // ── CSV Export ───────────────────────────────────────────────────────

    private function exportCsv(array $sections, array $summary, array $filters, string $reportName, string $reportKey, string $ts): array
    {
        $out = fopen('php://temp', 'r+');

        // Header
        fputcsv($out, [$reportName]);
        fputcsv($out, ['Generated', date('Y-m-d H:i:s')]);
        if (!empty($filters)) {
            fputcsv($out, ['Filters', implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($filters), $filters))]);
        }
        fputcsv($out, []);

        // Summary sections
        foreach ($summary as $sectionName => $kpis) {
            fputcsv($out, ["── {$sectionName} ──"]);
            foreach ($kpis as $k => $v) {
                $label = ucwords(str_replace('_', ' ', $k));
                fputcsv($out, [$label, is_bool($v) ? ($v ? 'Yes' : 'No') : $v]);
            }
            fputcsv($out, []);
        }

        // Data sections
        foreach ($sections as $sectionName => $rows) {
            if (empty($rows)) continue;
            fputcsv($out, ["── {$sectionName} ──"]);
            // Headers
            fputcsv($out, array_map(fn($h) => ucwords(str_replace('_', ' ', $h)), array_keys($rows[0])));
            // Rows
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn($v) => is_array($v) ? json_encode($v) : (is_bool($v) ? ($v ? 'Yes' : 'No') : $v), $row));
            }
            fputcsv($out, []);
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return [
            'success' => true,
            'filename' => "{$reportKey}_{$ts}.csv",
            'content' => $csv,
            'content_type' => 'text/csv'
        ];
    }

    // ── XLSX Export ──────────────────────────────────────────────────────

    private function exportXlsx(array $sections, array $summary, array $filters, string $reportName, string $reportKey, string $ts): array
    {
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            // Fallback to CSV
            return array_merge(
                $this->exportCsv($sections, $summary, $filters, $reportName, $reportKey, $ts),
                ['fallback' => true, 'fallback_reason' => 'PhpSpreadsheet not installed. Install: composer require phpoffice/phpspreadsheet']
            );
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getProperties()->setCreator('LMS Reports')->setTitle($reportName);
        $sheetIndex = 0;

        // ── Summary sheet (if we have summary KPIs) ──
        if (!empty($summary)) {
            $sheet = ($sheetIndex === 0) ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $sheet->setTitle($this->sanitizeSheetName('Summary'));

            $row = 1;
            // Report header
            $sheet->setCellValue('A' . $row, $reportName);
            $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14);
            $row++;
            $sheet->setCellValue('A' . $row, 'Generated: ' . date('Y-m-d H:i:s'));
            $row++;

            if (!empty($filters)) {
                $sheet->setCellValue('A' . $row, 'Filters: ' . implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($filters), $filters)));
                $row++;
            }
            $row++;

            foreach ($summary as $sectionName => $kpis) {
                $sheet->setCellValue('A' . $row, $sectionName);
                $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(11);
                $row++;

                foreach ($kpis as $k => $v) {
                    $sheet->setCellValue('A' . $row, ucwords(str_replace('_', ' ', $k)));
                    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
                    $sheet->setCellValue('B' . $row, is_bool($v) ? ($v ? 'Yes' : 'No') : $v);

                    // Highlight percentage values
                    if (str_contains($k, 'rate') || str_contains($k, 'percentage')) {
                        $sheet->getStyle('B' . $row)->getNumberFormat()
                            ->setFormatCode('0.0"%"');
                    }
                    $row++;
                }
                $row++;
            }

            $sheet->getColumnDimension('A')->setWidth(30);
            $sheet->getColumnDimension('B')->setWidth(25);
            $sheetIndex++;
        }

        // ── Data sheets (one per section) ──
        foreach ($sections as $sectionName => $rows) {
            if (empty($rows)) continue;

            $sheet = ($sheetIndex === 0) ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $sheet->setTitle($this->sanitizeSheetName($sectionName));
            $this->writeXlsxDataSheet($sheet, $rows, $sectionName);
            $sheetIndex++;
        }

        // If nothing was written, don't export empty file
        if ($sheetIndex === 0) {
            return ['success' => false, 'error' => 'No data to export'];
        }

        $spreadsheet->setActiveSheetIndex(0);
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $tmp = tempnam(sys_get_temp_dir(), 'rpt_');
        $writer->save($tmp);
        $content = file_get_contents($tmp);
        unlink($tmp);

        return [
            'success' => true,
            'filename' => "{$reportKey}_{$ts}.xlsx",
            'content' => $content,
            'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ];
    }

    /**
     * Write a data table to an XLSX sheet with styled headers and auto-width columns
     */
    private function writeXlsxDataSheet($sheet, array $rows, string $title): void
    {
        $headers = array_keys($rows[0]);

        // Header row
        foreach ($headers as $ci => $h) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci + 1);
            $cell = $col . '1';
            $sheet->setCellValue($cell, ucwords(str_replace('_', ' ', $h)));
            $sheet->getStyle($cell)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle($cell)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FF4472C4');
        }

        // Data rows
        $r = 2;
        foreach ($rows as $row) {
            $c = 1;
            foreach ($row as $k => $v) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
                $cell = $col . $r;

                if (is_array($v)) {
                    $sheet->setCellValue($cell, json_encode($v));
                } elseif (is_bool($v)) {
                    $sheet->setCellValue($cell, $v ? 'Yes' : 'No');
                } else {
                    $sheet->setCellValue($cell, $v);
                }

                // Zebra striping
                if ($r % 2 === 0) {
                    $sheet->getStyle($cell)->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FFF2F2F2');
                }

                // Format rate/percentage columns
                if ((str_contains($k, 'rate') || str_contains($k, 'percentage')) && is_numeric($v)) {
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('0.0');
                }

                // Format score columns
                if (str_contains($k, 'score') && is_numeric($v)) {
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('0.0');
                }

                $c++;
            }
            $r++;
        }

        // Auto-size columns
        foreach ($headers as $ci => $h) {
            $sheet->getColumnDimension(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci + 1)
            )->setAutoSize(true);
        }

        // Freeze header row
        $sheet->freezePane('A2');

        // Auto-filter
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $sheet->setAutoFilter("A1:{$lastCol}1");
    }

    /**
     * Sanitize sheet name for Excel (max 31 chars, no special chars)
     */
    private function sanitizeSheetName(string $name): string
    {
        $name = preg_replace('/[\\\\\/\?\*\[\]:\'"]/', '', $name);
        return mb_substr($name, 0, 31);
    }

    // ── PDF Export ───────────────────────────────────────────────────────

    private function exportPdf(array $sections, array $summary, array $filters, string $reportName, string $reportKey, string $ts): array
    {
        if (!class_exists('TCPDF')) {
            return array_merge(
                $this->exportCsv($sections, $summary, $filters, $reportName, $reportKey, $ts),
                ['fallback' => true, 'fallback_reason' => 'TCPDF not installed. Install: composer require tecnickcom/tcpdf']
            );
        }

        $pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('LMS Reports');
        $pdf->SetTitle($reportName);
        $pdf->SetHeaderData('', 0, $reportName, 'Generated: ' . date('Y-m-d H:i:s'));
        $pdf->setHeaderFont(['helvetica', '', 10]);
        $pdf->setFooterFont(['helvetica', '', 8]);
        $pdf->SetMargins(10, 20, 10);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();

        // ── Filter info ──
        if (!empty($filters)) {
            $filterText = implode(' | ', array_map(fn($k, $v) => ucwords(str_replace('_', ' ', $k)) . ": $v", array_keys($filters), $filters));
            $pdf->SetFont('helvetica', 'I', 8);
            $pdf->Cell(0, 6, "Filters: {$filterText}", 0, 1, 'L');
            $pdf->Ln(2);
        }

        // ── Summary KPI cards ──
        if (!empty($summary)) {
            foreach ($summary as $sectionName => $kpis) {
                $html = '<h3 style="color:#4472C4;">' . htmlspecialchars($sectionName) . '</h3>';
                $html .= '<table cellpadding="3" cellspacing="0" style="font-size:9px; width:100%;">';

                $cols = 0;
                $html .= '<tr>';
                foreach ($kpis as $k => $v) {
                    $label = ucwords(str_replace('_', ' ', $k));
                    $displayVal = is_bool($v) ? ($v ? 'Yes' : 'No') : htmlspecialchars((string)$v);

                    // Highlight compliance rates with color
                    $valStyle = '';
                    if ((str_contains($k, 'rate') || str_contains($k, 'percentage')) && is_numeric($v)) {
                        $displayVal .= '%';
                        if ($v >= 90) $valStyle = 'color:#28A745;';
                        elseif ($v >= 70) $valStyle = 'color:#FFC107;';
                        else $valStyle = 'color:#DC3545;';
                    }

                    $html .= '<td style="border:1px solid #DDD; width:25%;">'
                        . '<strong>' . htmlspecialchars($label) . '</strong><br>'
                        . "<span style=\"font-size:11px;font-weight:bold;{$valStyle}\">{$displayVal}</span>"
                        . '</td>';
                    $cols++;

                    // 4 KPIs per row
                    if ($cols % 4 === 0) {
                        $html .= '</tr><tr>';
                    }
                }
                // Close remaining cells
                while ($cols % 4 !== 0) {
                    $html .= '<td style="border:1px solid #DDD; width:25%;">&nbsp;</td>';
                    $cols++;
                }
                $html .= '</tr></table><br>';
                $pdf->writeHTML($html, true, false, true, false, '');
            }
        }

        // ── Data tables ──
        foreach ($sections as $sectionName => $rows) {
            if (empty($rows)) continue;

            // Section header
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(0, 8, $sectionName . ' (' . count($rows) . ' rows)', 0, 1, 'L');

            $headers = array_keys($rows[0]);
            $colCount = count($headers);

            // Calculate column widths — distribute available width
            $pageWidth = $pdf->getPageWidth() - 20; // margins
            $colWidth = max(20, $pageWidth / min($colCount, 14));

            // If too many columns, use smaller font
            $fontSize = $colCount > 10 ? 6 : ($colCount > 7 ? 7 : 8);

            $html = "<table border=\"1\" cellpadding=\"3\" cellspacing=\"0\" style=\"font-size:{$fontSize}px;\">";

            // Header row
            $html .= '<thead><tr style="background-color:#4472C4;color:#FFF;font-weight:bold;">';
            foreach ($headers as $h) {
                $label = ucwords(str_replace('_', ' ', $h));
                // Truncate long headers
                if (strlen($label) > 15 && $colCount > 8) {
                    $label = substr($label, 0, 13) . '…';
                }
                $html .= '<th>' . htmlspecialchars($label) . '</th>';
            }
            $html .= '</tr></thead><tbody>';

            // Data rows (limit to 500 for PDF performance)
            $ri = 0;
            $maxRows = min(count($rows), 500);
            for ($i = 0; $i < $maxRows; $i++) {
                $row = $rows[$i];
                $bg = ($ri % 2 === 0) ? '#FFF' : '#F2F2F2';
                $html .= "<tr style=\"background-color:{$bg};\">";
                foreach ($row as $k => $v) {
                    $displayVal = is_array($v) ? json_encode($v) : (is_bool($v) ? ($v ? 'Yes' : 'No') : htmlspecialchars((string)$v));

                    // Color-code risk levels
                    $cellStyle = '';
                    if ($k === 'risk_level' || $k === 'severity') {
                        switch ($v) {
                            case 'critical': $cellStyle = 'background-color:#F8D7DA;color:#721C24;'; break;
                            case 'high':     $cellStyle = 'background-color:#FFF3CD;color:#856404;'; break;
                            case 'medium':   $cellStyle = 'background-color:#D4EDDA;color:#155724;'; break;
                            case 'low':      $cellStyle = 'background-color:#D1ECF1;color:#0C5460;'; break;
                        }
                    }

                    // Color-code compliance rates
                    if ((str_contains($k, 'rate') || str_contains($k, 'percentage')) && is_numeric($v)) {
                        if ($v >= 90) $cellStyle = 'color:#28A745;font-weight:bold;';
                        elseif ($v < 70) $cellStyle = 'color:#DC3545;font-weight:bold;';
                    }

                    $html .= "<td style=\"{$cellStyle}\">{$displayVal}</td>";
                }
                $html .= '</tr>';
                $ri++;
            }

            $html .= '</tbody></table>';

            if (count($rows) > 500) {
                $html .= '<p style="font-size:8px;color:#999;">Showing 500 of ' . count($rows) . ' rows. Export as CSV/XLSX for full dataset.</p>';
            }

            $html .= '<br>';
            $pdf->writeHTML($html, true, false, true, false, '');
        }

        return [
            'success' => true,
            'filename' => "{$reportKey}_{$ts}.pdf",
            'content' => $pdf->Output('', 'S'),
            'content_type' => 'application/pdf'
        ];
    }

    // ── Legacy CSV helper (kept for backward compatibility) ──────────

    private function arrayToCsv(array $data): string
    {
        if (empty($data)) return '';
        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_keys($data[0]));
        foreach ($data as $row) {
            fputcsv($out, array_map(
                fn($v) => is_array($v) ? json_encode($v) : (is_bool($v) ? ($v ? 'Yes' : 'No') : $v),
                $row
            ));
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    /** PHASE 1: Resets org filters on cached instances */
    private function getReportInstance(string $className): BaseReport
    {
        if (!isset($this->reportInstances[$className])) {
            $instance = new $className($this->conn);
            $instance->setContext($this->tenant_id);
            if ($className === 'LearnerReports' && $this->user_id) $instance->setUser($this->user_id);
            $this->reportInstances[$className] = $instance;
        }
        $instance = $this->reportInstances[$className];
        $instance->setCourseFilter(null);
        $instance->setDepartmentFilter(null);
        $instance->setTeamFilter(null);
        $instance->setBranchFilter(null);
        $instance->setLearnerFilter(null);
        $instance->setManagerFilter(null);
        return $instance;
    }

    public function getDashboardSummary(): array {
        $summaries = [];
        foreach (['enrollment' => 'EnrollmentReports', 'completion' => 'CompletionReports', 'compliance' => 'ComplianceReports', 'assessment' => 'AssessmentReports', 'gamification' => 'GamificationReports'] as $key => $class) {
            try { $summaries[$key] = $this->getReportInstance($class)->getSummary(); }
            catch (Exception $e) { $summaries[$key] = ['error' => $e->getMessage()]; }
        }
        return $summaries;
    }

    // =========================================================================
    //  PHASE 8: Enhanced Dropdown Data Endpoints
    //  — Search, pagination, cascading filters for all dropdowns
    // =========================================================================

    /**
     * Master dropdown endpoint — returns all org-unit dropdowns in one call.
     * Optionally filters teams by department_id and branches by state.
     */
    public function getOrgUnits(array $params = []): array
    {
        $r = ['departments' => [], 'teams' => [], 'branches' => []];

        // ── Departments (optionally filter by branch) ──
        $deptSQL = "SELECT department_id, department_code, department_name
                    FROM departments WHERE tenant_id = ? AND is_active = 1";
        $deptTypes = 'i';
        $deptParams = [$this->tenant_id];
        if (!empty($params['branch_id'])) {
            $deptSQL .= " AND branch_id = ?";
            $deptTypes .= 'i';
            $deptParams[] = (int)$params['branch_id'];
        }
        $deptSQL .= " ORDER BY department_name";
        $stmt = $this->conn->prepare($deptSQL);
        $stmt->bind_param($deptTypes, ...$deptParams);
        $stmt->execute();
        $r['departments'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // ── Teams (optionally filter by department) ──
        $teamSQL = "SELECT t.team_id, t.team_code, t.team_name
                    FROM teams t WHERE t.tenant_id = ? AND t.is_active = 1";
        $teamTypes = 'i';
        $teamParams = [$this->tenant_id];
        if (!empty($params['department_id'])) {
            $teamSQL .= " AND t.department_id = ?";
            $teamTypes .= 'i';
            $teamParams[] = (int)$params['department_id'];
        }
        $teamSQL .= " ORDER BY t.team_name";
        $stmt = $this->conn->prepare($teamSQL);
        $stmt->bind_param($teamTypes, ...$teamParams);
        $stmt->execute();
        $r['teams'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // ── Branches (optionally filter by state/city) ──
        $branchSQL = "SELECT branch_id, branch_code, branch_name, city, state
                      FROM branches WHERE tenant_id = ? AND is_active = 1";
        $branchTypes = 'i';
        $branchParams = [$this->tenant_id];
        if (!empty($params['state'])) {
            $branchSQL .= " AND state = ?";
            $branchTypes .= 's';
            $branchParams[] = $params['state'];
        }
        $branchSQL .= " ORDER BY branch_name";
        $stmt = $this->conn->prepare($branchSQL);
        $stmt->bind_param($branchTypes, ...$branchParams);
        $stmt->execute();
        $r['branches'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Counts
        $r['counts'] = [
            'departments' => count($r['departments']),
            'teams'       => count($r['teams']),
            'branches'    => count($r['branches']),
        ];

        return $r;
    }

    /**
     * Course dropdown — supports search, category filter, pagination.
     *
     * @param array $params  search, category_id, is_mandatory, page, per_page
     */
    public function getCoursesList(array $params = []): array
    {
        $search    = $params['search'] ?? '';
        $page      = max(1, (int)($params['page'] ?? 1));
        $perPage   = min(200, max(1, (int)($params['per_page'] ?? 100)));
        $offset    = ($page - 1) * $perPage;

        $sql = "SELECT c.course_id, c.title,
                       cat.name as category, c.is_active
                FROM courses c
                LEFT JOIN categories cat ON c.category_id = cat.category_id AND cat.tenant_id = c.tenant_id
                WHERE c.tenant_id = ? AND c.is_active = 1";
        $types  = 'i';
        $values = [$this->tenant_id];

        if (!empty($search)) {
            $sql .= " AND (c.title LIKE ?)";
            $types .= 'ss';
            $like = "%{$search}%";
            $values[] = $like;
            $values[] = $like;
        }
        if (!empty($params['category_id'])) {
            $sql .= " AND c.category_id = ?";
            $types .= 'i';
            $values[] = (int)$params['category_id'];
        }
        if (isset($params['is_mandatory'])) {
            $sql .= " AND c.is_mandatory = ?";
            $types .= 'i';
            $values[] = (int)$params['is_mandatory'];
        }

        // Count
        $countSQL = str_replace(
            "SELECT c.course_id, c.title,\n cat.name as category, c.is_active",
            "SELECT COUNT(*) as total",
            $sql
        );
        $total = $this->execDropdownScalar($countSQL, $types, $values);

        // Data
        $sql .= " ORDER BY c.title LIMIT {$perPage} OFFSET {$offset}";
        $data = $this->execDropdownQuery($sql, $types, $values);

        return [
            'data'  => $data,
            'total' => $total,
            'page'  => $page,
            'per_page' => $perPage,
            'pages' => $total > 0 ? (int)ceil($total / $perPage) : 1,
        ];
    }

    /**
     * Learner dropdown — supports search (name/email/staffId),
     * org-unit cascading filters, pagination.
     *
     * @param array $params  search, department_id, team_id, branch_id, role, page, per_page
     */
    public function getLearnersList(array $params = []): array
    {
        $search  = $params['search'] ?? '';
        $page    = max(1, (int)($params['page'] ?? 1));
        $perPage = min(200, max(1, (int)($params['per_page'] ?? 50)));
        $offset  = ($page - 1) * $perPage;

        $sql = "SELECT u.id as user_id,
                       CONCAT(u.first_name, ' ', u.last_name) as name,
                       u.email, u.staffId,
                       d.department_name, t.team_name, b.branch_name
                FROM users u
                LEFT JOIN departments d ON u.department = d.department_id AND d.tenant_id = u.tenant_id
                LEFT JOIN teams t ON u.team = t.team_id AND t.tenant_id = u.tenant_id
                LEFT JOIN branches b ON u.branch = b.branch_id AND b.tenant_id = u.tenant_id
                WHERE u.tenant_id = ? AND u.is_active = 1";
        $types  = 'i';
        $values = [$this->tenant_id];

        if (!empty($search)) {
            $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.staffId LIKE ?
                      OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
            $types .= 'sssss';
            $like = "%{$search}%";
            $values = array_merge($values, [$like, $like, $like, $like, $like]);
        }
        if (!empty($params['department_id'])) {
            $sql .= " AND u.department = ?";
            $types .= 'i';
            $values[] = (int)$params['department_id'];
        }
        if (!empty($params['team_id'])) {
            $sql .= " AND u.team = ?";
            $types .= 'i';
            $values[] = (int)$params['team_id'];
        }
        if (!empty($params['branch_id'])) {
            $sql .= " AND u.branch = ?";
            $types .= 'i';
            $values[] = (int)$params['branch_id'];
        }
        if (!empty($params['role'])) {
            $sql .= " AND u.role = ?";
            $types .= 's';
            $values[] = $params['role'];
        }

        // Count
        $countSQL = preg_replace(
            '/SELECT .+? FROM users u/s',
            'SELECT COUNT(*) as total FROM users u',
            $sql,
            1
        );
        $total = $this->execDropdownScalar($countSQL, $types, $values);

        // Data
        $sql .= " ORDER BY u.first_name, u.last_name LIMIT {$perPage} OFFSET {$offset}";
        $data = $this->execDropdownQuery($sql, $types, $values);

        return [
            'data'  => $data,
            'total' => $total,
            'page'  => $page,
            'per_page' => $perPage,
            'pages' => $total > 0 ? (int)ceil($total / $perPage) : 1,
        ];
    }

    /**
     * Manager dropdown — lists users who have direct reports.
     *
     * @param array $params  search, department_id
     */
    public function getManagersList(array $params = []): array
    {
        $search = $params['search'] ?? '';

        $sql = "SELECT DISTINCT u.id as manager_id,
                       CONCAT(u.first_name, ' ', u.last_name) as name,
                       u.email, d.department_name,
                       COUNT(DISTINCT r.id) as direct_reports
                FROM users u
                JOIN users r ON r.manager_id = u.id AND r.tenant_id = ? AND r.is_active = 1
                LEFT JOIN departments d ON u.department = d.department_id AND d.tenant_id = u.tenant_id
                WHERE u.tenant_id = ? AND u.is_active = 1";
        $types  = 'ii';
        $values = [$this->tenant_id, $this->tenant_id];

        if (!empty($search)) {
            $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
            $types .= 'sss';
            $like = "%{$search}%";
            $values = array_merge($values, [$like, $like, $like]);
        }
        if (!empty($params['department_id'])) {
            $sql .= " AND u.department = ?";
            $types .= 'i';
            $values[] = (int)$params['department_id'];
        }

        $sql .= " GROUP BY u.id ORDER BY u.first_name, u.last_name";
        return $this->execDropdownQuery($sql, $types, $values);
    }

    /**
     * Assessment dropdown — supports search, type filter.
     *
     * @param array $params  search, assessment_type, course_id
     */
    public function getAssessmentsList(array $params = []): array
    {
        $search = $params['search'] ?? '';

        // Try proc_assessments first (proctored assessment module), fall back to assessments
        $table = 'assessments';
        $titleCol = 'title';
        $idCol = 'assessment_id';
        $check = $this->conn->query("SHOW TABLES LIKE 'proc_assessments'");
        if ($check && $check->num_rows > 0) {
            $table = 'proc_assessments';
        }

        $sql = "SELECT a.{$idCol} as assessment_id, a.{$titleCol} as title,
                       a.assessment_type,
                       c.title as course_title
                FROM {$table} a
                LEFT JOIN courses c ON a.course_id = c.course_id AND c.tenant_id = a.tenant_id
                WHERE a.tenant_id = ? AND a.is_active = 1";
        $types  = 'i';
        $values = [$this->tenant_id];

        if (!empty($search)) {
            $sql .= " AND a.{$titleCol} LIKE ?";
            $types .= 's';
            $values[] = "%{$search}%";
        }
        if (!empty($params['assessment_type'])) {
            $sql .= " AND a.assessment_type = ?";
            $types .= 's';
            $values[] = $params['assessment_type'];
        }
        if (!empty($params['course_id'])) {
            $sql .= " AND a.course_id = ?";
            $types .= 'i';
            $values[] = (int)$params['course_id'];
        }

        $sql .= " ORDER BY a.{$titleCol}";
        return $this->execDropdownQuery($sql, $types, $values);
    }

    /**
     * Learning path dropdown.
     *
     * @param array $params  search
     */
    public function getLearningPathsList(array $params = []): array
    {
        $sql = "SELECT path_id, title as path_name FROM learning_paths
                WHERE tenant_id = ? AND is_active = 1";
        $types  = 'i';
        $values = [$this->tenant_id];

        if (!empty($params['search'])) {
            $sql .= " AND path_name LIKE ?";
            $types .= 's';
            $values[] = "%{$params['search']}%";
        }
        $sql .= " ORDER BY path_name";
        return $this->execDropdownQuery($sql, $types, $values);
    }

    /**
     * ILT sessions dropdown — supports search, date filter, instructor/venue filter.
     *
     * @param array $params  search, instructor_id, venue_id, upcoming_only
     */
    public function getSessionsList(array $params = []): array
    {
        $sql = "SELECT ts.session_id, ts.title as session_name,
                       c.title as course_title,
                       ts.session_date, ts.start_time,
                       CONCAT(instr.first_name, ' ', instr.last_name) as instructor_name,
                       v.venue_name
                FROM live_training_sessions ts
                JOIN courses c ON ts.course_id = c.course_id AND c.tenant_id = ts.tenant_id
                LEFT JOIN users instr ON ts.instructor_id = instr.id
                LEFT JOIN venues v ON ts.venue_id = v.venue_id
                WHERE ts.tenant_id = ? AND ts.is_active = 1";
        $types  = 'i';
        $values = [$this->tenant_id];

        if (!empty($params['search'])) {
            $sql .= " AND (ts.session_name LIKE ? OR c.title LIKE ?)";
            $types .= 'ss';
            $like = "%{$params['search']}%";
            $values = array_merge($values, [$like, $like]);
        }
        if (!empty($params['instructor_id'])) {
            $sql .= " AND ts.instructor_id = ?";
            $types .= 'i';
            $values[] = (int)$params['instructor_id'];
        }
        if (!empty($params['venue_id'])) {
            $sql .= " AND ts.venue_id = ?";
            $types .= 'i';
            $values[] = (int)$params['venue_id'];
        }
        if (!empty($params['upcoming_only'])) {
            $sql .= " AND ts.session_date >= CURDATE()";
        }

        $sql .= " ORDER BY ts.session_date DESC";
        return $this->execDropdownQuery($sql, $types, $values);
    }

    /** Instructors dropdown — users who have instructed sessions */
    public function getInstructorsList(array $params = []): array
    {
        $sql = "SELECT DISTINCT u.id as instructor_id,
                       CONCAT(u.first_name, ' ', u.last_name) as name,
                       u.email
                FROM users u
                JOIN live_training_sessions ts ON ts.instructor_id = u.id AND ts.tenant_id = ?
                WHERE u.is_active = 1";
        $types  = 'i';
        $values = [$this->tenant_id];

        if (!empty($params['search'])) {
            $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ?)";
            $types .= 'ss';
            $like = "%{$params['search']}%";
            $values = array_merge($values, [$like, $like]);
        }
        $sql .= " ORDER BY u.first_name, u.last_name";
        return $this->execDropdownQuery($sql, $types, $values);
    }

    /** Venues dropdown */
    public function getVenuesList(array $params = []): array
    {
        $sql = "SELECT venue_id, venue_name, location, capacity
                FROM venues WHERE tenant_id = ? AND is_active = 1";
        $types  = 'i';
        $values = [$this->tenant_id];

        if (!empty($params['search'])) {
            $sql .= " AND (venue_name LIKE ? OR location LIKE ?)";
            $types .= 'ss';
            $like = "%{$params['search']}%";
            $values = array_merge($values, [$like, $like]);
        }
        $sql .= " ORDER BY venue_name";
        return $this->execDropdownQuery($sql, $types, $values);
    }

    /** Course categories dropdown */
    public function getCategoriesList(): array
    {
        $stmt = $this->conn->prepare(
            "SELECT category_id,name as category_name FROM categories
             WHERE tenant_id = ? AND is_active = 1 ORDER BY category_name"
        );
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $data;
    }

    /** Branch states dropdown (for cascading state → branch filter) */
    public function getBranchStates(): array
    {
        $stmt = $this->conn->prepare(
            "SELECT DISTINCT state FROM branches
             WHERE tenant_id = ? AND is_active = 1 AND state IS NOT NULL AND state != ''
             ORDER BY state"
        );
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return array_column($data, 'state');
    }

    /** Master meta endpoint — returns all available dropdown keys with descriptions */
    public function getDropdownMeta(): array
    {
        return [
            'org_units'     => ['endpoint' => 'org-units', 'description' => 'Departments, teams, branches', 'supports' => 'cascading filters (branch_id→depts, department_id→teams, state→branches)'],
            'courses'       => ['endpoint' => 'courses-list', 'description' => 'Active courses', 'supports' => 'search, category_id, is_mandatory, pagination'],
            'learners'      => ['endpoint' => 'learners-list', 'description' => 'Active learners', 'supports' => 'search, department_id, team_id, branch_id, role, pagination'],
            'managers'      => ['endpoint' => 'managers-list', 'description' => 'Users with direct reports', 'supports' => 'search, department_id'],
            'assessments'   => ['endpoint' => 'assessments-list', 'description' => 'Active assessments', 'supports' => 'search, assessment_type, course_id'],
            'learning_paths'=> ['endpoint' => 'paths-list', 'description' => 'Active learning paths', 'supports' => 'search'],
            'sessions'      => ['endpoint' => 'sessions-list', 'description' => 'ILT sessions', 'supports' => 'search, instructor_id, venue_id, upcoming_only'],
            'instructors'   => ['endpoint' => 'instructors-list', 'description' => 'ILT instructors', 'supports' => 'search'],
            'venues'        => ['endpoint' => 'venues-list', 'description' => 'Training venues', 'supports' => 'search'],
            'categories'    => ['endpoint' => 'categories-list', 'description' => 'Course categories'],
            'branch_states' => ['endpoint' => 'branch-states', 'description' => 'Distinct branch states for cascading filter'],
        ];
    }

    // ── Dropdown query helpers ──

    private function execDropdownQuery(string $sql, string $types, array $values): array
    {
        if (empty($types)) {
            $res = $this->conn->query($sql);
            return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        }
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return [];
        $refs = [$types];
        for ($i = 0; $i < count($values); $i++) {
            $refs[] = &$values[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $data;
    }

    private function execDropdownScalar(string $sql, string $types, array $values): int
    {
        if (empty($types)) {
            $res = $this->conn->query($sql);
            return $res ? (int)$res->fetch_assoc()['total'] : 0;
        }
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return 0;
        $refs = [$types];
        for ($i = 0; $i < count($values); $i++) {
            $refs[] = &$values[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
        return $total;
    }

    /** Stub — Phase 14 */
    public function scheduleReport(string $reportKey, array $schedule): array {
        return ['scheduled' => true, 'report_key' => $reportKey, 'schedule' => $schedule];
    }

    // ==========================================
    // Phase 6: Learner helpers
    // ==========================================

    /** Get learner sub-categories for frontend sidebar grouping */
    public function getLearnerCategories(): array {
        return array_values(array_filter($this->getCategories(), fn($c) => isset($c['parent']) && $c['parent'] === 'learner'));
    }

    /** Get learner report catalog (audience = learner) */
    public function getLearnerCatalog(): array {
        return $this->getReportCatalog(null, 'learner');
    }

    /** Learner learning paths dropdown (only paths the learner is enrolled in) */
    public function getLearnerPathsList(): array {
        if (!$this->user_id) return [];
        $stmt = $this->conn->prepare(
            "SELECT lp.path_id, lp.title as path_name
             FROM learning_path_enrollments lpe
             JOIN learning_paths lp ON lp.path_id = lpe.path_id
             WHERE lpe.user_id = ? AND lpe.tenant_id = ? ORDER BY path_name"
        );
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute(); $d = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close(); return $d;
    }

    // ==========================================
    // Phase 7: Custom Report Builder facade
    // ==========================================

    /** Get the custom report builder instance */
    private function getCustomBuilder(): CustomReportBuilder {
        /** @var CustomReportBuilder $instance */
        $instance = $this->getReportInstance('CustomReportBuilder');
        if ($this->user_id) $instance->setUser($this->user_id);
        return $instance;
    }

    /** List data sources for custom report builder */
    public function getCustomSources(): array {
        return $this->getCustomBuilder()->getAvailableSources();
    }

    /** List available columns for a source */
    public function getCustomColumns(string $source): array {
        return $this->getCustomBuilder()->getColumnsForSource($source);
    }

    /** List available filters for a source */
    public function getCustomFilters(string $source): array {
        return $this->getCustomBuilder()->getFiltersForSource($source);
    }

    /** List available GROUP BY keys for a source */
    public function getCustomGroupings(string $source): array {
        return $this->getCustomBuilder()->getGroupingsForSource($source);
    }

    /** Build and execute a custom report */
    public function buildCustomReport(array $config): array {
        return $this->getCustomBuilder()->buildCustomReport($config);
    }

    /** Save a custom report configuration */
    public function saveCustomReport(string $name, string $description, array $config): array {
        return $this->getCustomBuilder()->saveCustomReport($name, $description, $config);
    }

    /** Update a saved custom report */
    public function updateCustomReport(int $reportId, string $name, string $description, array $config): array {
        return $this->getCustomBuilder()->updateCustomReport($reportId, $name, $description, $config);
    }

    /** Delete (soft) a saved custom report */
    public function deleteCustomReport(int $reportId): array {
        return $this->getCustomBuilder()->deleteCustomReport($reportId);
    }

    /** List saved custom reports */
    public function listSavedReports(?int $userId = null): array {
        return $this->getCustomBuilder()->listSavedReports($userId);
    }

    /** Run a saved custom report */
    public function runSavedReport(int $reportId, array $overrides = []): array {
        return $this->getCustomBuilder()->runSavedReport($reportId, $overrides);
    }

    /** Duplicate a saved custom report */
    public function duplicateCustomReport(int $reportId): array {
        return $this->getCustomBuilder()->duplicateCustomReport($reportId);
    }
}