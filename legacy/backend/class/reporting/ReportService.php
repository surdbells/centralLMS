<?php

/**
 * ReportService - Factory and Facade for all LMS reports
 * Routes report keys to the correct class/method, handles scoping, pagination, export
 *
 * Multi-Tenant LMS Report Module - Phase 1
 */

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

class ReportService
{
    private mysqli $conn;
    private int $tenant_id;
    private int $user_id;

    /**
     * Master catalog of all available reports
     * Each entry maps a report_key to its class, method, metadata
     */
    private array $reportCatalog = [

        // ==========================================
        // ENROLLMENT REPORTS (7)
        // ==========================================
        'enrollment_summary' => [
            'class' => 'EnrollmentReports', 'method' => 'enrollmentSummary',
            'name' => 'Enrollment Summary', 'category' => 'enrollment',
            'description' => 'Overall enrollment statistics with status breakdown and department drill-down',
            'audience' => ['admin', 'hr'], 'exportable' => false, 'type' => 'summary'
        ],
        'enrollment_trends' => [
            'class' => 'EnrollmentReports', 'method' => 'enrollmentTrends',
            'name' => 'Enrollment Trends', 'category' => 'enrollment',
            'description' => 'Enrollment volume over time (daily/weekly/monthly)',
            'audience' => ['admin', 'hr'], 'exportable' => false, 'type' => 'chart'
        ],
        'enrollment_detail_list' => [
            'class' => 'EnrollmentReports', 'method' => 'enrollmentDetailList',
            'name' => 'Enrollment Detail List', 'category' => 'enrollment',
            'description' => 'Detailed enrollment records with learner, course, status, progress, score',
            'audience' => ['admin', 'hr'], 'exportable' => true, 'type' => 'table'
        ],
        'pending_enrollments' => [
            'class' => 'EnrollmentReports', 'method' => 'pendingEnrollments',
            'name' => 'Pending / Not Started', 'category' => 'enrollment',
            'description' => 'Learners enrolled but haven\'t started their courses',
            'audience' => ['admin', 'hr'], 'exportable' => true, 'type' => 'table'
        ],
        'enrollment_by_source' => [
            'class' => 'EnrollmentReports', 'method' => 'enrollmentBySource',
            'name' => 'Enrollment by Source', 'category' => 'enrollment',
            'description' => 'How learners were enrolled (auto-assign, manual, self, import)',
            'audience' => ['admin'], 'exportable' => false, 'type' => 'chart'
        ],
        'drop_off_report' => [
            'class' => 'EnrollmentReports', 'method' => 'dropOffReport',
            'name' => 'Drop-off Report', 'category' => 'enrollment',
            'description' => 'Learners who enrolled but dropped or became inactive (14+ days)',
            'audience' => ['admin', 'hr'], 'exportable' => true, 'type' => 'table'
        ],
        'enrollment_by_course' => [
            'class' => 'EnrollmentReports', 'method' => 'enrollmentByCourse',
            'name' => 'Enrollment by Course', 'category' => 'enrollment',
            'description' => 'Per-course enrollment, completion, and score breakdown',
            'audience' => ['admin', 'hr'], 'exportable' => true, 'type' => 'table'
        ],

        // ==========================================
        // COMPLETION REPORTS (8)
        // ==========================================
        'completion_summary' => [
            'class' => 'CompletionReports', 'method' => 'completionSummary',
            'name' => 'Course Completion Summary', 'category' => 'completion',
            'description' => 'Overall completion statistics with trend chart and overdue count',
            'audience' => ['admin', 'hr'], 'exportable' => false, 'type' => 'summary'
        ],
        'individual_progress' => [
            'class' => 'CompletionReports', 'method' => 'individualProgress',
            'name' => 'Individual Progress Report', 'category' => 'completion',
            'description' => 'Detailed progress for a specific learner across all courses',
            'audience' => ['admin', 'hr', 'manager'], 'exportable' => true, 'type' => 'detail',
            'requires' => ['learner_id']
        ],
        'completion_by_department' => [
            'class' => 'CompletionReports', 'method' => 'completionByDepartment',
            'name' => 'Completion by Department', 'category' => 'completion',
            'description' => 'Department-wise completion rates, overdue count, avg scores',
            'audience' => ['admin', 'hr'], 'exportable' => true, 'type' => 'table'
        ],
        'completion_by_team' => [
            'class' => 'CompletionReports', 'method' => 'completionByTeam',
            'name' => 'Completion by Team', 'category' => 'completion',
            'description' => 'Team-wise completion rates, avg progress, scores',
            'audience' => ['admin', 'hr', 'manager'], 'exportable' => true, 'type' => 'table'
        ],
        'completion_by_branch' => [
            'class' => 'CompletionReports', 'method' => 'completionByBranch',
            'name' => 'Completion by Branch', 'category' => 'completion',
            'description' => 'Branch-wise completion rates and scores',
            'audience' => ['admin', 'hr'], 'exportable' => true, 'type' => 'table'
        ],
        'overdue_training' => [
            'class' => 'CompletionReports', 'method' => 'overdueTraining',
            'name' => 'Overdue Training Report', 'category' => 'completion',
            'description' => 'All training past due dates with urgency levels',
            'audience' => ['admin', 'hr', 'manager'], 'exportable' => true, 'type' => 'table'
        ],
        'time_to_completion' => [
            'class' => 'CompletionReports', 'method' => 'timeToCompletion',
            'name' => 'Time to Completion Analysis', 'category' => 'completion',
            'description' => 'How long learners take to complete each course (avg/min/max)',
            'audience' => ['admin'], 'exportable' => true, 'type' => 'table'
        ],
        'completion_trend' => [
            'class' => 'CompletionReports', 'method' => 'completionTrend',
            'name' => 'Completion Trend', 'category' => 'completion',
            'description' => 'Completion volume over time',
            'audience' => ['admin', 'hr'], 'exportable' => false, 'type' => 'chart'
        ],

        // ==========================================
        // COMPLIANCE REPORTS (8)
        // ==========================================
        'executive_compliance_summary' => [
            'class' => 'ComplianceReports', 'method' => 'executiveComplianceSummary',
            'name' => 'Executive Compliance Summary', 'category' => 'compliance',
            'description' => 'Organization-wide compliance with drill-down: departments, branches, teams, non-compliant learners',
            'audience' => ['admin', 'hr', 'executive'], 'exportable' => false, 'type' => 'executive'
        ],
        'mandatory_training_compliance' => [
            'class' => 'ComplianceReports', 'method' => 'mandatoryTrainingCompliance',
            'name' => 'Mandatory Training Compliance', 'category' => 'compliance',
            'description' => 'User-level mandatory training status with compliance flags',
            'audience' => ['admin', 'hr', 'manager'], 'exportable' => true, 'type' => 'table'
        ],
        'certification_expiry' => [
            'class' => 'ComplianceReports', 'method' => 'certificationExpiry',
            'name' => 'Certification Expiry Report', 'category' => 'compliance',
            'description' => 'Certificates expiring within specified period',
            'audience' => ['admin', 'hr'], 'exportable' => true, 'type' => 'table'
        ],
        'non_compliant_users' => [
            'class' => 'ComplianceReports', 'method' => 'nonCompliantUsers',
            'name' => 'Non-Compliant Users', 'category' => 'compliance',
            'description' => 'Users with overdue mandatory training, grouped by risk level',
            'audience' => ['admin', 'hr', 'manager'], 'exportable' => true, 'type' => 'table'
        ],
        'compliance_by_department' => [
            'class' => 'ComplianceReports', 'method' => 'complianceByDepartment',
            'name' => 'Compliance by Department', 'category' => 'compliance',
            'description' => 'Department-level compliance percentages and risk levels',
            'audience' => ['admin', 'hr'], 'exportable' => true, 'type' => 'table'
        ],
        'regulatory_training_audit' => [
            'class' => 'ComplianceReports', 'method' => 'regulatoryTrainingAudit',
            'name' => 'Regulatory Training Audit', 'category' => 'compliance',
            'description' => 'Detailed audit trail for mandatory training with certificate info',
            'audience' => ['admin', 'hr', 'legal'], 'exportable' => true, 'type' => 'table'
        ],
        'compliance_gap_analysis' => [
            'class' => 'ComplianceReports', 'method' => 'complianceGapAnalysis',
            'name' => 'Compliance Gap Analysis', 'category' => 'compliance',
            'description' => 'Gap between required and completed training per department',
            'audience' => ['admin', 'hr', 'legal'], 'exportable' => true, 'type' => 'table'
        ],
        'recertification_due' => [
            'class' => 'ComplianceReports', 'method' => 'recertificationDue',
            'name' => 'Recertification Due', 'category' => 'compliance',
            'description' => 'Users needing recertification within specified period',
            'audience' => ['admin', 'hr'], 'exportable' => true, 'type' => 'table'
        ],

        // ==========================================
        // ASSESSMENT REPORTS (10)
        // ==========================================
        'assessment_results_summary' => [
            'class' => 'AssessmentReports', 'method' => 'assessmentResultsSummary',
            'name' => 'Assessment Results Summary', 'category' => 'assessment',
            'description' => 'Overall assessment performance: pass rate, avg score, per-assessment breakdown',
            'audience' => ['admin', 'hr'], 'exportable' => false, 'type' => 'summary'
        ],
        'individual_assessment_report' => [
            'class' => 'AssessmentReports', 'method' => 'individualAssessmentReport',
            'name' => 'Individual Assessment Report', 'category' => 'assessment',
            'description' => 'All assessment attempts for a specific learner',
            'audience' => ['admin', 'hr'], 'exportable' => true, 'type' => 'detail',
            'requires' => ['learner_id']
        ],
        'failed_assessments' => [
            'class' => 'AssessmentReports', 'method' => 'failedAssessments',
            'name' => 'Failed Assessments Report', 'category' => 'assessment',
            'description' => 'Users who failed assessments — identify intervention needs',
            'audience' => ['admin', 'hr'], 'exportable' => true, 'type' => 'table'
        ],
        'question_analysis' => [
            'class' => 'AssessmentReports', 'method' => 'questionAnalysis',
            'name' => 'Question Analysis Report', 'category' => 'assessment',
            'description' => 'Per-question performance: correct rate, difficulty index, discrimination',
            'audience' => ['admin'], 'exportable' => true, 'type' => 'detail',
            'requires' => ['assessment_id']
        ],
        'score_distribution' => [
            'class' => 'AssessmentReports', 'method' => 'scoreDistribution',
            'name' => 'Score Distribution Report', 'category' => 'assessment',
            'description' => 'How scores are distributed across learners (bell curve)',
            'audience' => ['admin'], 'exportable' => false, 'type' => 'chart'
        ],
        'assessment_attempts' => [
            'class' => 'AssessmentReports', 'method' => 'assessmentAttempts',
            'name' => 'Assessment Attempts Report', 'category' => 'assessment',
            'description' => 'Attempts per assessment: avg retries, first-try pass rate',
            'audience' => ['admin'], 'exportable' => true, 'type' => 'table'
        ],
        'top_performers' => [
            'class' => 'AssessmentReports', 'method' => 'topPerformers',
            'name' => 'Top Performers Report', 'category' => 'assessment',
            'description' => 'Highest scoring learners ranked by average assessment score',
            'audience' => ['admin', 'hr'], 'exportable' => true, 'type' => 'table'
        ],
        'struggling_learners' => [
            'class' => 'AssessmentReports', 'method' => 'strugglingLearners',
            'name' => 'Struggling Learners Report', 'category' => 'assessment',
            'description' => 'Users with consistently low scores needing support',
            'audience' => ['admin', 'hr'], 'exportable' => true, 'type' => 'table'
        ],
        'proctoring_violations' => [
            'class' => 'AssessmentReports', 'method' => 'proctoringViolations',
            'name' => 'Proctoring Violation Report', 'category' => 'assessment',
            'description' => 'Proctored exam violations: tab switches, face detection, copy/paste',
            'audience' => ['admin'], 'exportable' => true, 'type' => 'table'
        ],
        'assessment_results_detail' => [
            'class' => 'AssessmentReports', 'method' => 'assessmentResultsDetail',
            'name' => 'Assessment Results Detail', 'category' => 'assessment',
            'description' => 'All attempt records with score, grade, duration — filterable and exportable',
            'audience' => ['admin', 'hr'], 'exportable' => true, 'type' => 'table'
        ],

        // ==========================================
        // USER & DEPARTMENT REPORTS (8)
        // ==========================================
        'active_users' => [
            'class' => 'UserReports', 'method' => 'activeUsers',
            'name' => 'Active Users Report', 'category' => 'user',
            'description' => 'Users actively using the LMS with last login and activity level',
            'audience' => ['admin'], 'exportable' => true, 'type' => 'table'
        ],
        'inactive_users' => [
            'class' => 'UserReports', 'method' => 'inactiveUsers',
            'name' => 'Inactive Users Report', 'category' => 'user',
            'description' => 'Users with no recent activity and pending courses',
            'audience' => ['admin', 'hr'], 'exportable' => true, 'type' => 'table'
        ],
        'new_users' => [
            'class' => 'UserReports', 'method' => 'newUsers',
            'name' => 'New Users Report', 'category' => 'user',
            'description' => 'Recently added users with onboarding status',
            'audience' => ['admin'], 'exportable' => true, 'type' => 'table'
        ],
        'user_activity_summary' => [
            'class' => 'UserReports', 'method' => 'userActivitySummary',
            'name' => 'User Activity Summary', 'category' => 'user',
            'description' => 'Complete LMS activity profile: courses, assessments, certificates, logins',
            'audience' => ['admin', 'hr'], 'exportable' => false, 'type' => 'detail',
            'requires' => ['learner_id']
        ],
        'department_training_summary' => [
            'class' => 'UserReports', 'method' => 'departmentTrainingSummary',
            'name' => 'Department Training Summary', 'category' => 'user',
            'description' => 'Training overview by department with compliance rates',
            'audience' => ['admin', 'hr'], 'exportable' => true, 'type' => 'table'
        ],
        'managers_team_report' => [
            'class' => 'UserReports', 'method' => 'managersTeamReport',
            'name' => "Manager's Team Report", 'category' => 'user',
            'description' => 'Training status of direct reports in a department/team/branch',
            'audience' => ['admin', 'hr', 'manager'], 'exportable' => true, 'type' => 'table'
        ],
        'user_login_history' => [
            'class' => 'UserReports', 'method' => 'userLoginHistory',
            'name' => 'User Login History', 'category' => 'user',
            'description' => 'Login patterns, frequency, device, and session duration',
            'audience' => ['admin'], 'exportable' => true, 'type' => 'table'
        ],
        'role_based_training' => [
            'class' => 'UserReports', 'method' => 'roleBasedTraining',
            'name' => 'Role-based Training Report', 'category' => 'user',
            'description' => 'Training completion and compliance by organizational role',
            'audience' => ['admin', 'hr'], 'exportable' => true, 'type' => 'table'
        ],

        // ==========================================
        // COURSE & CONTENT REPORTS (9)
        // ==========================================
        'course_catalog' => [
            'class' => 'CourseReports', 'method' => 'courseCatalog',
            'name' => 'Course Catalog Report', 'category' => 'course',
            'description' => 'All courses with enrollment, completion, score, and rating metrics',
            'audience' => ['admin'], 'exportable' => true, 'type' => 'table'
        ],
        'course_effectiveness' => [
            'class' => 'CourseReports', 'method' => 'courseEffectiveness',
            'name' => 'Course Effectiveness Report', 'category' => 'course',
            'description' => 'Course performance: completion rate, scores, ratings, composite effectiveness score',
            'audience' => ['admin'], 'exportable' => true, 'type' => 'table'
        ],
        'course_usage' => [
            'class' => 'CourseReports', 'method' => 'courseUsage',
            'name' => 'Course Usage Report', 'category' => 'course',
            'description' => 'Enrollment volume, unique learners, completion, and total time per course',
            'audience' => ['admin'], 'exportable' => true, 'type' => 'table'
        ],
        'module_performance' => [
            'class' => 'CourseReports', 'method' => 'modulePerformance',
            'name' => 'Module Performance Report', 'category' => 'course',
            'description' => 'Per-module drop-off, retention, and time analysis for a specific course',
            'audience' => ['admin'], 'exportable' => true, 'type' => 'table',
            'requires' => ['course_id']
        ],
        'content_engagement' => [
            'class' => 'CourseReports', 'method' => 'contentEngagement',
            'name' => 'Content Engagement Report', 'category' => 'course',
            'description' => 'Interaction with course content: video plays, document views, replay rate',
            'audience' => ['admin'], 'exportable' => false, 'type' => 'chart'
        ],
        'course_ratings' => [
            'class' => 'CourseReports', 'method' => 'courseRatings',
            'name' => 'Course Ratings & Feedback', 'category' => 'course',
            'description' => 'User ratings, star distribution, and review summaries',
            'audience' => ['admin'], 'exportable' => true, 'type' => 'table'
        ],
        'unused_courses' => [
            'class' => 'CourseReports', 'method' => 'unusedCourses',
            'name' => 'Unused Courses Report', 'category' => 'course',
            'description' => 'Courses with low or no enrollment — archive or review recommendations',
            'audience' => ['admin'], 'exportable' => true, 'type' => 'table'
        ],
        'course_version_history' => [
            'class' => 'CourseReports', 'method' => 'courseVersionHistory',
            'name' => 'Course Version History', 'category' => 'course',
            'description' => 'Track changes and module updates for a specific course',
            'audience' => ['admin'], 'exportable' => false, 'type' => 'detail',
            'requires' => ['course_id']
        ],
        'scorm_activity' => [
            'class' => 'CourseReports', 'method' => 'scormActivity',
            'name' => 'SCORM/xAPI Activity Report', 'category' => 'course',
            'description' => 'Detailed SCORM tracking data per lesson',
            'audience' => ['admin'], 'exportable' => true, 'type' => 'table'
        ],

        // ==========================================
        // TIME & ENGAGEMENT REPORTS (6)
        // ==========================================
        'time_spent' => [
            'class' => 'TimeEngagementReports', 'method' => 'timeSpent',
            'name' => 'Time Spent Report', 'category' => 'time',
            'description' => 'Learning time by learner, course, or department',
            'audience' => ['admin', 'hr'], 'exportable' => true, 'type' => 'table'
        ],
        'peak_usage' => [
            'class' => 'TimeEngagementReports', 'method' => 'peakUsage',
            'name' => 'Peak Usage Report', 'category' => 'time',
            'description' => 'When the LMS is most used — by hour and day of week',
            'audience' => ['admin'], 'exportable' => false, 'type' => 'chart'
        ],
        'session_duration' => [
            'class' => 'TimeEngagementReports', 'method' => 'sessionDuration',
            'name' => 'Session Duration Report', 'category' => 'time',
            'description' => 'Average session length, distribution, patterns',
            'audience' => ['admin'], 'exportable' => false, 'type' => 'chart'
        ],
        'engagement_score' => [
            'class' => 'TimeEngagementReports', 'method' => 'engagementScore',
            'name' => 'Engagement Score Report', 'category' => 'time',
            'description' => 'Composite engagement score per learner (completion + time + login + assessments)',
            'audience' => ['admin', 'hr'], 'exportable' => true, 'type' => 'table'
        ],
        'activity_report' => [
            'class' => 'TimeEngagementReports', 'method' => 'activityReport',
            'name' => 'Daily/Weekly Activity Report', 'category' => 'time',
            'description' => 'Activity volume and active users over time',
            'audience' => ['admin'], 'exportable' => false, 'type' => 'chart'
        ],
        'platform_usage' => [
            'class' => 'TimeEngagementReports', 'method' => 'platformUsage',
            'name' => 'Mobile vs Desktop Usage', 'category' => 'time',
            'description' => 'Platform/device breakdown for LMS access',
            'audience' => ['admin'], 'exportable' => false, 'type' => 'chart'
        ],

        // ==========================================
        // GAMIFICATION REPORTS (6)
        // ==========================================
        'leaderboard' => [
            'class' => 'GamificationReports', 'method' => 'leaderboard',
            'name' => 'Leaderboard Report', 'category' => 'gamification',
            'description' => 'Top learners by points, badges, completions, and streaks',
            'audience' => ['admin', 'hr'], 'exportable' => true, 'type' => 'table'
        ],
        'badge_distribution' => [
            'class' => 'GamificationReports', 'method' => 'badgeDistribution',
            'name' => 'Badge Distribution Report', 'category' => 'gamification',
            'description' => 'Badges earned across the organization with earn rates',
            'audience' => ['admin'], 'exportable' => true, 'type' => 'table'
        ],
        'points_summary' => [
            'class' => 'GamificationReports', 'method' => 'pointsSummary',
            'name' => 'Points Summary Report', 'category' => 'gamification',
            'description' => 'Points by activity type with monthly trend',
            'audience' => ['admin'], 'exportable' => false, 'type' => 'summary'
        ],
        'milestone_achievements' => [
            'class' => 'GamificationReports', 'method' => 'milestoneAchievements',
            'name' => 'Milestone Achievement Report', 'category' => 'gamification',
            'description' => 'Key milestones reached by learners',
            'audience' => ['admin'], 'exportable' => true, 'type' => 'table'
        ],
        'gamification_participation' => [
            'class' => 'GamificationReports', 'method' => 'gamificationParticipation',
            'name' => 'Gamification Participation', 'category' => 'gamification',
            'description' => 'Engagement rates with gamification features',
            'audience' => ['admin'], 'exportable' => false, 'type' => 'summary'
        ],
        'streak_report' => [
            'class' => 'GamificationReports', 'method' => 'streakReport',
            'name' => 'Streak Report', 'category' => 'gamification',
            'description' => 'Learning streak statistics — current and longest',
            'audience' => ['admin'], 'exportable' => true, 'type' => 'table'
        ],

        // ==========================================
        // CERTIFICATE REPORTS (5)
        // ==========================================
        'certificates_issued' => [
            'class' => 'CertificateReports', 'method' => 'certificatesIssued',
            'name' => 'Certificates Issued Report', 'category' => 'certificate',
            'description' => 'All certificates issued with status and recipient info',
            'audience' => ['admin', 'hr'], 'exportable' => true, 'type' => 'table'
        ],
        'verification_log' => [
            'class' => 'CertificateReports', 'method' => 'verificationLog',
            'name' => 'Certificate Verification Log', 'category' => 'certificate',
            'description' => 'External verification requests and results',
            'audience' => ['admin'], 'exportable' => true, 'type' => 'table'
        ],
        'expiring_certificates' => [
            'class' => 'CertificateReports', 'method' => 'expiringCertificates',
            'name' => 'Expiring Certificates Report', 'category' => 'certificate',
            'description' => 'Certificates expiring within date range with urgency levels',
            'audience' => ['admin', 'hr'], 'exportable' => true, 'type' => 'table'
        ],
        'reprint_history' => [
            'class' => 'CertificateReports', 'method' => 'reprintHistory',
            'name' => 'Certificate Reprint History', 'category' => 'certificate',
            'description' => 'Certificates reprinted or reissued with reason',
            'audience' => ['admin'], 'exportable' => true, 'type' => 'table'
        ],
        'template_usage' => [
            'class' => 'CertificateReports', 'method' => 'templateUsage',
            'name' => 'Certificate Template Usage', 'category' => 'certificate',
            'description' => 'Which certificate templates are used most',
            'audience' => ['admin'], 'exportable' => false, 'type' => 'table'
        ],

        // ==========================================
        // SYSTEM REPORTS (6)
        // ==========================================
        'system_usage' => [
            'class' => 'SystemReports', 'method' => 'systemUsage',
            'name' => 'System Usage Report', 'category' => 'system',
            'description' => 'Overall LMS utilization: users, courses, enrollments, assessments',
            'audience' => ['admin'], 'exportable' => false, 'type' => 'summary'
        ],
        'error_log' => [
            'class' => 'SystemReports', 'method' => 'errorLog',
            'name' => 'Error & Issue Log', 'category' => 'system',
            'description' => 'System errors with type, frequency, resolution status',
            'audience' => ['admin'], 'exportable' => true, 'type' => 'table'
        ],
        'admin_activity_log' => [
            'class' => 'SystemReports', 'method' => 'adminActivityLog',
            'name' => 'Admin Activity Log', 'category' => 'system',
            'description' => 'Actions performed by administrators',
            'audience' => ['admin'], 'exportable' => true, 'type' => 'table'
        ],
        'storage_usage' => [
            'class' => 'SystemReports', 'method' => 'storageUsage',
            'name' => 'Storage Usage Report', 'category' => 'system',
            'description' => 'Content storage utilization by course',
            'audience' => ['admin'], 'exportable' => false, 'type' => 'table'
        ],
        'integration_sync' => [
            'class' => 'SystemReports', 'method' => 'integrationSync',
            'name' => 'Integration Sync Report', 'category' => 'system',
            'description' => 'HR system integration sync status and errors',
            'audience' => ['admin'], 'exportable' => true, 'type' => 'table'
        ],
        'tenant_usage' => [
            'class' => 'SystemReports', 'method' => 'tenantUsage',
            'name' => 'Tenant Usage Report', 'category' => 'system',
            'description' => 'Multi-tenant usage comparison (superadmin)',
            'audience' => ['admin'], 'exportable' => true, 'type' => 'table'
        ],

        // ==========================================
        // EXECUTIVE REPORTS (5)
        // ==========================================
        'executive_dashboard' => [
            'class' => 'ExecutiveReports', 'method' => 'executiveDashboard',
            'name' => 'Executive Training Dashboard', 'category' => 'executive',
            'description' => 'High-level KPIs: completion rate, compliance, trends, top departments',
            'audience' => ['admin', 'executive'], 'exportable' => false, 'type' => 'executive'
        ],
        'monthly_training_summary' => [
            'class' => 'ExecutiveReports', 'method' => 'monthlyTrainingSummary',
            'name' => 'Monthly Training Summary', 'category' => 'executive',
            'description' => 'Monthly training metrics and highlights',
            'audience' => ['admin', 'hr', 'executive'], 'exportable' => false, 'type' => 'summary'
        ],
        'training_roi' => [
            'class' => 'ExecutiveReports', 'method' => 'trainingROI',
            'name' => 'Training ROI Report', 'category' => 'executive',
            'description' => 'Return on training investment: efficiency, time per completion',
            'audience' => ['admin', 'hr', 'finance'], 'exportable' => true, 'type' => 'table'
        ],
        'year_over_year' => [
            'class' => 'ExecutiveReports', 'method' => 'yearOverYear',
            'name' => 'Year-over-Year Comparison', 'category' => 'executive',
            'description' => 'Compare enrollments, completions, scores, hours across years',
            'audience' => ['admin', 'hr'], 'exportable' => false, 'type' => 'summary'
        ],
        'budget_utilization' => [
            'class' => 'ExecutiveReports', 'method' => 'budgetUtilization',
            'name' => 'Training Budget Utilization', 'category' => 'executive',
            'description' => 'Budget allocated vs spent (requires cost table)',
            'audience' => ['admin', 'finance'], 'exportable' => false, 'type' => 'summary'
        ],

        // ==========================================
        // AUDIT REPORTS (6)
        // ==========================================
        'full_audit_trail' => [
            'class' => 'AuditReports', 'method' => 'fullAuditTrail',
            'name' => 'Full Audit Trail', 'category' => 'audit',
            'description' => 'Complete activity log for compliance auditing',
            'audience' => ['admin', 'legal'], 'exportable' => true, 'type' => 'table'
        ],
        'data_access_log' => [
            'class' => 'AuditReports', 'method' => 'dataAccessLog',
            'name' => 'Data Access Log', 'category' => 'audit',
            'description' => 'Who accessed what data and when',
            'audience' => ['admin'], 'exportable' => true, 'type' => 'table'
        ],
        'privacy_report' => [
            'class' => 'AuditReports', 'method' => 'privacyReport',
            'name' => 'GDPR/NDPR Privacy Report', 'category' => 'audit',
            'description' => 'Data subject requests, consent status, privacy compliance',
            'audience' => ['admin', 'legal'], 'exportable' => true, 'type' => 'table'
        ],
        'training_records_export' => [
            'class' => 'AuditReports', 'method' => 'trainingRecordsExport',
            'name' => 'Training Records Export', 'category' => 'audit',
            'description' => 'Complete training record for a specific user (legal/audit)',
            'audience' => ['admin', 'hr', 'legal'], 'exportable' => true, 'type' => 'detail',
            'requires' => ['learner_id']
        ],
        'security_audit' => [
            'class' => 'AuditReports', 'method' => 'securityAudit',
            'name' => 'Security Audit Report', 'category' => 'audit',
            'description' => 'Failed logins, security alerts, password activity',
            'audience' => ['admin'], 'exportable' => true, 'type' => 'summary'
        ],
        'data_retention' => [
            'class' => 'AuditReports', 'method' => 'dataRetention',
            'name' => 'Data Retention Report', 'category' => 'audit',
            'description' => 'Data age analysis for retention policy compliance',
            'audience' => ['admin', 'legal'], 'exportable' => false, 'type' => 'table'
        ],
    ];

