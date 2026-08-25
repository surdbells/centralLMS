<?php
/**
 * LearnerDashboard Class
 * Personalized dashboard data for LMS learners
 * Returns metrics, progress, achievements, and insights for the learner
 */

class LearnerDashboard
{
    private $conn;
    private $tenant_id;
    private $user_id;

    public function __construct($db)
    {
        $this->conn = $db;
    }

    /**
     * Set context for dashboard queries
     */
    public function setContext(int $tenant_id, int $user_id): void
    {
        $this->tenant_id = $tenant_id;
        $this->user_id = $user_id;
    }

    /**
     * Get all dashboard data for learner
     */
    public function getDashboardData(): array
    {
        return [
            'user' => $this->getUserProfile(),
            'overview' => $this->getOverviewStats(),
            'learning_streak' => $this->getLearningStreak(),
            'courses' => $this->getMyCourses(),
            'progress' => $this->getProgressStats(),
            'achievements' => $this->getAchievements(),
            'certificates' => $this->getMyCertificates(),
            'assessments' => $this->getMyAssessments(),
            'compliance' => $this->getComplianceStatus(),
            'leaderboard' => $this->getLeaderboardPosition(),
            'recent_activity' => $this->getRecentActivity(),
            'upcoming_deadlines' => $this->getUpcomingDeadlines(),
            'recommended_courses' => $this->getRecommendedCourses(),
            'weekly_progress' => $this->getWeeklyProgress(),
            'skill_progress' => $this->getSkillProgress()
        ];
    }

