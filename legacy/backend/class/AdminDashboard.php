<?php
/**
 * AdminDashboard Class
 * Comprehensive dashboard data for LMS administrators
 * Returns metrics, trends, and insights for the admin dashboard
 */

class AdminDashboard
{
    private $conn;
    private $tenant_id;
    private $date_from;
    private $date_to;

    public function __construct($db)
    {
        $this->conn = $db;
    }

    /**
     * Set context for dashboard queries
     */
    public function setContext(int $tenant_id, ?string $date_from = null, ?string $date_to = null): void
    {
        $this->tenant_id = $tenant_id;
        $this->date_to = $date_to ?? date('Y-m-d');
        $this->date_from = $date_from ?? date('Y-m-d', strtotime('-30 days'));
    }

    /**
     * Get all dashboard data
     */
    public function getDashboardData(): array
    {
        return [
            'overview' => $this->getOverviewStats(),
            'trends' => $this->getTrends(),
            'courses' => $this->getCourseStats(),
            'learners' => $this->getLearnerStats(),
            'compliance' => $this->getComplianceStats(),
            'assessments' => $this->getAssessmentStats(),
            'recent_activity' => $this->getRecentActivity(),
            'top_courses' => $this->getTopCourses(),
            'at_risk_learners' => $this->getAtRiskLearners(),
            'recent_completions' => $this->getRecentCompletions(),
            'upcoming_deadlines' => $this->getUpcomingDeadlines(),
            'department_stats' => $this->getDepartmentStats(),
            'engagement' => $this->getEngagementStats()
        ];
    }

    /**
     * Get overview statistics
     */
    private function getOverviewStats(): array
    {
        // Total users
        $query = "SELECT COUNT(*) as total FROM users WHERE tenant_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $totalUsers = (int) $stmt->get_result()->fetch_assoc()['total'];

        // Active learners (logged in within last 30 days)
        $query = "SELECT COUNT(*) as total FROM users 
                  WHERE tenant_id = ? AND is_active = 1 
                  AND last_login_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $activeLearners = (int) $stmt->get_result()->fetch_assoc()['total'];

        // Total courses
        $query = "SELECT COUNT(*) as total FROM courses WHERE tenant_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $totalCourses = (int) $stmt->get_result()->fetch_assoc()['total'];

        // Published courses
        $query = "SELECT COUNT(*) as total FROM courses WHERE tenant_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $publishedCourses = (int) $stmt->get_result()->fetch_assoc()['total'];

        // Total enrollments
        $query = "SELECT COUNT(*) as total FROM course_enrollments WHERE tenant_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $totalEnrollments = (int) $stmt->get_result()->fetch_assoc()['total'];

        // Enrollments this month
        $query = "SELECT COUNT(*) as total FROM course_enrollments 
                  WHERE tenant_id = ? AND is_active = 1 
                  AND MONTH(enrolled_at) = MONTH(CURRENT_DATE()) 
                  AND YEAR(enrolled_at) = YEAR(CURRENT_DATE())";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $enrollmentsThisMonth = (int) $stmt->get_result()->fetch_assoc()['total'];

        // Completions
        $query = "SELECT COUNT(*) as total FROM course_enrollments 
                  WHERE tenant_id = ? AND is_active = 1 AND status = 'completed'";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $totalCompletions = (int) $stmt->get_result()->fetch_assoc()['total'];

        // Completions this month
        $query = "SELECT COUNT(*) as total FROM course_enrollments 
                  WHERE tenant_id = ? AND is_active = 1 AND status = 'completed'
                  AND MONTH(completed_at) = MONTH(CURRENT_DATE()) 
                  AND YEAR(completed_at) = YEAR(CURRENT_DATE())";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $completionsThisMonth = (int) $stmt->get_result()->fetch_assoc()['total'];

        // Average completion rate
        $completionRate = $totalEnrollments > 0 ? round(($totalCompletions / $totalEnrollments) * 100, 1) : 0;

        // Certificates issued
        $query = "SELECT COUNT(*) as total FROM certificates WHERE tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $totalCertificates = (int) $stmt->get_result()->fetch_assoc()['total'];

        // Average score
        $query = "SELECT AVG(final_score) as avg_score FROM course_enrollments 
                  WHERE tenant_id = ? AND is_active = 1 AND final_score IS NOT NULL";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $avgScore = round((float) $stmt->get_result()->fetch_assoc()['avg_score'], 1);

        // Total learning hours
        $query = "SELECT SUM(time_spent_minutes) as total FROM course_enrollments 
                  WHERE tenant_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $totalMinutes = (int) $stmt->get_result()->fetch_assoc()['total'];
        $totalLearningHours = round($totalMinutes / 60, 0);

        // Previous month comparison for growth rates
        $lastMonthEnrollments = $this->getLastMonthEnrollments();
        $enrollmentGrowth = $lastMonthEnrollments > 0
            ? round((($enrollmentsThisMonth - $lastMonthEnrollments) / $lastMonthEnrollments) * 100, 1)
            : 0;

        $lastMonthCompletions = $this->getLastMonthCompletions();
        $completionGrowth = $lastMonthCompletions > 0
            ? round((($completionsThisMonth - $lastMonthCompletions) / $lastMonthCompletions) * 100, 1)
            : 0;

        return [
            'total_users' => $totalUsers,
            'active_learners' => $activeLearners,
            'total_courses' => $totalCourses,
            'published_courses' => $publishedCourses,
            'total_enrollments' => $totalEnrollments,
            'enrollments_this_month' => $enrollmentsThisMonth,
            'enrollment_growth' => $enrollmentGrowth,
            'total_completions' => $totalCompletions,
            'completions_this_month' => $completionsThisMonth,
            'completion_growth' => $completionGrowth,
            'completion_rate' => $completionRate,
            'total_certificates' => $totalCertificates,
            'average_score' => $avgScore,
            'total_learning_hours' => $totalLearningHours,
            'in_progress_count' => $this->getInProgressCount(),
            'not_started_count' => $this->getNotStartedCount()
        ];
    }