    // Cache for class instances
    private array $instances = [];

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function setTenant(int $tenant_id): self
    {
        $this->tenant_id = $tenant_id;
        return $this;
    }

    public function setUser(int $user_id): self
    {
        $this->user_id = $user_id;
        return $this;
    }

    // ==========================================
    // CATALOG
    // ==========================================

    public function getCatalog(?string $category = null, ?string $audience = null): array
    {
        $reports = [];
        foreach ($this->reportCatalog as $key => $config) {
            if ($category && $config['category'] !== $category) continue;
            if ($audience && !in_array($audience, $config['audience'])) continue;

            $reports[] = [
                'report_key' => $key,
                'name' => $config['name'],
                'category' => $config['category'],
                'description' => $config['description'],
                'audience' => $config['audience'],
                'exportable' => $config['exportable'],
                'type' => $config['type'],
                'requires' => $config['requires'] ?? []
            ];
        }
        return $reports;
    }

    public function getCategories(): array
    {
        return [
            ['key' => 'enrollment', 'name' => 'Enrollment & Participation', 'icon' => 'tuiIconUserPlus', 'report_count' => $this->countByCategory('enrollment')],
            ['key' => 'completion', 'name' => 'Completion & Progress', 'icon' => 'tuiIconCheckCircle', 'report_count' => $this->countByCategory('completion')],
            ['key' => 'compliance', 'name' => 'Compliance & Mandatory', 'icon' => 'tuiIconShield', 'report_count' => $this->countByCategory('compliance')],
            ['key' => 'assessment', 'name' => 'Assessment & Performance', 'icon' => 'tuiIconClipboard', 'report_count' => $this->countByCategory('assessment')],
            ['key' => 'user', 'name' => 'User & Department', 'icon' => 'tuiIconUsers', 'report_count' => $this->countByCategory('user')],
            ['key' => 'course', 'name' => 'Course & Content', 'icon' => 'tuiIconBook', 'report_count' => $this->countByCategory('course')],
            ['key' => 'time', 'name' => 'Time & Engagement', 'icon' => 'tuiIconClock', 'report_count' => $this->countByCategory('time')],
            ['key' => 'gamification', 'name' => 'Gamification & Achievements', 'icon' => 'tuiIconAward', 'report_count' => $this->countByCategory('gamification')],
            ['key' => 'certificate', 'name' => 'Certificate Reports', 'icon' => 'tuiIconFileText', 'report_count' => $this->countByCategory('certificate')],
            ['key' => 'system', 'name' => 'System & Administration', 'icon' => 'tuiIconSettings', 'report_count' => $this->countByCategory('system')],
            ['key' => 'executive', 'name' => 'Executive & Summary', 'icon' => 'tuiIconTrendingUp', 'report_count' => $this->countByCategory('executive')],
            ['key' => 'audit', 'name' => 'Audit & Data Privacy', 'icon' => 'tuiIconEye', 'report_count' => $this->countByCategory('audit')],
        ];
    }