    /**
     * Get user profile information
     */
    private function getUserProfile(): array
    {
        $query = "SELECT 
                    u.id, u.first_name, u.last_name, u.email, u.avatar,
                    u.job_title, u.created as joined_date,
                    d.department_name, j.title_name
                  FROM users u
                  LEFT JOIN departments d ON u.department = d.department_id
                  LEFT JOIN job_titles j ON u.job_title = j.job_title_id
                  WHERE u.id = ? AND u.tenant_id = ?";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        return [
            'user_id' => (int) $user['id'],
            'full_name' => trim($user['first_name'] . ' ' . $user['last_name']),
            'first_name' => $user['first_name'],
            'email' => $user['email'],
            'avatar_url' => $user['avatar'],
            'job_title' => $user['title_name'],
            'department' => $user['department_name'],
            'joined_date' => $user['joined_date']
        ];
    }

    /**
     * Get overview statistics
     */
    private function getOverviewStats(): array
    {
        // Courses enrolled
        $query = "SELECT COUNT(*) as total FROM course_enrollments 
                  WHERE user_id = ? AND tenant_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $coursesEnrolled = (int) $stmt->get_result()->fetch_assoc()['total'];

        // Courses completed
        $query = "SELECT COUNT(*) as total FROM course_enrollments 
                  WHERE user_id = ? AND tenant_id = ? AND is_active = 1 AND status = 'completed'";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $coursesCompleted = (int) $stmt->get_result()->fetch_assoc()['total'];

        // Courses in progress
        $query = "SELECT COUNT(*) as total FROM course_enrollments 
                  WHERE user_id = ? AND tenant_id = ? AND is_active = 1 AND status = 'in_progress'";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $coursesInProgress = (int) $stmt->get_result()->fetch_assoc()['total'];

        // Not started
        $query = "SELECT COUNT(*) as total FROM course_enrollments 
                  WHERE user_id = ? AND tenant_id = ? AND is_active = 1 AND status = 'not_started'";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $coursesNotStarted = (int) $stmt->get_result()->fetch_assoc()['total'];

        // Total learning hours
        $query = "SELECT SUM(time_spent_minutes) as total FROM course_enrollments 
                  WHERE user_id = ? AND tenant_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $totalMinutes = (int) $stmt->get_result()->fetch_assoc()['total'];
        $totalHours = round($totalMinutes / 60, 1);

        // This week's learning hours
        $query = "SELECT SUM(time_spent_minutes) as total FROM learning_sessions 
                  WHERE user_id = ? AND tenant_id = ? 
                  AND started_at >= DATE_SUB(CURRENT_DATE(), INTERVAL WEEKDAY(CURRENT_DATE()) DAY)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $weekMinutes = (int) $stmt->get_result()->fetch_assoc()['total'];
        $weekHours = round($weekMinutes / 60, 1);

        // Average score
        $query = "SELECT AVG(final_score) as avg_score FROM course_enrollments 
                  WHERE user_id = ? AND tenant_id = ? AND is_active = 1 AND final_score IS NOT NULL";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $avgScore = round((float) $stmt->get_result()->fetch_assoc()['avg_score'], 1);

        // Total certificates
        $query = "SELECT COUNT(*) as total FROM certificates 
                  WHERE user_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $totalCertificates = (int) $stmt->get_result()->fetch_assoc()['total'];

        // Total badges
        $query = "SELECT COUNT(*) as total FROM user_badges 
                  WHERE user_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $totalBadges = (int) $stmt->get_result()->fetch_assoc()['total'];

        // Points (sum all points entries for user)
        $query = "SELECT COALESCE(SUM(points), 0) as total_points FROM user_points 
                  WHERE user_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $totalPoints = (int) ($result['total_points'] ?? 0);

        // Overall progress percentage
        $overallProgress = $coursesEnrolled > 0
            ? round(($coursesCompleted / $coursesEnrolled) * 100, 0)
            : 0;

        return [
            'courses_enrolled' => $coursesEnrolled,
            'courses_completed' => $coursesCompleted,
            'courses_in_progress' => $coursesInProgress,
            'courses_not_started' => $coursesNotStarted,
            'total_learning_hours' => $totalHours,
            'hours_this_week' => $weekHours,
            'average_score' => $avgScore,
            'total_certificates' => $totalCertificates,
            'total_badges' => $totalBadges,
            'total_points' => $totalPoints,
            'overall_progress' => $overallProgress
        ];
    }

    /**
     * Get learning streak information
     */
    private function getLearningStreak(): array
    {
        // Get current streak
        $query = "SELECT 
                    current_streak,
                    longest_streak,
                    last_activity_date
                  FROM user_streaks 
                  WHERE user_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        $currentStreak = (int) ($result['current_streak'] ?? 0);
        $longestStreak = (int) ($result['longest_streak'] ?? 0);
        $lastActivity = $result['last_activity_date'] ?? null;

        // Check if streak is still active (activity today or yesterday)
        $isActive = false;
        if ($lastActivity) {
            $lastDate = new DateTime($lastActivity);
            $today = new DateTime();
            $diff = $today->diff($lastDate)->days;
            $isActive = $diff <= 1;
        }

        // Get activity for the last 7 days
        $weekActivity = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $dayName = date('D', strtotime("-$i days"));

            $query = "SELECT COUNT(*) as has_activity FROM learning_sessions 
                      WHERE user_id = ? AND tenant_id = ? AND DATE(started_at) = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("iis", $this->user_id, $this->tenant_id, $date);
            $stmt->execute();
            $hasActivity = (int) $stmt->get_result()->fetch_assoc()['has_activity'] > 0;

            $weekActivity[] = [
                'date' => $date,
                'day' => $dayName,
                'active' => $hasActivity
            ];
        }

        return [
            'current_streak' => $currentStreak,
            'longest_streak' => $longestStreak,
            'is_active' => $isActive,
            'last_activity' => $lastActivity,
            'week_activity' => $weekActivity
        ];
    }

    /**
     * Get my courses
     */
    private function getMyCourses(): array
    {
        // In Progress courses
        $query = "SELECT 
                    c.course_id, c.title, c.level,
                    c.duration_minutes,
                    ce.progress_percentage, ce.enrolled_at, ce.last_activity_at,
                    ce.due_date,
                    DATEDIFF(ce.due_date, CURRENT_DATE()) as days_until_due,
                    cat.name as category
                  FROM course_enrollments ce
                  JOIN courses c ON ce.course_id = c.course_id
                  LEFT JOIN categories cat ON c.category_id = cat.category_id
                  WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1 
                  AND ce.status = 'in_progress' ORDER BY ce.last_activity_at DESC
                  LIMIT 4";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $inProgress = [];
        while ($row = $result->fetch_assoc()) {
            $inProgress[] = [
                'course_id' => (int) $row['course_id'],
                'title' => $row['title'],
                'level' => $row['level'],
                'category' => $row['category'],
                'duration_minutes' => (float) $row['duration_minutes'],
                'progress' => (int) $row['progress_percentage'],
                'enrolled_at' => $row['enrolled_at'],
                'last_activity' => $row['last_activity_at'],
                'due_date' => $row['due_date'],
                'days_until_due' => $row['days_until_due'] !== null ? (int) $row['days_until_due'] : null
            ];
        }
        // Not started courses
        $query = "SELECT 
                    c.course_id, c.title, c.level,
                    c.duration_minutes,
                    ce.enrolled_at, ce.due_date,
                    DATEDIFF(ce.due_date, CURRENT_DATE()) as days_until_due,
                    cat.name as category
                  FROM course_enrollments ce
                  JOIN courses c ON ce.course_id = c.course_id
                  LEFT JOIN categories cat ON c.category_id = cat.category_id
                  WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1 
                  AND ce.status = 'not_started'
                  ORDER BY ce.due_date ASC, ce.enrolled_at DESC
                  LIMIT 5";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $notStarted = [];
        while ($row = $result->fetch_assoc()) {
            $notStarted[] = [
                'course_id' => (int) $row['course_id'],
                'title' => $row['title'],
                'level' => $row['level'],
                'category' => $row['category'],
                'duration_minutes' => (float) $row['duration_minutes'],
                'enrolled_at' => $row['enrolled_at'],
                'due_date' => $row['due_date'],
                'days_until_due' => $row['days_until_due'] !== null ? (int) $row['days_until_due'] : null
            ];
        }

        // Recently completed
        $query = "SELECT 
                    c.course_id, c.title, c.level,
                    ce.completed_at, ce.final_score,
                    cert.certificate_id,
                    cat.name as category
                  FROM course_enrollments ce
                  JOIN courses c ON ce.course_id = c.course_id
                  LEFT JOIN categories cat ON c.category_id = cat.category_id
                  LEFT JOIN certificates cert ON cert.course_id = c.course_id AND cert.user_id = ce.user_id
                  WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1 
                  AND ce.status = 'completed'
                  ORDER BY ce.completed_at DESC
                  LIMIT 5";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $completed = [];
        while ($row = $result->fetch_assoc()) {
            $completed[] = [
                'course_id' => (int) $row['course_id'],
                'title' => $row['title'],
                'level' => $row['level'],
                'category' => $row['category'],
                'completed_at' => $row['completed_at'],
                'final_score' => (int) $row['final_score'],
                'has_certificate' => !empty($row['certificate_id'])
            ];
        }

        return [
            'in_progress' => $inProgress,
            'not_started' => $notStarted,
            'completed' => $completed
        ];
    }

    /**
     * Get progress statistics
     */
    private function getProgressStats(): array
    {
        // Progress breakdown
        $query = "SELECT 
                    status,
                    COUNT(*) as count,
                    AVG(progress_percentage) as avg_progress
                  FROM course_enrollments 
                  WHERE user_id = ? AND tenant_id = ? AND is_active = 1
                  GROUP BY status";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $breakdown = [];
        while ($row = $result->fetch_assoc()) {
            $breakdown[] = [
                'status' => $row['status'],
                'count' => (int) $row['count'],
                'avg_progress' => round((float) $row['avg_progress'], 0)
            ];
        }

        // Monthly completions for the year
        $monthlyCompletions = [];
        for ($i = 11; $i >= 0; $i--) {
            $monthNum = date('n', strtotime("-$i months"));
            $yearNum = date('Y', strtotime("-$i months"));
            $monthName = date('M', strtotime("-$i months"));

            $query = "SELECT COUNT(*) as total FROM course_enrollments 
                      WHERE user_id = ? AND tenant_id = ? AND is_active = 1 
                      AND status = 'completed'
                      AND MONTH(completed_at) = ? AND YEAR(completed_at) = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("iiii", $this->user_id, $this->tenant_id, $monthNum, $yearNum);
            $stmt->execute();
            $count = (int) $stmt->get_result()->fetch_assoc()['total'];

            $monthlyCompletions[] = [
                'month' => $monthName,
                'year' => $yearNum,
                'completions' => $count
            ];
        }

        return [
            'breakdown' => $breakdown,
            'monthly_completions' => $monthlyCompletions
        ];
    }

    /**
     * Get achievements (badges and points)
     */
    private function getAchievements(): array
    {
        // Recent badges
        $query = "SELECT 
                    b.badge_id, b.badge_name, b.description, b.icon,
                    ub.earned_at
                  FROM user_badges ub
                  JOIN badges b ON ub.badge_id = b.badge_id
                  WHERE ub.user_id = ? AND ub.tenant_id = ?
                  ORDER BY ub.earned_at DESC
                  LIMIT 6";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $badges = [];
        while ($row = $result->fetch_assoc()) {
            $badges[] = [
                'badge_id' => (int) $row['badge_id'],
                'name' => $row['badge_name'],
                'description' => $row['description'],
                'icon' => $row['icon'],
                'earned_at' => $row['earned_at']
            ];
        }

        // Points breakdown
        $query = "SELECT 
                    point_type,activity_type,
                    SUM(points) as total_points
                  FROM user_points
                  WHERE user_id = ? AND tenant_id = ?
                  GROUP BY point_type
                  ORDER BY total_points DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $pointsBreakdown = [];
        while ($row = $result->fetch_assoc()) {
            $pointsBreakdown[] = [
                'activity' => $row['activity_type'],
                'points' => (int) $row['total_points']
            ];
        }

        // This month's points
        $query = "SELECT COALESCE(SUM(points), 0) as total FROM user_points 
                  WHERE user_id = ? AND tenant_id = ?
                  AND MONTH(earned_at) = MONTH(CURRENT_DATE())
                  AND YEAR(earned_at) = YEAR(CURRENT_DATE())";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $pointsThisMonth = (int) $stmt->get_result()->fetch_assoc()['total'];

        // Next badge to earn (closest to achieving)
        $nextBadge = $this->getNextBadgeToEarn();

        return [
            'badges' => $badges,
            'points_breakdown' => $pointsBreakdown,
            'points_this_month' => $pointsThisMonth,
            'next_badge' => $nextBadge
        ];
    }

    private function getNextBadgeToEarn(): ?array
    {
        // This would be based on your badge criteria logic
        // Simplified example
        $query = "SELECT 
                    b.badge_id, b.badge_name, b.description, b.icon
                  FROM badges b
                  LEFT JOIN user_badges ub ON b.badge_id = ub.badge_id AND ub.user_id = ?
                  WHERE b.tenant_id = ? AND ub.badge_id IS NULL
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        if ($result) {
            return [
                'badge_id' => (int) $result['badge_id'],
                'name' => $result['badge_name'],
                'description' => $result['description'],
                'icon' => $result['icon'],
                'progress' => 0 // Would calculate based on criteria
            ];
        }

        return null;
    }

    /**
     * Get my certificates
     */
    private function getMyCertificates(): array
    {
        $query = "SELECT 
                    cert.certificate_id, cert.certificate_number, 
                    cert.issue_date, cert.expiry_date,
                    c.title as course_title,
                    DATEDIFF(cert.expiry_date, CURRENT_DATE()) as days_until_expiry
                  FROM certificates cert
                  JOIN courses c ON cert.course_id = c.course_id
                  WHERE cert.user_id = ? AND cert.tenant_id = ?
                  ORDER BY cert.issue_date DESC
                  LIMIT 5";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $certificates = [];
        while ($row = $result->fetch_assoc()) {
            $daysUntilExpiry = $row['days_until_expiry'] !== null ? (int) $row['days_until_expiry'] : null;
            $status = 'valid';
            if ($daysUntilExpiry !== null) {
                if ($daysUntilExpiry < 0) $status = 'expired';
                elseif ($daysUntilExpiry <= 30) $status = 'expiring_soon';
            }

            $certificates[] = [
                'certificate_id' => (int) $row['certificate_id'],
                'certificate_number' => $row['certificate_number'],
                'course_title' => $row['course_title'],
                'issued_at' => $row['issued_at'],
                'expiry_date' => $row['expiry_date'],
                'days_until_expiry' => $daysUntilExpiry,
                'status' => $status
            ];
        }

        // Count expiring soon
        $query = "SELECT COUNT(*) as total FROM certificates 
                  WHERE user_id = ? AND tenant_id = ?
                  AND expiry_date IS NOT NULL
                  AND expiry_date BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $expiringSoon = (int) $stmt->get_result()->fetch_assoc()['total'];

        return [
            'recent' => $certificates,
            'expiring_soon' => $expiringSoon
        ];
    }

    /**
     * Get my assessments
     */
    private function getMyAssessments(): array
    {
        // Recent assessment attempts
        $query = "SELECT 
                    a.assessment_id, a.title, a.assessment_type,
                    aa.score, aa.passed, aa.time_taken_seconds,
                    aa.completed_at, c.title as course_title
                  FROM assessment_attempts aa
                  JOIN assessments a ON aa.assessment_id = a.assessment_id
                  JOIN courses c ON a.course_id = c.course_id
                  WHERE aa.user_id = ? AND a.tenant_id = ?
                  ORDER BY aa.completed_at DESC
                  LIMIT 5";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $recentAttempts = [];
        while ($row = $result->fetch_assoc()) {
            $recentAttempts[] = [
                'assessment_id' => (int) $row['assessment_id'],
                'title' => $row['title'],
                'type' => $row['assessment_type'],
                'course_title' => $row['course_title'],
                'score' => (int) $row['score'],
                'passed' => (bool) $row['passed'],
                'time_taken' => (int) $row['time_taken_seconds'] / 60,
                'completed_at' => $row['completed_at']
            ];
        }

        // Overall stats
        $query = "SELECT 
                    COUNT(*) as total_attempts,
                    AVG(score) as avg_score,
                    SUM(CASE WHEN passed = 1 THEN 1 ELSE 0 END) as passed_count
                  FROM assessment_attempts aa
                  JOIN assessments a ON aa.assessment_id = a.assessment_id
                  WHERE aa.user_id = ? AND a.tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();

        $totalAttempts = (int) $stats['total_attempts'];
        $passedCount = (int) $stats['passed_count'];

        return [
            'recent_attempts' => $recentAttempts,
            'total_attempts' => $totalAttempts,
            'average_score' => round((float) $stats['avg_score'], 1),
            'pass_rate' => $totalAttempts > 0 ? round(($passedCount / $totalAttempts) * 100, 1) : 0
        ];
    }

    /**
     * Get compliance status
     */
    private function getComplianceStatus(): array
    {
        // Mandatory courses assigned
        $query = "SELECT 
                    c.course_id, c.title,
                    ce.status, ce.progress_percentage, ce.due_date,
                    DATEDIFF(ce.due_date, CURRENT_DATE()) as days_remaining
                  FROM course_enrollments ce
                  JOIN courses c ON ce.course_id = c.course_id
                  WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1
                  AND ce.is_mandatory = 1
                  ORDER BY ce.due_date ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $mandatoryCourses = [];
        $completed = 0;
        $pending = 0;
        $overdue = 0;

        while ($row = $result->fetch_assoc()) {
            $daysRemaining = $row['days_remaining'] !== null ? (int) $row['days_remaining'] : null;
            $status = $row['status'];

            if ($status === 'completed') {
                $completed++;
            } elseif ($daysRemaining !== null && $daysRemaining < 0) {
                $overdue++;
                $status = 'overdue';
            } else {
                $pending++;
            }

            $mandatoryCourses[] = [
                'course_id' => (int) $row['course_id'],
                'title' => $row['title'],
                'status' => $status,
                'progress' => (int) $row['progress_percentage'],
                'due_date' => $row['due_date'],
                'days_remaining' => $daysRemaining
            ];
        }

        $total = $completed + $pending + $overdue;
        $complianceRate = $total > 0 ? round(($completed / $total) * 100, 0) : 100;

        return [
            'compliance_rate' => $complianceRate,
            'completed' => $completed,
            'pending' => $pending,
            'overdue' => $overdue,
            'mandatory_courses' => $mandatoryCourses
        ];
    }

    /**
     * Get leaderboard position
     */
    private function getLeaderboardPosition(): array
    {
        // Overall rank by points (calculate sum per user)
        $query = "SELECT 
                    user_id,
                    SUM(points) as total_points
                  FROM user_points
                  WHERE tenant_id = ?
                  GROUP BY user_id
                  ORDER BY total_points DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $myRank = null;
        $totalUsers = 0;
        $myPoints = 0;
        $rank = 0;

        while ($row = $result->fetch_assoc()) {
            $rank++;
            $totalUsers++;
            if ((int) $row['user_id'] === $this->user_id) {
                $myRank = $rank;
                $myPoints = (int) $row['total_points'];
            }
        }

        // Top 5 on leaderboard
        $query = "SELECT 
                    u.id as user_id,
                    CONCAT(u.first_name, ' ', u.last_name) as full_name,
                    u.avatar,
                    COALESCE(SUM(up.points), 0) as total_points
                  FROM users u
                  LEFT JOIN user_points up ON u.id = up.user_id AND up.tenant_id = ?
                  WHERE u.tenant_id = ? AND u.is_active = 1
                  GROUP BY u.id, u.first_name, u.last_name, u.avatar
                  HAVING total_points > 0
                  ORDER BY total_points DESC
                  LIMIT 5";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->tenant_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $topLearners = [];
        $rank = 1;
        while ($row = $result->fetch_assoc()) {
            $topLearners[] = [
                'user_id' => (int) $row['user_id'],
                'full_name' => $row['full_name'],
                'avatar_url' => $row['avatar'],
                'points' => (int) $row['total_points'],
                'rank' => $rank,
                'is_me' => (int) $row['user_id'] === $this->user_id
            ];
            $rank++;
        }
        // Department rank
        $query = "SELECT u.department FROM users u WHERE u.id = ? AND u.tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $dept = $stmt->get_result()->fetch_assoc()['department'] ?? null;

        $deptRank = null;
        if ($dept) {
            $query = "SELECT 
                        up.user_id,
                        SUM(up.points) as total_points
                      FROM user_points up
                      JOIN users u ON up.user_id = u.id
                      WHERE up.tenant_id = ? AND u.department = ?
                      GROUP BY up.user_id
                      ORDER BY total_points DESC";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("is", $this->tenant_id, $dept);
            $stmt->execute();
            $result = $stmt->get_result();

            $deptRankCounter = 0;
            while ($row = $result->fetch_assoc()) {
                $deptRankCounter++;
                if ((int) $row['user_id'] === $this->user_id) {
                    $deptRank = $deptRankCounter;
                    break;
                }
            }
        }
        return [
            'my_rank' => $myRank,
            'my_points' => $myPoints,
            'total_learners' => $totalUsers,
            'department_rank' => $deptRank,
            'top_learners' => $topLearners
        ];
    }

    /**
     * Get recent activity
     */
    private function getRecentActivity(): array
    {
        $activities = [];

        // Course progress updates
        $query = "SELECT 
                    'progress' as type,
                    c.title as course_title,
                    ce.progress_percentage,
                    ce.last_activity_at as activity_date
                  FROM course_enrollments ce
                  JOIN courses c ON ce.course_id = c.course_id
                  WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1
                  AND ce.last_activity_at IS NOT NULL
                  ORDER BY ce.last_activity_at DESC
                  LIMIT 5";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $activities[] = [
                'type' => 'progress',
                'icon' => 'circle-play',
                'message' => "Made progress on {$row['course_title']} ({$row['progress_percentage']}%)",
                'date' => $row['activity_date'],
                'color' => 'secondary'
            ];
        }

        // Completed courses
        $query = "SELECT 
                    c.title as course_title,
                    ce.completed_at as activity_date
                  FROM course_enrollments ce
                  JOIN courses c ON ce.course_id = c.course_id
                  WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1
                  AND ce.status = 'completed'
                  ORDER BY ce.completed_at DESC
                  LIMIT 3";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $activities[] = [
                'type' => 'completion',
                'icon' => 'circle-check',
                'message' => "Completed {$row['course_title']}",
                'date' => $row['activity_date'],
                'color' => 'success'
            ];
        }

        // Badges earned
        $query = "SELECT 
                    b.badge_name as badge_name,
                    ub.earned_at as activity_date
                  FROM user_badges ub
                  JOIN badges b ON ub.badge_id = b.badge_id
                  WHERE ub.user_id = ? AND ub.tenant_id = ?
                  ORDER BY ub.earned_at DESC
                  LIMIT 3";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $activities[] = [
                'type' => 'badge',
                'icon' => 'award',
                'message' => "Earned badge: {$row['badge_name']}",
                'date' => $row['activity_date'],
                'color' => 'warning'
            ];
        }

        // Sort by date
        usort($activities, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        return array_slice($activities, 0, 10);
    }

    /**
     * Get upcoming deadlines
     */
    private function getUpcomingDeadlines(): array
    {
        $query = "SELECT 
                    c.course_id, c.title,
                    ce.due_date, ce.progress_percentage,
                    DATEDIFF(ce.due_date, CURRENT_DATE()) as days_remaining
                  FROM course_enrollments ce
                  JOIN courses c ON ce.course_id = c.course_id
                  WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1
                  AND ce.status != 'completed'
                  AND ce.due_date IS NOT NULL
                  AND ce.due_date >= CURRENT_DATE()
                  ORDER BY ce.due_date ASC
                  LIMIT 5";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $deadlines = [];
        while ($row = $result->fetch_assoc()) {
            $daysRemaining = (int) $row['days_remaining'];
            $urgency = 'normal';
            if ($daysRemaining <= 3) $urgency = 'critical';
            elseif ($daysRemaining <= 7) $urgency = 'warning';

            $deadlines[] = [
                'course_id' => (int) $row['course_id'],
                'title' => $row['title'],
                'due_date' => $row['due_date'],
                'days_remaining' => $daysRemaining,
                'progress' => (int) $row['progress_percentage'],
                'urgency' => $urgency
            ];
        }

        return $deadlines;
    }

    /**
     * Get recommended courses
     */
    private function getRecommendedCourses(): array
    {
        // Courses not yet enrolled, based on category of completed courses
        $query = "SELECT DISTINCT 
                    c.course_id, c.title, c.level,
                    c.duration_minutes, cat.name as category,
                    (SELECT AVG(cr.rating) FROM course_reviews cr WHERE cr.course_id = c.course_id) as avg_rating,
                    (SELECT COUNT(*) FROM course_enrollments ce2 WHERE ce2.course_id = c.course_id) as enrollment_count
                  FROM courses c
                  LEFT JOIN categories cat ON c.category_id = cat.category_id
                  WHERE c.tenant_id = ? AND c.is_active = 1
                  AND c.course_id NOT IN (
                    SELECT course_id FROM course_enrollments 
                    WHERE user_id = ? AND tenant_id = ?
                  )
                  ORDER BY enrollment_count DESC, avg_rating DESC
                  LIMIT 4";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iii", $this->tenant_id, $this->user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $recommended = [];
        while ($row = $result->fetch_assoc()) {
            $recommended[] = [
                'course_id' => (int) $row['course_id'],
                'title' => $row['title'],
                'level' => $row['level'],
                'category' => $row['category'],
                'duration_minutes' => (float) $row['duration_minutes'],
                'avg_rating' => round((float) $row['avg_rating'], 1),
                'enrollment_count' => (int) $row['enrollment_count']
            ];
        }

        return $recommended;
    }

    /**
     * Get weekly progress data
     */
    private function getWeeklyProgress(): array
    {
        $weekData = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $dayName = date('D', strtotime("-$i days"));

            // Learning time
            $query = "SELECT COALESCE(SUM(time_spent_minutes), 0) as minutes
                      FROM learning_sessions 
                      WHERE user_id = ? AND tenant_id = ? AND DATE(started_at) = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("iis", $this->user_id, $this->tenant_id, $date);
            $stmt->execute();
            $minutes = (int) $stmt->get_result()->fetch_assoc()['minutes'];

            $weekData[] = [
                'date' => $date,
                'day' => $dayName,
                'minutes' => $minutes,
                'hours' => round($minutes / 60, 1)
            ];
        }

        return $weekData;
    }

    /**
     * Get skill progress
     */
    private function getSkillProgress(): array
    {
        // This assumes you have skills/categories that users develop
        $query = "SELECT 
                    cat.name as skill,
                    COUNT(CASE WHEN ce.status = 'completed' THEN 1 END) as completed,
                    COUNT(*) as total
                  FROM course_enrollments ce
                  JOIN courses c ON ce.course_id = c.course_id
                  JOIN categories cat ON c.category_id = cat.category_id
                  WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1
                  GROUP BY cat.category_id, cat.name
                  ORDER BY completed DESC
                  LIMIT 5";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $skills = [];
        while ($row = $result->fetch_assoc()) {
            $total = (int) $row['total'];
            $completed = (int) $row['completed'];
            $skills[] = [
                'skill' => $row['skill'],
                'completed' => $completed,
                'total' => $total,
                'progress' => $total > 0 ? round(($completed / $total) * 100, 0) : 0
            ];
        }

        return $skills;
    }
}