    private function getLastMonthEnrollments(): int
    {
        $query = "SELECT COUNT(*) as total FROM course_enrollments 
                  WHERE tenant_id = ? AND is_active = 1 
                  AND MONTH(enrolled_at) = MONTH(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH))
                  AND YEAR(enrolled_at) = YEAR(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH))";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        return (int) $stmt->get_result()->fetch_assoc()['total'];
    }

    private function getLastMonthCompletions(): int
    {
        $query = "SELECT COUNT(*) as total FROM course_enrollments 
                  WHERE tenant_id = ? AND is_active = 1 AND status = 'completed'
                  AND MONTH(completed_at) = MONTH(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH))
                  AND YEAR(completed_at) = YEAR(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH))";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        return (int) $stmt->get_result()->fetch_assoc()['total'];
    }

    private function getInProgressCount(): int
    {
        $query = "SELECT COUNT(*) as total FROM course_enrollments 
                  WHERE tenant_id = ? AND is_active = 1 AND status = 'in_progress'";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        return (int) $stmt->get_result()->fetch_assoc()['total'];
    }

    private function getNotStartedCount(): int
    {
        $query = "SELECT COUNT(*) as total FROM course_enrollments 
                  WHERE tenant_id = ? AND is_active = 1 AND status = 'not_started'";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        return (int) $stmt->get_result()->fetch_assoc()['total'];
    }

    /**
     * Get trends data for charts
     */
    private function getTrends(): array
    {
        $enrollmentTrend = [];
        $completionTrend = [];

        // Last 12 months trend
        for ($i = 11; $i >= 0; $i--) {
            $date = date('Y-m', strtotime("-$i months"));
            $month = date('M', strtotime("-$i months"));
            $year = date('Y', strtotime("-$i months"));
            $monthNum = date('n', strtotime("-$i months"));
            $yearNum = date('Y', strtotime("-$i months"));

            // Enrollments
            $query = "SELECT COUNT(*) as total FROM course_enrollments 
                      WHERE tenant_id = ? AND is_active = 1 
                      AND MONTH(enrolled_at) = ? AND YEAR(enrolled_at) = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("iii", $this->tenant_id, $monthNum, $yearNum);
            $stmt->execute();
            $enrollments = (int) $stmt->get_result()->fetch_assoc()['total'];

            // Completions
            $query = "SELECT COUNT(*) as total FROM course_enrollments 
                      WHERE tenant_id = ? AND is_active = 1 AND status = 'completed'
                      AND MONTH(completed_at) = ? AND YEAR(completed_at) = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("iii", $this->tenant_id, $monthNum, $yearNum);
            $stmt->execute();
            $completions = (int) $stmt->get_result()->fetch_assoc()['total'];

            $enrollmentTrend[] = [
                'month' => $month,
                'year' => $year,
                'label' => "$month $year",
                'value' => $enrollments
            ];

            $completionTrend[] = [
                'month' => $month,
                'year' => $year,
                'label' => "$month $year",
                'value' => $completions
            ];
        }

        // Daily trend for last 30 days
        $dailyTrend = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $label = date('M d', strtotime("-$i days"));

            $query = "SELECT 
                        COUNT(CASE WHEN DATE(enrolled_at) = ? THEN 1 END) as enrollments,
                        COUNT(CASE WHEN DATE(completed_at) = ? AND status = 'completed' THEN 1 END) as completions
                      FROM course_enrollments 
                      WHERE tenant_id = ? AND is_active = 1";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("ssi", $date, $date, $this->tenant_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();

            $dailyTrend[] = [
                'date' => $date,
                'label' => $label,
                'enrollments' => (int) $result['enrollments'],
                'completions' => (int) $result['completions']
            ];
        }

        return [
            'enrollment_trend' => $enrollmentTrend,
            'completion_trend' => $completionTrend,
            'daily_trend' => $dailyTrend
        ];
    }

    /**
     * Get course statistics
     */
    private function getCourseStats(): array
    {
        // By category
        $query = "SELECT 
                    COALESCE(cat.name, 'Uncategorized') as category,
                    COUNT(c.course_id) as course_count,
                    SUM((SELECT COUNT(*) FROM course_enrollments ce WHERE ce.course_id = c.course_id AND ce.is_active = 1)) as enrollments
                  FROM courses c
                  LEFT JOIN categories cat ON c.category_id = cat.category_id
                  WHERE c.tenant_id = ? AND c.is_active = 1
                  GROUP BY cat.category_id, cat.name
                  ORDER BY course_count DESC
                  LIMIT 10";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $byCategory = [];
        while ($row = $result->fetch_assoc()) {
            $byCategory[] = [
                'category' => $row['category'],
                'course_count' => (int) $row['course_count'],
                'enrollments' => (int) $row['enrollments']
            ];
        }

        // By level
        $query = "SELECT 
                    COALESCE(level, 'Not Set') as level,
                    COUNT(*) as count
                  FROM courses 
                  WHERE tenant_id = ? AND is_active = 1
                  GROUP BY level";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $byLevel = [];
        while ($row = $result->fetch_assoc()) {
            $byLevel[] = [
                'level' => ucfirst($row['level']),
                'count' => (int) $row['count']
            ];
        }

        return [
            'by_category' => $byCategory,
            'by_level' => $byLevel
        ];
    }

    /**
     * Get learner statistics
     */
    private function getLearnerStats(): array
    {
        // By status
        $query = "SELECT 
                    status,
                    COUNT(*) as count
                  FROM course_enrollments 
                  WHERE tenant_id = ? AND is_active = 1
                  GROUP BY status";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $byStatus = [];
        while ($row = $result->fetch_assoc()) {
            $byStatus[] = [
                'status' => $row['status'],
                'count' => (int) $row['count']
            ];
        }

        // New users this month
        $query = "SELECT COUNT(*) as total FROM users 
                  WHERE tenant_id = ? AND is_active = 1 
                  AND MONTH(created) = MONTH(CURRENT_DATE()) 
                  AND YEAR(created) = YEAR(CURRENT_DATE())";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $newUsersThisMonth = (int) $stmt->get_result()->fetch_assoc()['total'];

        return [
            'by_status' => $byStatus,
            'new_users_this_month' => $newUsersThisMonth
        ];
    }

    /**
     * Get compliance statistics
     */
    private function getComplianceStats(): array
    {
        // This assumes you have a way to mark courses as mandatory/compliance
        // Adjust based on your actual schema

        $query = "SELECT 
                    COUNT(DISTINCT ce.user_id) as total_assigned,
                    COUNT(DISTINCT CASE WHEN ce.status = 'completed' THEN ce.user_id END) as compliant
                  FROM course_enrollments ce
                  JOIN courses c ON ce.course_id = c.course_id
                  WHERE ce.tenant_id = ? AND ce.is_active = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        $totalAssigned = (int) $result['total_assigned'];
        $compliant = (int) $result['compliant'];
        $complianceRate = $totalAssigned > 0 ? round(($compliant / $totalAssigned) * 100, 1) : 0;

        // Overdue count
        $query = "SELECT COUNT(*) as total FROM course_enrollments 
                  WHERE tenant_id = ? AND is_active = 1 
                  AND status != 'completed'
                  AND due_date IS NOT NULL AND due_date < CURRENT_DATE()";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $overdueCount = (int) $stmt->get_result()->fetch_assoc()['total'];

        // Expiring certificates (next 30 days)
        $query = "SELECT COUNT(*) as total FROM certificates 
                  WHERE tenant_id = ? 
                  AND expiry_date IS NOT NULL 
                  AND expiry_date BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $expiringCertificates = (int) $stmt->get_result()->fetch_assoc()['total'];

        return [
            'total_assigned' => $totalAssigned,
            'compliant' => $compliant,
            'non_compliant' => $totalAssigned - $compliant,
            'compliance_rate' => $complianceRate,
            'overdue_count' => $overdueCount,
            'expiring_certificates' => $expiringCertificates
        ];
    }

    /**
     * Get assessment statistics
     */
    private function getAssessmentStats(): array
    {
        $query = "SELECT 
                    COUNT(*) as total_attempts,
                    AVG(score) as avg_score,
                    SUM(CASE WHEN passed = 1 THEN 1 ELSE 0 END) as passed,
                    SUM(CASE WHEN passed = 0 THEN 1 ELSE 0 END) as failed
                  FROM assessment_attempts aa
                  JOIN assessments a ON aa.assessment_id = a.assessment_id
                  WHERE a.tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        $totalAttempts = (int) $result['total_attempts'];
        $passed = (int) $result['passed'];
        $passRate = $totalAttempts > 0 ? round(($passed / $totalAttempts) * 100, 1) : 0;

        return [
            'total_attempts' => $totalAttempts,
            'average_score' => round((float) $result['avg_score'], 1),
            'passed' => $passed,
            'failed' => (int) $result['failed'],
            'pass_rate' => $passRate
        ];
    }

    /**
     * Get recent activity
     *
     */
    private function getRecentActivity(): array
    {
        $activities = [];

        // Recent enrollments
        $query = "SELECT 
                    'enrollment' as type,
                    CONCAT(u.first_name, ' ', u.last_name) as user_name,
                    c.title as course_title,
                    ce.enrolled_at as activity_date
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  JOIN courses c ON ce.course_id = c.course_id
                  WHERE ce.tenant_id = ? AND ce.is_active = 1
                  ORDER BY ce.enrolled_at DESC
                  LIMIT 5";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $activities[] = [
                'type' => 'enrollment',
                'icon' => 'user-plus',
                'message' => "{$row['user_name']} enrolled in {$row['course_title']}",
                'date' => $row['activity_date'],
                'color' => 'primary'
            ];
        }

        // Recent completions
        $query = "SELECT 
                    'completion' as type,
                    CONCAT(u.first_name, ' ', u.last_name) as user_name,
                    c.title as course_title,
                    ce.completed_at as activity_date
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  JOIN courses c ON ce.course_id = c.course_id
                  WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.status = 'completed'
                  ORDER BY ce.completed_at DESC
                  LIMIT 5";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $activities[] = [
                'type' => 'completion',
                'icon' => 'check-circle',
                'message' => "{$row['user_name']} completed {$row['course_title']}",
                'date' => $row['activity_date'],
                'color' => 'success'
            ];
        }

        // Sort by date and limit
        usort($activities, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        return array_slice($activities, 0, 10);
    }

    /**
     * Get top courses by enrollment
     */
    private function getTopCourses(): array
    {
        $query = "SELECT 
                    c.course_id,
                    c.title,
                    c.level,
                    COUNT(ce.enrollment_id) as enrollments,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completions,
                    AVG(ce.final_score) as avg_score,
                    AVG(cr.rating) as avg_rating
                  FROM courses c
                  LEFT JOIN course_enrollments ce ON c.course_id = ce.course_id AND ce.is_active = 1
                  LEFT JOIN course_reviews cr ON c.course_id = cr.course_id
                  WHERE c.tenant_id = ? AND c.is_active = 1
                  GROUP BY c.course_id
                  ORDER BY enrollments DESC
                  LIMIT 5";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $courses = [];
        while ($row = $result->fetch_assoc()) {
            $enrollments = (int) $row['enrollments'];
            $completions = (int) $row['completions'];
            $courses[] = [
                'course_id' => (int) $row['course_id'],
                'title' => $row['title'],
                'level' => $row['level'],
                'enrollments' => $enrollments,
                'completions' => $completions,
                'completion_rate' => $enrollments > 0 ? round(($completions / $enrollments) * 100, 1) : 0,
                'avg_score' => round((float) $row['avg_score'], 1),
                'avg_rating' => round((float) $row['avg_rating'], 1)
            ];
        }

        return $courses;
    }

    /**
     * Get at-risk learners
     */
    private function getAtRiskLearners(): array
    {
        $query = "SELECT 
                    u.id as user_id,
                    CONCAT(u.first_name, ' ', u.last_name) as full_name,
                    u.email,
                    u.avatar,
                    d.department_name,
                    COUNT(ce.enrollment_id) as courses_assigned,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as courses_completed,
                    AVG(ce.progress_percentage) as avg_progress,
                    MAX(ce.last_activity_at) as last_activity,
                    DATEDIFF(NOW(), MAX(ce.last_activity_at)) as days_inactive
                  FROM users u
                  JOIN course_enrollments ce ON u.id = ce.user_id AND ce.is_active = 1
                  LEFT JOIN departments d ON u.department = d.department_id
                  WHERE u.tenant_id = ? AND u.is_active = 1
                  AND ce.status IN ('not_started', 'in_progress')
                  GROUP BY u.id
                  HAVING days_inactive > 7 OR avg_progress < 25
                  ORDER BY days_inactive DESC, avg_progress ASC
                  LIMIT 10";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $learners = [];
        while ($row = $result->fetch_assoc()) {
            $daysInactive = (int) $row['days_inactive'];
            $avgProgress = round((float) $row['avg_progress'], 0);

            $riskLevel = 'low';
            if ($daysInactive > 14 || $avgProgress < 15) {
                $riskLevel = 'high';
            } elseif ($daysInactive > 7 || $avgProgress < 30) {
                $riskLevel = 'medium';
            }

            $learners[] = [
                'user_id' => (int) $row['user_id'],
                'full_name' => $row['full_name'],
                'email' => $row['email'],
                'avatar_url' => $row['avatar'],
                'department' => $row['department_name'] ?? 'N/A',
                'courses_assigned' => (int) $row['courses_assigned'],
                'courses_completed' => (int) $row['courses_completed'],
                'avg_progress' => $avgProgress,
                'last_activity' => $row['last_activity'],
                'days_inactive' => $daysInactive,
                'risk_level' => $riskLevel
            ];
        }

        return $learners;
    }

    /**
     * Get recent completions
     */
    private function getRecentCompletions(): array
    {
        $query = "SELECT 
                    u.id as user_id,
                    CONCAT(u.first_name, ' ', u.last_name) as full_name,
                    u.avatar,
                    c.title as course_title,
                    ce.completed_at,
                    ce.final_score,
                    cert.certificate_id
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  JOIN courses c ON ce.course_id = c.course_id
                  LEFT JOIN certificates cert ON cert.user_id = u.id AND cert.course_id = c.course_id
                  WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.status = 'completed'
                  ORDER BY ce.completed_at DESC
                  LIMIT 10";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $completions = [];
        while ($row = $result->fetch_assoc()) {
            $completions[] = [
                'user_id' => (int) $row['user_id'],
                'full_name' => $row['full_name'],
                'avatar_url' => $row['avatar'],
                'course_title' => $row['course_title'],
                'completed_at' => $row['completed_at'],
                'final_score' => (int) $row['final_score'],
                'has_certificate' => !empty($row['certificate_id'])
            ];
        }

        return $completions;
    }

    /**
     * Get upcoming deadlines
     */
    private function getUpcomingDeadlines(): array
    {
        $query = "SELECT 
                    u.id as user_id,
                    CONCAT(u.first_name, ' ', u.last_name) as full_name,
                    c.title as course_title,
                    ce.due_date,
                    DATEDIFF(ce.due_date, CURRENT_DATE()) as days_remaining,
                    ce.progress_percentage
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  JOIN courses c ON ce.course_id = c.course_id
                  WHERE ce.tenant_id = ? AND ce.is_active = 1 
                  AND ce.status != 'completed'
                  AND ce.due_date IS NOT NULL
                  AND ce.due_date BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL 14 DAY)
                  ORDER BY ce.due_date ASC
                  LIMIT 10";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $deadlines = [];
        while ($row = $result->fetch_assoc()) {
            $daysRemaining = (int) $row['days_remaining'];
            $urgency = 'normal';
            if ($daysRemaining <= 3) {
                $urgency = 'critical';
            } elseif ($daysRemaining <= 7) {
                $urgency = 'warning';
            }

            $deadlines[] = [
                'user_id' => (int) $row['user_id'],
                'full_name' => $row['full_name'],
                'course_title' => $row['course_title'],
                'due_date' => $row['due_date'],
                'days_remaining' => $daysRemaining,
                'progress' => (int) $row['progress_percentage'],
                'urgency' => $urgency
            ];
        }

        return $deadlines;
    }

    /**
     * Get department statistics
     */
    private function getDepartmentStats(): array
    {
        $query = "SELECT 
                    d.department_name,
                    COUNT(DISTINCT u.id) as total_users,
                    COUNT(ce.enrollment_id) as total_enrollments,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completions,
                    AVG(ce.progress_percentage) as avg_progress
                  FROM departments d
                  LEFT JOIN users u ON d.department_id = u.department AND u.tenant_id = ? AND u.is_active = 1
                  LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.is_active = 1
                  WHERE d.tenant_id = ?
                  GROUP BY d.department_id, d.department_name
                  ORDER BY total_enrollments DESC
                  LIMIT 10";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->tenant_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $departments = [];
        while ($row = $result->fetch_assoc()) {
            $enrollments = (int) $row['total_enrollments'];
            $completions = (int) $row['completions'];
            $departments[] = [
                'department' => $row['department_name'],
                'total_users' => (int) $row['total_users'],
                'total_enrollments' => $enrollments,
                'completions' => $completions,
                'completion_rate' => $enrollments > 0 ? round(($completions / $enrollments) * 100, 1) : 0,
                'avg_progress' => round((float) $row['avg_progress'], 1)
            ];
        }

        return $departments;
    }

    /**
     * Get engagement statistics
     */
    private function getEngagementStats(): array
    {
        // Peak hours
        $query = "SELECT 
                    HOUR(started_at) as hour,
                    COUNT(*) as sessions
                  FROM learning_sessions
                  WHERE tenant_id = ?
                  GROUP BY HOUR(started_at)
                  ORDER BY sessions DESC
                  LIMIT 5";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $peakHours = [];
        while ($row = $result->fetch_assoc()) {
            $peakHours[] = [
                'hour' => (int) $row['hour'],
                'sessions' => (int) $row['sessions']
            ];
        }

        // Peak days
        $query = "SELECT 
                    DAYNAME(started_at) as day,
                    COUNT(*) as sessions
                  FROM learning_sessions
                  WHERE tenant_id = ?
                  GROUP BY DAYNAME(started_at), DAYOFWEEK(started_at)
                  ORDER BY sessions DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $peakDays = [];
        while ($row = $result->fetch_assoc()) {
            $peakDays[] = [
                'day' => $row['day'],
                'sessions' => (int) $row['sessions']
            ];
        }

        return [
            'peak_hours' => $peakHours,
            'peak_days' => $peakDays
        ];
    }
}