    private function countByCategory(string $cat): int
    {
        return count(array_filter($this->reportCatalog, fn($c) => $c['category'] === $cat));
    }

    // ==========================================
    // EXECUTION
    // ==========================================

    /**
     * Run a report by key with all params
     */
    public function runReport(string $reportKey, array $params = []): array
    {
        if (!isset($this->reportCatalog[$reportKey])) {
            return ['error' => 'Report not found', 'report_key' => $reportKey];
        }

        $config = $this->reportCatalog[$reportKey];
        $instance = $this->getReportInstance($config['class']);

        // Apply common settings
        if (isset($params['date_from']) || isset($params['date_to'])) {
            $instance->setDateRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        }
        if (isset($params['page']) || isset($params['per_page'])) {
            $instance->setPagination((int) ($params['page'] ?? 1), (int) ($params['per_page'] ?? 25));
        }
        if (isset($params['sort_by'])) {
            $instance->setSorting($params['sort_by'], $params['sort_dir'] ?? 'DESC');
        }

        // Apply scope (course, department, team, branch, learner)
        $instance->setScope([
            'course_id' => $params['course_id'] ?? null,
            'department_id' => $params['department_id'] ?? null,
            'team_id' => $params['team_id'] ?? null,
            'branch_id' => $params['branch_id'] ?? null,
            'learner_id' => $params['learner_id'] ?? null,
        ]);

        // Apply extra filters
        $instance->setFilters($params['filters'] ?? $params);

        // Execute
        $method = $config['method'];
        $result = $instance->$method();

        return [
            'report_key' => $reportKey,
            'report_name' => $config['name'],
            'category' => $config['category'],
            'type' => $config['type'],
            'exportable' => $config['exportable'],
            'result' => $result
        ];
    }

