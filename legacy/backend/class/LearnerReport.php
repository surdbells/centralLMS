<?php

/**
 * LearnerReport Manager
 * Handles learner personal reports and analytics
 * For Multi-Tenant LMS Learner Portal
 */

class LearnerReport
{
    private mysqli $conn;
    private int $tenant_id;
    private int $user_id;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function setTenant(int $tenant_id): void
    {
        $this->tenant_id = $tenant_id;
    }

    public function setUser(int $user_id): void
    {
        $this->user_id = $user_id;
    }

    // ==========================================
    // OVERVIEW STATISTICS
    // ==========================================

    /**
     * Get comprehensive learning overview
     */
    public function getOverview(): array
    {
        return [
            'enrollment_stats' => $this->getEnrollmentStats(),
            'learning_time' => $this->getLearningTimeStats(),
            'performance' => $this->getPerformanceStats(),
            'achievements' => $this->getAchievementStats(),
            'current_streak' => $this->getCurrentStreak(),
            'rank' => $this->getLeaderboardRank()
        ];
    }

    /**
     * Get enrollment statistics
     */
    private function getEnrollmentStats(): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                COUNT(*) as total_enrolled,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 'not_started' THEN 1 ELSE 0 END) as not_started,
                SUM(CASE WHEN status != 'completed' AND due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
                SUM(CASE WHEN is_mandatory = 1 THEN 1 ELSE 0 END) as mandatory_total,
                SUM(CASE WHEN is_mandatory = 1 AND status = 'completed' THEN 1 ELSE 0 END) as mandatory_completed,
                ROUND(AVG(CASE WHEN status = 'completed' THEN progress_percentage ELSE NULL END), 1) as avg_completion_rate
            FROM course_enrollments
            WHERE tenant_id = ? AND user_id = ? AND is_active = 1 AND status != 'dropped'
        ");
        $stmt->bind_param("ii", $this->tenant_id, $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Calculate completion rate
        $total = (int) $result['total_enrolled'];
        $completed = (int) $result['completed'];
        $result['completion_rate'] = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

        // Mandatory compliance rate
        $mandatoryTotal = (int) $result['mandatory_total'];
        $mandatoryCompleted = (int) $result['mandatory_completed'];
        $result['mandatory_compliance_rate'] = $mandatoryTotal > 0 
            ? round(($mandatoryCompleted / $mandatoryTotal) * 100, 1) : 100;

        return $result;
    }

    /**
     * Get learning time statistics
     */
    private function getLearningTimeStats(): array
    {
        // Total time
        $stmt = $this->conn->prepare("
            SELECT 
                COALESCE(SUM(time_spent_seconds), 0) as total_seconds,
                COALESCE(SUM(time_spent_minutes), 0) as total_minutes_alt
            FROM lesson_progress
            WHERE tenant_id = ? AND user_id = ?
        ");
        $stmt->bind_param("ii", $this->tenant_id, $this->user_id);
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $totalSeconds = (int) $total['total_seconds'];
        $totalMinutes = $totalSeconds > 0 ? $totalSeconds / 60 : (int) $total['total_minutes_alt'];

        // This week's time
        $stmt = $this->conn->prepare("
            SELECT COALESCE(SUM(time_spent_seconds), 0) / 60 as minutes
            FROM lesson_progress
            WHERE tenant_id = ? AND user_id = ? 
                AND YEARWEEK(last_activity_at, 1) = YEARWEEK(CURDATE(), 1)
        ");
        $stmt->bind_param("ii", $this->tenant_id, $this->user_id);
        $stmt->execute();
        $thisWeek = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // This month's time
        $stmt = $this->conn->prepare("
            SELECT COALESCE(SUM(time_spent_seconds), 0) / 60 as minutes
            FROM lesson_progress
            WHERE tenant_id = ? AND user_id = ? 
                AND YEAR(last_activity_at) = YEAR(CURDATE())
                AND MONTH(last_activity_at) = MONTH(CURDATE())
        ");
        $stmt->bind_param("ii", $this->tenant_id, $this->user_id);
        $stmt->execute();
        $thisMonth = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Average daily time (last 30 days)
        $stmt = $this->conn->prepare("
            SELECT 
                COUNT(DISTINCT DATE(last_activity_at)) as active_days,
                COALESCE(SUM(time_spent_seconds), 0) / 60 as total_minutes
            FROM lesson_progress
            WHERE tenant_id = ? AND user_id = ? 
                AND last_activity_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ");
        $stmt->bind_param("ii", $this->tenant_id, $this->user_id);
        $stmt->execute();
        $last30 = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $activeDays = (int) $last30['active_days'];
        $avgDaily = $activeDays > 0 ? round($last30['total_minutes'] / $activeDays, 1) : 0;

        return [
            'total_minutes' => round($totalMinutes, 1),
            'total_hours' => round($totalMinutes / 60, 1),
            'this_week_minutes' => round($thisWeek['minutes'] ?? 0, 1),
            'this_month_minutes' => round($thisMonth['minutes'] ?? 0, 1),
            'avg_daily_minutes' => $avgDaily,
            'active_days_last_30' => $activeDays
        ];
    }

    /**
     * Get performance statistics
     */
    private function getPerformanceStats(): array
    {
        // Check if quiz_attempts table exists
        $checkTable = $this->conn->query("SHOW TABLES LIKE 'quiz_attempts'");
        if ($checkTable->num_rows === 0) {
            return [
                'quizzes_taken' => 0,
                'quizzes_passed' => 0,
                'average_score' => 0,
                'highest_score' => 0,
                'pass_rate' => 0
            ];
        }

        $stmt = $this->conn->prepare("
            SELECT 
                COUNT(*) as quizzes_taken,
                SUM(CASE WHEN passed = 1 THEN 1 ELSE 0 END) as quizzes_passed,
                ROUND(AVG(score), 1) as average_score,
                MAX(score) as highest_score
            FROM assessment_attempts
            WHERE tenant_id = ? AND user_id = ?
        ");
        $stmt->bind_param("ii", $this->tenant_id, $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $taken = (int) $result['quizzes_taken'];
        $passed = (int) $result['quizzes_passed'];

        return [
            'quizzes_taken' => $taken,
            'quizzes_passed' => $passed,
            'average_score' => (float) ($result['average_score'] ?? 0),
            'highest_score' => (int) ($result['highest_score'] ?? 0),
            'pass_rate' => $taken > 0 ? round(($passed / $taken) * 100, 1) : 0
        ];
    }

    /**
     * Get achievement statistics
     */
    private function getAchievementStats(): array
    {
        // Certificates
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as total
            FROM certificates
            WHERE tenant_id = ? AND user_id = ?
        ");
        $stmt->bind_param("ii", $this->tenant_id, $this->user_id);
        $stmt->execute();
        $certs = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Badges (if table exists)
        $badges = 0;
        $checkTable = $this->conn->query("SHOW TABLES LIKE 'user_badges'");
        if ($checkTable->num_rows > 0) {
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) as total
                FROM user_badges
                WHERE tenant_id = ? AND user_id = ?
            ");
            $stmt->bind_param("ii", $this->tenant_id, $this->user_id);
            $stmt->execute();
            $badgeResult = $stmt->get_result()->fetch_assoc();
            $badges = (int) ($badgeResult['total'] ?? 0);
            $stmt->close();
        }

        // Points (if table exists)
        $points = 0;
        $checkTable = $this->conn->query("SHOW TABLES LIKE 'user_points'");
        if ($checkTable->num_rows > 0) {
            $stmt = $this->conn->prepare("
                SELECT COALESCE(SUM(points), 0) as total
                FROM user_points
                WHERE tenant_id = ? AND user_id = ?
            ");
            $stmt->bind_param("ii", $this->tenant_id, $this->user_id);
            $stmt->execute();
            $pointsResult = $stmt->get_result()->fetch_assoc();
            $points = (int) ($pointsResult['total'] ?? 0);
            $stmt->close();
        }

        return [
            'certificates_earned' => (int) ($certs['total'] ?? 0),
            'badges_earned' => $badges,
            'total_points' => $points
        ];
    }

    /**
     * Get current learning streak
     */
    private function getCurrentStreak(): int
    {
        $checkTable = $this->conn->query("SHOW TABLES LIKE 'user_streaks'");
        if ($checkTable->num_rows > 0) {
            $stmt = $this->conn->prepare("
                SELECT current_streak
                FROM user_streaks
                WHERE tenant_id = ? AND user_id = ?
            ");
            $stmt->bind_param("ii", $this->tenant_id, $this->user_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return (int) ($result['current_streak'] ?? 0);
        }
        return 0;
    }

    /**
     * Get leaderboard rank
     */
    private function getLeaderboardRank(): ?int
    {
        $checkTable = $this->conn->query("SHOW TABLES LIKE 'user_points'");
        if ($checkTable->num_rows === 0) {
            return null;
        }

        $stmt = $this->conn->prepare("
            SELECT COUNT(*) + 1 as user_rank
            FROM (
                SELECT user_id, SUM(points) as total_points
                FROM user_points
                WHERE tenant_id = ?
                GROUP BY user_id
            ) as rankings
            WHERE total_points > (
                SELECT COALESCE(SUM(points), 0)
                FROM user_points
                WHERE tenant_id = ? AND user_id = ?
            )
        ");
        $stmt->bind_param("iii", $this->tenant_id, $this->tenant_id, $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($result['user_rank'] ?? 1);
    }

    // ==========================================
    // COURSE PROGRESS REPORT
    // ==========================================

    /**
     * Get detailed course progress report
     */
    public function getCourseProgressReport(): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                ce.enrollment_id,
                ce.course_id,
                ce.status,
                ce.progress_percentage,
                ce.enrolled_at,
                ce.started_at,
                ce.completed_at,
                ce.due_date,
                ce.is_mandatory,
                c.title,
                c.duration_minutes,
                cat.name as category_name,
                (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.course_id AND l.is_active = 1) as total_lessons,
                DATEDIFF(COALESCE(ce.completed_at, NOW()), ce.started_at) as days_to_complete
            FROM course_enrollments ce
            INNER JOIN courses c ON ce.course_id = c.course_id
            LEFT JOIN categories cat ON c.category_id = cat.category_id
            WHERE ce.tenant_id = ? AND ce.user_id = ? AND ce.is_active = 1 AND ce.status != 'dropped'
            ORDER BY 
                CASE ce.status 
                    WHEN 'in_progress' THEN 1 
                    WHEN 'not_started' THEN 2 
                    WHEN 'completed' THEN 3 
                END,
                ce.enrolled_at DESC
        ");
        $stmt->bind_param("ii", $this->tenant_id, $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $courses = [];
        while ($row = $result->fetch_assoc()) {
            $row['progress_percentage'] = (float) $row['progress_percentage'];
            $row['is_mandatory'] = (bool) $row['is_mandatory'];
            $row['total_lessons'] = (int) $row['total_lessons'];
            $row['completed_lessons'] = $this->getCompletedLessonsCount($row['course_id']);
            $row['time_spent_minutes'] = $this->getCourseTimeSpent($row['course_id']);
            $row['is_overdue'] = $row['due_date'] && $row['status'] !== 'completed' 
                && strtotime($row['due_date']) < strtotime('today');
            $courses[] = $row;
        }
        $stmt->close();

        return $courses;
    }

    /**
     * Get completed lessons count for a course
     */
    private function getCompletedLessonsCount(int $course_id): int
    {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as completed
            FROM lesson_progress lp
            INNER JOIN lessons l ON lp.lesson_id = l.lesson_id
            WHERE lp.tenant_id = ? AND lp.user_id = ? 
                AND l.course_id = ? AND lp.status = 'completed'
        ");
        $stmt->bind_param("iii", $this->tenant_id, $this->user_id, $course_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        return (int) ($result['completed'] ?? 0);
    }

    /**
     * Get time spent on a course
     */
    private function getCourseTimeSpent(int $course_id): int
    {
        $stmt = $this->conn->prepare("
            SELECT COALESCE(SUM(lp.time_spent_seconds), 0) / 60 as minutes
            FROM lesson_progress lp
            INNER JOIN lessons l ON lp.lesson_id = l.lesson_id
            WHERE lp.tenant_id = ? AND lp.user_id = ? AND l.course_id = ?
        ");
        $stmt->bind_param("iii", $this->tenant_id, $this->user_id, $course_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        return (int) ($result['minutes'] ?? 0);
    }

    // ==========================================
    // LEARNING ACTIVITY TIMELINE
    // ==========================================

    /**
     * Get daily learning activity for chart
     */
    public function getDailyActivity(int $days = 30): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                DATE(last_activity_at) as date,
                COUNT(DISTINCT lesson_id) as lessons_accessed,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as lessons_completed,
                COALESCE(SUM(time_spent_seconds), 0) / 60 as minutes_spent
            FROM lesson_progress
            WHERE tenant_id = ? AND user_id = ? 
                AND last_activity_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY DATE(last_activity_at)
            ORDER BY date ASC
        ");
        $stmt->bind_param("iii", $this->tenant_id, $this->user_id, $days);
        $stmt->execute();
        $result = $stmt->get_result();

        $activity = [];
        while ($row = $result->fetch_assoc()) {
            $activity[] = [
                'date' => $row['date'],
                'lessons_accessed' => (int) $row['lessons_accessed'],
                'lessons_completed' => (int) $row['lessons_completed'],
                'minutes_spent' => round($row['minutes_spent'], 1)
            ];
        }
        $stmt->close();

        return $activity;
    }

    /**
     * Get weekly learning activity summary
     */
    public function getWeeklyActivity(int $weeks = 12): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                YEARWEEK(last_activity_at, 1) as year_week,
                MIN(DATE(last_activity_at)) as week_start,
                COUNT(DISTINCT lesson_id) as lessons_accessed,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as lessons_completed,
                COALESCE(SUM(time_spent_seconds), 0) / 60 as minutes_spent,
                COUNT(DISTINCT DATE(last_activity_at)) as active_days
            FROM lesson_progress
            WHERE tenant_id = ? AND user_id = ? 
                AND last_activity_at >= DATE_SUB(CURDATE(), INTERVAL ? WEEK)
            GROUP BY YEARWEEK(last_activity_at, 1)
            ORDER BY year_week ASC
        ");
        $stmt->bind_param("iii", $this->tenant_id, $this->user_id, $weeks);
        $stmt->execute();
        $result = $stmt->get_result();

        $activity = [];
        while ($row = $result->fetch_assoc()) {
            $activity[] = [
                'week_start' => $row['week_start'],
                'lessons_accessed' => (int) $row['lessons_accessed'],
                'lessons_completed' => (int) $row['lessons_completed'],
                'minutes_spent' => round($row['minutes_spent'], 1),
                'active_days' => (int) $row['active_days']
            ];
        }
        $stmt->close();

        return $activity;
    }

    /**
     * Get monthly learning activity summary
     */
    public function getMonthlyActivity(int $months = 12): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                DATE_FORMAT(last_activity_at, '%Y-%m') as month,
                DATE_FORMAT(last_activity_at, '%b %Y') as month_label,
                COUNT(DISTINCT lesson_id) as lessons_accessed,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as lessons_completed,
                COALESCE(SUM(time_spent_seconds), 0) / 60 as minutes_spent,
                COUNT(DISTINCT DATE(last_activity_at)) as active_days
            FROM lesson_progress
            WHERE tenant_id = ? AND user_id = ? 
                AND last_activity_at >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
            GROUP BY DATE_FORMAT(last_activity_at, '%Y-%m')
            ORDER BY month ASC
        ");
        $stmt->bind_param("iii", $this->tenant_id, $this->user_id, $months);
        $stmt->execute();
        $result = $stmt->get_result();

        $activity = [];
        while ($row = $result->fetch_assoc()) {
            $activity[] = [
                'month' => $row['month'],
                'month_label' => $row['month_label'],
                'lessons_accessed' => (int) $row['lessons_accessed'],
                'lessons_completed' => (int) $row['lessons_completed'],
                'minutes_spent' => round($row['minutes_spent'], 1),
                'hours_spent' => round($row['minutes_spent'] / 60, 1),
                'active_days' => (int) $row['active_days']
            ];
        }
        $stmt->close();

        return $activity;
    }

    // ==========================================
    // CATEGORY BREAKDOWN
    // ==========================================

    /**
     * Get learning breakdown by category
     */
    public function getCategoryBreakdown(): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                COALESCE(cat.name, 'Uncategorized') as category_name,
                COUNT(*) as total_courses,
                SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed_courses,
                SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_courses,
                ROUND(AVG(ce.progress_percentage), 1) as avg_progress
            FROM course_enrollments ce
            INNER JOIN courses c ON ce.course_id = c.course_id
            LEFT JOIN categories cat ON c.category_id = cat.category_id
            WHERE ce.tenant_id = ? AND ce.user_id = ? AND ce.is_active = 1 AND ce.status != 'dropped'
            GROUP BY cat.category_id, cat.name
            ORDER BY total_courses DESC
        ");
        $stmt->bind_param("ii", $this->tenant_id, $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $categories = [];
        while ($row = $result->fetch_assoc()) {
            $row['total_courses'] = (int) $row['total_courses'];
            $row['completed_courses'] = (int) $row['completed_courses'];
            $row['in_progress_courses'] = (int) $row['in_progress_courses'];
            $row['avg_progress'] = (float) $row['avg_progress'];
            $row['completion_rate'] = $row['total_courses'] > 0 
                ? round(($row['completed_courses'] / $row['total_courses']) * 100, 1) : 0;
            $categories[] = $row;
        }
        $stmt->close();

        return $categories;
    }

    // ==========================================
    // QUIZ PERFORMANCE REPORT
    // ==========================================

    /**
     * Get quiz performance history
     */
    public function getQuizPerformance(int $limit = 20): array
    {
        $checkTable = $this->conn->query("SHOW TABLES LIKE 'assessment_attempts'");
        if ($checkTable->num_rows === 0) {
            return [];
        }

        $stmt = $this->conn->prepare("
            SELECT 
                qa.attempt_id,
                qa.assessment_id,
                qa.score,
                qa.passed,
                qa.time_taken_seconds,
                qa.attempt_number,
                qa.created_at as attempted_at,
                q.title as quiz_title,
                q.pass_percentage,
                c.title as course_title
            FROM assessment_attempts qa
            INNER JOIN assessments q ON qa.assessment_id = q.assessment_id
            LEFT JOIN courses c ON q.course_id = c.course_id
            WHERE qa.tenant_id = ? AND qa.user_id = ?
            ORDER BY qa.created_at DESC
            LIMIT ?
        ");
        $stmt->bind_param("iii", $this->tenant_id, $this->user_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $quizzes = [];
        while ($row = $result->fetch_assoc()) {
            $row['score'] = (int) $row['score'];
            $row['passed'] = (bool) $row['passed'];
            $row['passing_score'] = (int) $row['pass_percentage'];
            $row['time_taken_minutes'] = round(($row['time_taken_seconds'] ?? 0) / 60, 1);
            $quizzes[] = $row;
        }
        $stmt->close();

        return $quizzes;
    }

    // ==========================================
    // CERTIFICATES
    // ==========================================

    /**
     * Get all certificates earned
     */
    public function getCertificates(): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                cert.certificate_id,
                cert.certificate_number,
                cert.issue_date,
                cert.expiry_date,
                cert.pdf_url,
                c.title as course_title,
                c.course_id,
                cat.name as category_name,
                CASE 
                    WHEN cert.expiry_date IS NULL THEN 'valid'
                    WHEN cert.expiry_date < CURDATE() THEN 'expired'
                    WHEN cert.expiry_date < DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'expiring_soon'
                    ELSE 'valid'
                END as status
            FROM certificates cert
            INNER JOIN courses c ON cert.course_id = c.course_id
            LEFT JOIN categories cat ON c.category_id = cat.category_id
            WHERE cert.tenant_id = ? AND cert.user_id = ?
            ORDER BY cert.issue_date DESC
        ");
        $stmt->bind_param("ii", $this->tenant_id, $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $certificates = [];
        while ($row = $result->fetch_assoc()) {
            $certificates[] = $row;
        }
        $stmt->close();

        return $certificates;
    }

    // ==========================================
    // RECENT ACTIVITY
    // ==========================================

    /**
     * Get recent learning activities
     */
    public function getRecentActivities(int $limit = 20): array
    {
        $activities = [];

        // Recent lesson activities
        $stmt = $this->conn->prepare("
            SELECT 
                'lesson' as activity_type,
                lp.lesson_id as item_id,
                l.title as item_title,
                c.title as course_title,
                lp.status,
                lp.progress_percentage,
                lp.last_activity_at as activity_at
            FROM lesson_progress lp
            INNER JOIN lessons l ON lp.lesson_id = l.lesson_id
            INNER JOIN courses c ON l.course_id = c.course_id
            WHERE lp.tenant_id = ? AND lp.user_id = ?
            ORDER BY lp.last_activity_at DESC
            LIMIT ?
        ");
        $stmt->bind_param("iii", $this->tenant_id, $this->user_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $row['progress_percentage'] = (float) $row['progress_percentage'];
            $activities[] = $row;
        }
        $stmt->close();

        // Recent course completions
        $stmt = $this->conn->prepare("
            SELECT 
                'course_completed' as activity_type,
                ce.course_id as item_id,
                c.title as item_title,
                c.title as course_title,
                'completed' as status,
                100 as progress_percentage,
                ce.completed_at as activity_at
            FROM course_enrollments ce
            INNER JOIN courses c ON ce.course_id = c.course_id
            WHERE ce.tenant_id = ? AND ce.user_id = ? 
                AND ce.status = 'completed' AND ce.completed_at IS NOT NULL
            ORDER BY ce.completed_at DESC
            LIMIT ?
        ");
        $stmt->bind_param("iii", $this->tenant_id, $this->user_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $activities[] = $row;
        }
        $stmt->close();

        // Recent certificates
        $stmt = $this->conn->prepare("
            SELECT 
                'certificate' as activity_type,
                cert.certificate_id as item_id,
                c.title as item_title,
                c.title as course_title,
                'earned' as status,
                100 as progress_percentage,
                cert.issue_date as activity_at
            FROM certificates cert
            INNER JOIN courses c ON cert.course_id = c.course_id
            WHERE cert.tenant_id = ? AND cert.user_id = ?
            ORDER BY cert.issue_date DESC
            LIMIT ?
        ");
        $stmt->bind_param("iii", $this->tenant_id, $this->user_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $activities[] = $row;
        }
        $stmt->close();

        // Sort by activity date and limit
        usort($activities, function($a, $b) {
            return strtotime($b['activity_at']) - strtotime($a['activity_at']);
        });

        return array_slice($activities, 0, $limit);
    }

    // ==========================================
    // SKILLS/COMPETENCIES (if applicable)
    // ==========================================

    /**
     * Get skills acquired through completed courses
     */
    public function getSkillsAcquired(): array
    {
        $checkTable = $this->conn->query("SHOW TABLES LIKE 'course_skills'");
        if ($checkTable->num_rows === 0) {
            return [];
        }

        $stmt = $this->conn->prepare("
            SELECT 
                s.skill_id,
                s.name as skill_name,
                COUNT(DISTINCT cs.course_id) as courses_count,
                MAX(ce.completed_at) as last_acquired
            FROM course_skills cs
            INNER JOIN skills s ON cs.skill_id = s.skill_id
            INNER JOIN course_enrollments ce ON cs.course_id = ce.course_id 
                AND ce.tenant_id = ? AND ce.user_id = ? AND ce.status = 'completed'
            GROUP BY s.skill_id, s.name
            ORDER BY courses_count DESC, last_acquired DESC
        ");
        $stmt->bind_param("ii", $this->tenant_id, $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $skills = [];
        while ($row = $result->fetch_assoc()) {
            $skills[] = $row;
        }
        $stmt->close();

        return $skills;
    }

    // ==========================================
    // EXPORT REPORT
    // ==========================================

    /**
     * Get full report data for export
     */
    public function getFullReport(): array
    {
        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'overview' => $this->getOverview(),
            'course_progress' => $this->getCourseProgressReport(),
            'category_breakdown' => $this->getCategoryBreakdown(),
            'monthly_activity' => $this->getMonthlyActivity(12),
            'certificates' => $this->getCertificates(),
            'quiz_performance' => $this->getQuizPerformance(50),
            'skills_acquired' => $this->getSkillsAcquired()
        ];
    }
}