    /**
     * Export a report as CSV
     */
    public function exportReport(string $reportKey, array $params = []): ?string
    {
        $config = $this->reportCatalog[$reportKey] ?? null;
        if (!$config || !$config['exportable']) return null;

        // Run report with high per_page for export
        $params['per_page'] = 10000;
        $params['page'] = 1;
        $report = $this->runReport($reportKey, $params);

        $instance = $this->getReportInstance($config['class']);
        $columns = $instance->getExportColumns($reportKey);

        if (empty($columns)) return null;

        $data = $report['result']['data'] ?? $report['result'] ?? [];
        if (isset($data['data'])) $data = $data['data']; // unwrap paginated

        return $instance->exportCsv($data, $columns);
    }

    // ==========================================
    // LOOKUPS (for filter dropdowns)
    // ==========================================

    public function getCourses(): array
    {
        $sql = "SELECT course_id as id, title FROM courses WHERE tenant_id = ? AND is_active = 1 ORDER BY title ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $stmt->close();
        return $rows;
    }

    public function getDepartments(): array
    {
        $sql = "SELECT department_id as id, department_name as name FROM departments WHERE tenant_id = ? AND is_active = 1 ORDER BY department_name ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $stmt->close();
        return $rows;
    }

    public function getTeams(): array
    {
        $sql = "SELECT team_id as id, team_name as name FROM teams WHERE tenant_id = ? AND is_active = 1 ORDER BY team_name ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $stmt->close();
        return $rows;
    }

    public function getBranches(): array
    {
        $sql = "SELECT branch_id as id, branch_name as name FROM branches WHERE tenant_id = ? AND is_active = 1 ORDER BY branch_name ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $stmt->close();
        return $rows;
    }

    public function getLearners(string $search = ''): array
    {
        $sql = "SELECT id, CONCAT(first_name, ' ', last_name) as name, email
            FROM users WHERE tenant_id = ? AND is_active = 1";
        $types = 'i';
        $params = [$this->tenant_id];

        if ($search) {
            $s = '%' . $search . '%';
            $sql .= " AND (CONCAT(first_name, ' ', last_name) LIKE ? OR email LIKE ?)";
            $types .= 'ss';
            $params[] = $s;
            $params[] = $s;
        }
        $sql .= " ORDER BY first_name ASC LIMIT 50";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $stmt->close();
        return $rows;
    }

    // ==========================================
    // INTERNAL
    // ==========================================

    private function getReportInstance(string $className): BaseReport
    {
        if (!isset($this->instances[$className])) {
            $instance = new $className($this->conn);
            $instance->setTenant($this->tenant_id);
            $instance->setUser($this->user_id);
            $this->instances[$className] = $instance;
        }
        return $this->instances[$className];
    }
}
