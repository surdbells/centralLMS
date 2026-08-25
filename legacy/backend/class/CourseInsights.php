<?php
/**
 * CourseInsights Class
 * Comprehensive analytics for LMS courses
 * Returns detailed metrics for course performance dashboards
 */

class CourseInsights
{
    private $conn;
    private $course_id;
    private $tenant_id;
    private $date_from;
    private $date_to;
    private $period;

    public function __construct($db)
    {
        $this->conn = $db;
    }

    /**
     * Set parameters for insights query
     */
    public function setParams(int $course_id, int $tenant_id, ?string $date_from = null, ?string $date_to = null, string $period = 'daily'): void
    {
        $this->course_id = $course_id;
        $this->tenant_id = $tenant_id;
        $this->date_from = $date_from;
        $this->date_to = $date_to ?? date('Y-m-d');
        $this->period = $period;

        // Default date_from based on period if not set
        if (!$this->date_from) {
            switch ($period) {
                case 'daily':
                    $this->date_from = date('Y-m-d', strtotime('-30 days'));
                    break;
                case 'weekly':
                    $this->date_from = date('Y-m-d', strtotime('-90 days'));
                    break;
                case 'monthly':
                    $this->date_from = date('Y-m-d', strtotime('-1 year'));
                    break;
            }
        }
    }

    /**
     * Get all insights data
     */
    public function getInsights(): array
    {
        return [
            'course' => $this->getCourseInfo(),
            'overview' => $this->getOverview(),
            'enrollment' => $this->getEnrollmentAnalytics(),
            'completion' => $this->getCompletionAnalytics(),
            'engagement' => $this->getEngagementAnalytics(),
            'assessments' => $this->getAssessmentAnalytics(),
            'modules' => $this->getModuleAnalytics(),
            'learners' => $this->getLearnerAnalytics(),
            'trends' => $this->getTrendData()
        ];
    }

    /**
     * Get basic course information
     */
    private function getCourseInfo(): ?array
    {
        $query = "SELECT 
                    c.course_id, 
                    c.tenant_id, 
                    c.title, 
                    c.description, 
                    NULL as thumbnail_url,
                    COALESCE(cat.name, 'Uncategorized') as category,
                    COALESCE(c.level, 'Beginner') as difficulty_level,
                    COALESCE(CAST(c.duration_minutes * 60 AS UNSIGNED), 0) as estimated_duration,
                    c.is_active as is_published,
                    c.created_at, 
                    c.updated_at,
                    (SELECT COUNT(*) FROM modules WHERE course_id = c.course_id AND is_active = 1) as total_modules,
                    (SELECT COUNT(*) FROM lessons l 
                     JOIN modules m ON l.module_id = m.module_id 
                     WHERE m.course_id = c.course_id AND l.is_active = 1 AND m.is_active = 1) as total_lessons
                  FROM courses c
                  LEFT JOIN categories cat ON c.category_id = cat.category_id
                  WHERE c.course_id = ? AND c.tenant_id = ?";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        if ($result) {
            $result['course_id'] = (int) $result['course_id'];
            $result['tenant_id'] = (int) $result['tenant_id'];
            $result['estimated_duration'] = (int) $result['estimated_duration'];
            $result['total_modules'] = (int) $result['total_modules'];
            $result['total_lessons'] = (int) $result['total_lessons'];
            $result['is_published'] = (bool) $result['is_published'];
            // Normalize difficulty level to Title Case
            $result['difficulty_level'] = ucfirst(strtolower($result['difficulty_level']));
        }

        return $result;
    }

    /**
     * Get course overview statistics
     */
    private function getOverview(): array
    {
        // Get enrollment counts
        $query = "SELECT 
                    COUNT(*) as total_enrolled,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_count,
                    SUM(CASE WHEN status = 'not_started' THEN 1 ELSE 0 END) as not_started_count,
                    SUM(CASE WHEN status = 'dropped' THEN 1 ELSE 0 END) as dropped_count,
                    AVG(progress_percentage) as average_progress,
                    AVG(CASE WHEN final_score IS NOT NULL THEN final_score ELSE NULL END) as average_score,
                    AVG(time_spent_minutes) as average_time_spent
                  FROM course_enrollments
                  WHERE course_id = ? AND tenant_id = ? AND is_active = 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();

        // Active learners (activity in last 7 days)
        $query = "SELECT COUNT(DISTINCT user_id) as active_learners
                  FROM course_enrollments
                  WHERE course_id = ? AND tenant_id = ? AND is_active = 1
                  AND last_activity_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $active = $stmt->get_result()->fetch_assoc();

        // Certificates issued
        $query = "SELECT COUNT(*) as total_certificates
                  FROM certificates
                  WHERE course_id = ? AND tenant_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $certs = $stmt->get_result()->fetch_assoc();

        // Course rating
        $query = "SELECT AVG(rating) as course_rating, COUNT(*) as total_reviews
                  FROM course_reviews
                  WHERE course_id = ? AND tenant_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $reviews = $stmt->get_result()->fetch_assoc();

        return [
            'total_enrolled' => (int) ($stats['total_enrolled'] ?? 0),
            'active_learners' => (int) ($active['active_learners'] ?? 0),
            'completed_count' => (int) ($stats['completed_count'] ?? 0),
            'in_progress_count' => (int) ($stats['in_progress_count'] ?? 0),
            'not_started_count' => (int) ($stats['not_started_count'] ?? 0),
            'dropped_count' => (int) ($stats['dropped_count'] ?? 0),
            'average_progress' => round((float) ($stats['average_progress'] ?? 0), 1),
            'average_score' => round((float) ($stats['average_score'] ?? 0), 1),
            'average_time_spent' => round((float) ($stats['average_time_spent'] ?? 0), 0),
            'total_certificates_issued' => (int) ($certs['total_certificates'] ?? 0),
            'course_rating' => round((float) ($reviews['course_rating'] ?? 0), 1),
            'total_reviews' => (int) ($reviews['total_reviews'] ?? 0)
        ];
    }

    /**
     * Get enrollment analytics
     */
    private function getEnrollmentAnalytics(): array
    {
        // Total and recent enrollments
        $query = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN enrolled_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as this_week,
                    SUM(CASE WHEN enrolled_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as this_month
                  FROM course_enrollments
                  WHERE course_id = ? AND tenant_id = ? AND is_active = 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $totals = $stmt->get_result()->fetch_assoc();

        // Calculate growth rate (compare last 30 days to previous 30 days)
        $query = "SELECT 
                    SUM(CASE WHEN enrolled_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as current_period,
                    SUM(CASE WHEN enrolled_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) 
                             AND enrolled_at < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as previous_period
                  FROM course_enrollments
                  WHERE course_id = ? AND tenant_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $growth = $stmt->get_result()->fetch_assoc();

        $growthRate = 0;
        if ($growth['previous_period'] > 0) {
            $growthRate = (($growth['current_period'] - $growth['previous_period']) / $growth['previous_period']) * 100;
        }

        // By source
        $query = "SELECT 
                    COALESCE(enrollment_source, 'Direct Assignment') as source,
                    COUNT(*) as count
                  FROM course_enrollments
                  WHERE course_id = ? AND tenant_id = ? AND is_active = 1
                  GROUP BY enrollment_source
                  ORDER BY count DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $bySource = [];
        $totalEnrolled = (int) $totals['total'];
        while ($row = $result->fetch_assoc()) {
            $count = (int) $row['count'];
            $bySource[] = [
                'source' => $row['source'],
                'count' => $count,
                'percentage' => $totalEnrolled > 0 ? round(($count / $totalEnrolled) * 100, 1) : 0
            ];
        }

        // By department
        $query = "SELECT 
                    d.department_id, d.department_name,
                    COUNT(ce.enrollment_id) as enrolled,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  JOIN departments d ON u.department = d.department_id
                  WHERE ce.course_id = ? AND ce.tenant_id = ? AND ce.is_active = 1
                  GROUP BY d.department_id, d.department_name
                  ORDER BY enrolled DESC
                  LIMIT 10";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $byDepartment = [];
        while ($row = $result->fetch_assoc()) {
            $enrolled = (int) $row['enrolled'];
            $completed = (int) $row['completed'];
            $byDepartment[] = [
                'department_id' => (int) $row['department'],
                'department_name' => $row['department_name'],
                'enrolled' => $enrolled,
                'completed' => $completed,
                'completion_rate' => $enrolled > 0 ? round(($completed / $enrolled) * 100, 1) : 0
            ];
        }

        // Daily trend
        $dailyTrend = $this->getDailyTrend();

        // Monthly trend
        $monthlyTrend = $this->getMonthlyTrend();

        return [
            'total' => (int) $totals['total'],
            'this_week' => (int) $totals['this_week'],
            'this_month' => (int) $totals['this_month'],
            'growth_rate' => round($growthRate, 1),
            'by_source' => $bySource,
            'by_department' => $byDepartment,
            'daily_trend' => $dailyTrend,
            'monthly_trend' => $monthlyTrend
        ];
    }

    /**
     * Get daily enrollment/completion trend
     */
    private function getDailyTrend(): array
    {
        $query = "SELECT 
                    DATE(enrolled_at) as date,
                    COUNT(*) as enrollments,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completions
                  FROM course_enrollments
                  WHERE course_id = ? AND tenant_id = ? AND is_active = 1
                  AND enrolled_at >= ? AND enrolled_at <= ?
                  GROUP BY DATE(enrolled_at)
                  ORDER BY date ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iiss", $this->course_id, $this->tenant_id, $this->date_from, $this->date_to);
        $stmt->execute();
        $result = $stmt->get_result();

        $trend = [];
        while ($row = $result->fetch_assoc()) {
            $trend[] = [
                'date' => $row['date'],
                'enrollments' => (int) $row['enrollments'],
                'completions' => (int) $row['completions']
            ];
        }

        return $trend;
    }

    /**
     * Get monthly enrollment/completion trend
     */
    private function getMonthlyTrend(): array
    {
        $query = "SELECT 
                    DATE_FORMAT(enrolled_at, '%M') as month,
                    YEAR(enrolled_at) as year,
                    COUNT(*) as enrollments,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completions,
                    AVG(CASE WHEN final_score IS NOT NULL THEN final_score END) as average_score
                  FROM course_enrollments
                  WHERE course_id = ? AND tenant_id = ? AND is_active = 1
                  AND enrolled_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                  GROUP BY YEAR(enrolled_at), MONTH(enrolled_at), DATE_FORMAT(enrolled_at, '%M')
                  ORDER BY YEAR(enrolled_at), MONTH(enrolled_at)";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $trend = [];
        while ($row = $result->fetch_assoc()) {
            $trend[] = [
                'month' => $row['month'],
                'year' => (int) $row['year'],
                'enrollments' => (int) $row['enrollments'],
                'completions' => (int) $row['completions'],
                'average_score' => round((float) ($row['average_score'] ?? 0), 1)
            ];
        }

        return $trend;
    }

    /**
     * Get completion analytics
     */
    private function getCompletionAnalytics(): array
    {
        // Basic completion stats
        $query = "SELECT 
                    COUNT(*) as total_completed,
                    AVG(DATEDIFF(completed_at, enrolled_at)) as average_completion_time,
                    MIN(DATEDIFF(completed_at, enrolled_at)) as fastest_completion,
                    MAX(DATEDIFF(completed_at, enrolled_at)) as slowest_completion
                  FROM course_enrollments
                  WHERE course_id = ? AND tenant_id = ? AND is_active = 1 AND status = 'completed'";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();

        // Get total enrolled for rate calculation
        $query = "SELECT COUNT(*) as total FROM course_enrollments 
                  WHERE course_id = ? AND tenant_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];

        $completionRate = $total > 0 ? ((int) $stats['total_completed'] / $total) * 100 : 0;

        // By time range
        $query = "SELECT 
                    CASE 
                        WHEN DATEDIFF(completed_at, enrolled_at) <= 7 THEN '1-7 days'
                        WHEN DATEDIFF(completed_at, enrolled_at) <= 14 THEN '8-14 days'
                        WHEN DATEDIFF(completed_at, enrolled_at) <= 21 THEN '15-21 days'
                        WHEN DATEDIFF(completed_at, enrolled_at) <= 30 THEN '22-30 days'
                        ELSE '30+ days'
                    END as time_range,
                    COUNT(*) as count
                  FROM course_enrollments
                  WHERE course_id = ? AND tenant_id = ? AND is_active = 1 AND status = 'completed'
                  GROUP BY time_range
                  ORDER BY FIELD(time_range, '1-7 days', '8-14 days', '15-21 days', '22-30 days', '30+ days')";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $byTimeRange = [];
        $totalCompleted = (int) $stats['total_completed'];
        while ($row = $result->fetch_assoc()) {
            $count = (int) $row['count'];
            $byTimeRange[] = [
                'range' => $row['time_range'],
                'count' => $count,
                'percentage' => $totalCompleted > 0 ? round(($count / $totalCompleted) * 100, 1) : 0
            ];
        }

        // Completion funnel
        $funnel = $this->getCompletionFunnel();

        return [
            'total_completed' => (int) $stats['total_completed'],
            'completion_rate' => round($completionRate, 2),
            'average_completion_time' => (int) ($stats['average_completion_time'] ?? 0),
            'fastest_completion' => (int) ($stats['fastest_completion'] ?? 0),
            'slowest_completion' => (int) ($stats['slowest_completion'] ?? 0),
            'by_time_range' => $byTimeRange,
            'completion_funnel' => $funnel
        ];
    }

    /**
     * Get completion funnel data
     */
    private function getCompletionFunnel(): array
    {
        $query = "SELECT 
                    COUNT(*) as enrolled,
                    SUM(CASE WHEN progress_percentage > 0 THEN 1 ELSE 0 END) as started,
                    SUM(CASE WHEN progress_percentage >= 50 THEN 1 ELSE 0 END) as halfway,
                    SUM(CASE WHEN progress_percentage >= 80 THEN 1 ELSE 0 END) as almost_done,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
                  FROM course_enrollments
                  WHERE course_id = ? AND tenant_id = ? AND is_active = 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        return [
            'enrolled' => (int) $result['enrolled'],
            'started' => (int) $result['started'],
            'halfway' => (int) $result['halfway'],
            'almost_done' => (int) $result['almost_done'],
            'completed' => (int) $result['completed']
        ];
    }

    /**
     * Get engagement analytics
     */
    private function getEngagementAnalytics(): array
    {
        // Session stats
        $query = "SELECT 
                    SUM(time_spent_minutes) as total_time_spent,
                    AVG(session_duration) as average_session_duration,
                    COUNT(*) as total_sessions
                  FROM learning_sessions
                  WHERE course_id = ? AND tenant_id = ?
                  AND started_at >= ? AND started_at <= ?";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iiss", $this->course_id, $this->tenant_id, $this->date_from, $this->date_to);
        $stmt->execute();
        $sessions = $stmt->get_result()->fetch_assoc();

        // Unique learners
        $query = "SELECT COUNT(DISTINCT user_id) as unique_learners FROM learning_sessions
                  WHERE course_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $learners = $stmt->get_result()->fetch_assoc();

        $totalSessions = (int) ($sessions['total_sessions'] ?? 0);
        $uniqueLearners = (int) ($learners['unique_learners'] ?? 1);
        $avgSessionsPerLearner = $uniqueLearners > 0 ? $totalSessions / $uniqueLearners : 0;

        // Peak hours
        $peakHours = $this->getPeakHours();

        // Peak days
        $peakDays = $this->getPeakDays();

        // Content interactions
        $contentInteractions = $this->getContentInteractions();

        // Calculate engagement score (0-100)
        $engagementScore = $this->calculateEngagementScore();

        return [
            'total_time_spent' => (int) ($sessions['total_time_spent'] ?? 0),
            'average_session_duration' => round((float) ($sessions['average_session_duration'] ?? 0), 0),
            'total_sessions' => $totalSessions,
            'average_sessions_per_learner' => round($avgSessionsPerLearner, 1),
            'peak_hours' => $peakHours,
            'peak_days' => $peakDays,
            'content_interactions' => $contentInteractions,
            'engagement_score' => $engagementScore
        ];
    }

    /**
     * Get peak activity hours
     */
    private function getPeakHours(): array
    {
        $query = "SELECT 
                    HOUR(started_at) as hour,
                    COUNT(*) as sessions
                  FROM learning_sessions
                  WHERE course_id = ? AND tenant_id = ?
                  AND started_at >= ? AND started_at <= ?
                  GROUP BY HOUR(started_at)
                  ORDER BY hour";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iiss", $this->course_id, $this->tenant_id, $this->date_from, $this->date_to);
        $stmt->execute();
        $result = $stmt->get_result();

        $hours = [];
        $total = 0;
        $data = [];

        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
            $total += (int) $row['sessions'];
        }

        foreach ($data as $row) {
            $sessions = (int) $row['sessions'];
            $hours[] = [
                'hour' => (int) $row['hour'],
                'sessions' => $sessions,
                'percentage' => $total > 0 ? round(($sessions / $total) * 100, 1) : 0
            ];
        }

        return $hours;
    }

    /**
     * Get peak activity days
     */
    private function getPeakDays(): array
    {
        $query = "SELECT 
                    DAYNAME(started_at) as day,
                    COUNT(*) as sessions
                  FROM learning_sessions
                  WHERE course_id = ? AND tenant_id = ?
                  AND started_at >= ? AND started_at <= ?
                  GROUP BY DAYNAME(started_at), DAYOFWEEK(started_at)
                  ORDER BY DAYOFWEEK(started_at)";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iiss", $this->course_id, $this->tenant_id, $this->date_from, $this->date_to);
        $stmt->execute();
        $result = $stmt->get_result();

        $days = [];
        $total = 0;
        $data = [];

        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
            $total += (int) $row['sessions'];
        }

        foreach ($data as $row) {
            $sessions = (int) $row['sessions'];
            $days[] = [
                'day' => $row['day'],
                'sessions' => $sessions,
                'percentage' => $total > 0 ? round(($sessions / $total) * 100, 1) : 0
            ];
        }

        return $days;
    }

    /**
     * Get content interactions
     */
    private function getContentInteractions(): array
    {
        $interactions = [];

        // Video plays
        $query = "SELECT COUNT(*) as count, COUNT(DISTINCT user_id) as unique_users
                  FROM content_interactions
                  WHERE course_id = ? AND tenant_id = ? AND interaction_type = 'video_play'";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $interactions[] = [
            'type' => 'video_plays',
            'count' => (int) ($result['count'] ?? 0),
            'unique_users' => (int) ($result['unique_users'] ?? 0)
        ];

        // Document views
        $query = "SELECT COUNT(*) as count, COUNT(DISTINCT user_id) as unique_users
                  FROM content_interactions
                  WHERE course_id = ? AND tenant_id = ? AND interaction_type = 'document_view'";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $interactions[] = [
            'type' => 'document_views',
            'count' => (int) ($result['count'] ?? 0),
            'unique_users' => (int) ($result['unique_users'] ?? 0)
        ];

        // Quiz attempts
        $query = "SELECT COUNT(*) as count, COUNT(DISTINCT user_id) as unique_users
                  FROM assessment_attempts aa
                  JOIN assessments a ON aa.assessment_id = a.assessment_id
                  WHERE a.course_id = ? AND a.tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $interactions[] = [
            'type' => 'quiz_attempts',
            'count' => (int) ($result['count'] ?? 0),
            'unique_users' => (int) ($result['unique_users'] ?? 0)
        ];

        // Notes created
        $query = "SELECT COUNT(*) as count, COUNT(DISTINCT user_id) as unique_users
                  FROM notes n
                  JOIN lessons l ON n.lesson_id = l.lesson_id
                  JOIN modules m ON l.module_id = m.module_id
                  WHERE m.course_id = ? AND n.tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $interactions[] = [
            'type' => 'notes_created',
            'count' => (int) ($result['count'] ?? 0),
            'unique_users' => (int) ($result['unique_users'] ?? 0)
        ];

        // Discussions
        $query = "SELECT COUNT(*) as count, COUNT(DISTINCT user_id) as unique_users
                  FROM discussion_posts
                  WHERE course_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $interactions[] = [
            'type' => 'discussions',
            'count' => (int) ($result['count'] ?? 0),
            'unique_users' => (int) ($result['unique_users'] ?? 0)
        ];

        return $interactions;
    }

    /**
     * Calculate engagement score (0-100)
     */
    private function calculateEngagementScore(): int
    {
        // Get various engagement metrics
        $query = "SELECT 
                    AVG(ce.progress_percentage) as avg_progress,
                    AVG(ce.time_spent_minutes) as avg_time,
                    (SELECT AVG(final_score) FROM course_enrollments 
                     WHERE course_id = ? AND tenant_id = ? AND final_score IS NOT NULL) as avg_score
                  FROM course_enrollments ce
                  WHERE ce.course_id = ? AND ce.tenant_id = ? AND ce.is_active = 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iiii", $this->course_id, $this->tenant_id, $this->course_id, $this->tenant_id);
        $stmt->execute();
        $metrics = $stmt->get_result()->fetch_assoc();

        // Calculate score based on weighted factors
        $progressScore = min(100, (float) ($metrics['avg_progress'] ?? 0));
        $scoreScore = min(100, (float) ($metrics['avg_score'] ?? 0));

        // Normalize time (assume 3 hours is optimal engagement)
        $avgTime = (float) ($metrics['avg_time'] ?? 0);
        $timeScore = min(100, ($avgTime / 180) * 100);

        // Weighted average
        $engagementScore = ($progressScore * 0.4) + ($scoreScore * 0.4) + ($timeScore * 0.2);

        return (int) round($engagementScore);
    }

    /**
     * Get assessment analytics
     */
    private function getAssessmentAnalytics(): array
    {
        // Overall assessment stats
        $query = "SELECT 
                    COUNT(DISTINCT a.assessment_id) as total_assessments,
                    COUNT(aa.attempt_id) as total_attempts,
                    AVG(aa.score) as average_score,
                    MAX(aa.score) as highest_score,
                    MIN(aa.score) as lowest_score
                  FROM assessments a
                  LEFT JOIN assessment_attempts aa ON a.assessment_id = aa.assessment_id
                  WHERE a.course_id = ? AND a.tenant_id = ? AND a.is_active = 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();

        // Pass rate
        $query = "SELECT 
                    SUM(CASE WHEN aa.score >= a.pass_percentage THEN 1 ELSE 0 END) as passed,
                    COUNT(*) as total
                  FROM assessment_attempts aa
                  JOIN assessments a ON aa.assessment_id = a.assessment_id
                  WHERE a.course_id = ? AND a.tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $passData = $stmt->get_result()->fetch_assoc();
        $passRate = $passData['total'] > 0 ? ($passData['passed'] / $passData['total']) * 100 : 0;

        // Score distribution
        $scoreDistribution = $this->getScoreDistribution();

        // Individual assessments
        $assessments = $this->getAssessmentDetails();

        // Question analytics
        $questionAnalytics = $this->getQuestionAnalytics();

        return [
            'total_assessments' => (int) ($stats['total_assessments'] ?? 0),
            'total_attempts' => (int) ($stats['total_attempts'] ?? 0),
            'average_score' => round((float) ($stats['average_score'] ?? 0), 1),
            'pass_rate' => round($passRate, 1),
            'highest_score' => (int) ($stats['highest_score'] ?? 0),
            'lowest_score' => (int) ($stats['lowest_score'] ?? 0),
            'score_distribution' => $scoreDistribution,
            'assessments' => $assessments,
            'question_analytics' => $questionAnalytics
        ];
    }

    /**
     * Get score distribution
     */
    private function getScoreDistribution(): array
    {
        $query = "SELECT 
                    CASE 
                        WHEN aa.score <= 20 THEN '0-20'
                        WHEN aa.score <= 40 THEN '21-40'
                        WHEN aa.score <= 60 THEN '41-60'
                        WHEN aa.score <= 80 THEN '61-80'
                        ELSE '81-100'
                    END as score_range,
                    COUNT(*) as count
                  FROM assessment_attempts aa
                  JOIN assessments a ON aa.assessment_id = a.assessment_id
                  WHERE a.course_id = ? AND a.tenant_id = ?
                  GROUP BY score_range
                  ORDER BY FIELD(score_range, '0-20', '21-40', '41-60', '61-80', '81-100')";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $distribution = [];
        $total = 0;
        $data = [];

        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
            $total += (int) $row['count'];
        }

        // Ensure all ranges are present
        $ranges = ['0-20', '21-40', '41-60', '61-80', '81-100'];
        foreach ($ranges as $range) {
            $found = false;
            foreach ($data as $row) {
                if ($row['score_range'] === $range) {
                    $count = (int) $row['count'];
                    $distribution[] = [
                        'range' => $range,
                        'count' => $count,
                        'percentage' => $total > 0 ? round(($count / $total) * 100, 1) : 0
                    ];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $distribution[] = [
                    'range' => $range,
                    'count' => 0,
                    'percentage' => 0
                ];
            }
        }

        return $distribution;
    }

    /**
     * Get individual assessment details
     */
    private function getAssessmentDetails(): array
    {
        $query = "SELECT 
                    a.assessment_id, a.title, a.assessment_type, a.total_questions, a.pass_percentage,
                    COUNT(aa.attempt_id) as total_attempts,
                    AVG(aa.score) as average_score,
                    AVG(aa.time_taken_seconds) as average_time,
                    SUM(CASE WHEN aa.score >= a.pass_percentage THEN 1 ELSE 0 END) as passed_count
                  FROM assessments a
                  LEFT JOIN assessment_attempts aa ON a.assessment_id = aa.assessment_id
                  WHERE a.course_id = ? AND a.tenant_id = ? AND a.is_active = 1
                  GROUP BY a.assessment_id, a.title, a.assessment_type, a.total_questions, a.pass_percentage
                  ORDER BY a.assessment_id";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $assessments = [];
        while ($row = $result->fetch_assoc()) {
            $totalAttempts = (int) $row['total_attempts'];
            $passedCount = (int) $row['passed_count'];
            $assessments[] = [
                'assessment_id' => (int) $row['assessment_id'],
                'title' => $row['title'],
                'type' => $row['type'],
                'total_questions' => (int) $row['total_questions'],
                'total_attempts' => $totalAttempts,
                'average_score' => round((float) ($row['average_score'] ?? 0), 1),
                'pass_rate' => $totalAttempts > 0 ? round(($passedCount / $totalAttempts) * 100, 1) : 0,
                'average_time' => (int) ($row['average_time'] ?? 0)
            ];
        }

        return $assessments;
    }

    /**
     * Get question-level analytics
     */
    private function getQuestionAnalytics(): array
    {
        $query = "SELECT 
                    q.question_id, q.question_text,
                    COUNT(qa.answer_id) as total_answers,
                    SUM(CASE WHEN qa.is_correct = 1 THEN 1 ELSE 0 END) as correct_count,
                    AVG(qa.time_taken) as average_time,
                    (SUM(CASE WHEN qa.is_correct = 1 THEN 1 ELSE 0 END) / COUNT(qa.answer_id)) as correct_rate_calc
                  FROM questions q
                  JOIN assessments a ON q.assessment_id = a.assessment_id
                  LEFT JOIN question_answers qa ON q.question_id = qa.question_id
                  WHERE a.course_id = ? AND a.tenant_id = ? AND q.is_active = 1
                  GROUP BY q.question_id, q.question_text
                  HAVING COUNT(qa.answer_id) > 0
                  ORDER BY correct_rate_calc ASC
                  LIMIT 10";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $questions = [];
        while ($row = $result->fetch_assoc()) {
            $totalAnswers = (int) $row['total_answers'];
            $correctCount = (int) $row['correct_count'];
            $correctRate = $totalAnswers > 0 ? ($correctCount / $totalAnswers) * 100 : 0;

            // Determine difficulty
            $difficulty = 'easy';
            if ($correctRate < 50) {
                $difficulty = 'hard';
            } elseif ($correctRate < 75) {
                $difficulty = 'medium';
            }

            // Get most selected wrong answer
            $wrongAnswer = $this->getMostSelectedWrongAnswer((int) $row['question_id']);

            $questions[] = [
                'question_id' => (int) $row['question_id'],
                'question_text' => $row['question_text'],
                'correct_rate' => round($correctRate, 1),
                'average_time' => (int) ($row['average_time'] ?? 0),
                'difficulty' => $difficulty,
                'most_selected_wrong' => $wrongAnswer
            ];
        }

        return $questions;
    }

    /**
     * Get most commonly selected wrong answer for a question
     */
    private function getMostSelectedWrongAnswer(int $questionId): ?string
    {
        $query = "SELECT o.option_text, COUNT(*) as count
                  FROM question_answers qa
                  JOIN question_options o ON qa.selected_option_id = o.option_id
                  WHERE qa.question_id = ? AND qa.is_correct = 0
                  GROUP BY o.option_id
                  ORDER BY count DESC
                  LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $questionId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        return $result ? $result['option_text'] : null;
    }

    /**
     * Get module analytics
     */
    private function getModuleAnalytics(): array
    {
        $query = "SELECT 
                    m.module_id, m.title,
                    (SELECT COUNT(*) FROM lessons WHERE module_id = m.module_id AND is_active = 1) as total_lessons
                  FROM modules m
                  WHERE m.course_id = ? AND m.tenant_id = ? AND m.is_active = 1
                  ORDER BY m.module_id";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $modules = [];
        $orderIndex = 0;
        while ($row = $result->fetch_assoc()) {
            $moduleId = (int) $row['module_id'];
            $moduleStats = $this->getModuleStats($moduleId);
            $lessons = $this->getLessonAnalytics($moduleId);

            $modules[] = [
                'module_id' => $moduleId,
                'title' => $row['title'],
                'order_index' => $orderIndex++,
                'total_lessons' => (int) $row['total_lessons'],
                'completion_rate' => $moduleStats['completion_rate'],
                'average_time_spent' => $moduleStats['average_time_spent'],
                'average_score' => $moduleStats['average_score'],
                'drop_off_rate' => $moduleStats['drop_off_rate'],
                'engagement_score' => $moduleStats['engagement_score'],
                'lessons' => $lessons
            ];
        }

        return $modules;
    }

    /**
     * Get statistics for a specific module
     */
    private function getModuleStats(int $moduleId): array
    {
        $query = "SELECT 
                    AVG(mp.progress_percentage) as avg_progress,
                    AVG(mp.time_spent_minutes) as avg_time,
                    AVG(mp.score) as avg_score
                  FROM module_progress mp
                  WHERE mp.module_id = ? AND mp.tenant_id = ?";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $moduleId, $this->tenant_id);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();

        // Calculate drop-off rate (users who started but didn't complete)
        $query = "SELECT 
                    SUM(CASE WHEN progress_percentage > 0 THEN 1 ELSE 0 END) as started,
                    SUM(CASE WHEN progress_percentage = 100 THEN 1 ELSE 0 END) as completed
                  FROM module_progress
                  WHERE module_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $moduleId, $this->tenant_id);
        $stmt->execute();
        $dropOff = $stmt->get_result()->fetch_assoc();

        $started = (int) ($dropOff['started'] ?? 0);
        $completed = (int) ($dropOff['completed'] ?? 0);
        $dropOffRate = $started > 0 ? (($started - $completed) / $started) * 100 : 0;

        // Engagement score based on completion and time
        $avgProgress = (float) ($stats['avg_progress'] ?? 0);
        $engagementScore = (int) min(100, $avgProgress * 1.1);

        return [
            'completion_rate' => round((float) ($stats['avg_progress'] ?? 0), 1),
            'average_time_spent' => (int) ($stats['avg_time'] ?? 0),
            'average_score' => $stats['avg_score'] !== null ? round((float) $stats['avg_score'], 1) : null,
            'drop_off_rate' => round($dropOffRate, 1),
            'engagement_score' => $engagementScore
        ];
    }

    /**
     * Get lesson analytics for a module
     */
    private function getLessonAnalytics(int $moduleId): array
    {
        $query = "SELECT 
                    l.lesson_id, l.title, l.content_type as type,
                    AVG(lp.progress_percentage) as completion_rate,
                    AVG(lp.time_spent_seconds / 60) as avg_time_spent,
                    AVG(lp.replay_count) as avg_replays
                  FROM lessons l
                  LEFT JOIN lesson_progress lp ON l.lesson_id = lp.lesson_id
                  WHERE l.module_id = ? AND l.is_active = 1
                  GROUP BY l.lesson_id, l.title, l.content_type
                  ORDER BY l.lesson_id";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $moduleId);
        $stmt->execute();
        $result = $stmt->get_result();

        $lessons = [];
        while ($row = $result->fetch_assoc()) {
            $lessons[] = [
                'lesson_id' => (int) $row['lesson_id'],
                'title' => $row['title'],
                'type' => $row['type'],
                'completion_rate' => round((float) ($row['completion_rate'] ?? 0), 1),
                'average_time_spent' => round((float) ($row['avg_time_spent'] ?? 0), 0),
                'replay_rate' => round((float) ($row['avg_replays'] ?? 0) * 10, 1),
                'drop_off_point' => null // Would need video analytics to calculate
            ];
        }

        return $lessons;
    }

    /**
     * Get learner analytics
     */
    private function getLearnerAnalytics(): array
    {
        return [
            'top_performers' => $this->getTopPerformers(),
            'at_risk_learners' => $this->getAtRiskLearners(),
            'recent_completions' => $this->getRecentCompletions(),
            'learner_segments' => $this->getLearnerSegments()
        ];
    }

    /**
     * Get top performing learners
     */
    private function getTopPerformers(): array
    {
        $query = "SELECT 
                    u.id as user_id, CONCAT(u.first_name, ' ', u.last_name) as full_name, 
                    u.email, u.avatar, d.department_name as department,
                    ce.progress_percentage as progress, ce.final_score as average_score,
                    ce.time_spent_minutes as time_spent, ce.completed_at
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  LEFT JOIN departments d ON u.department = d.department_id
                  WHERE ce.course_id = ? AND ce.tenant_id = ? AND ce.is_active = 1
                  AND ce.status = 'completed'
                  ORDER BY ce.final_score DESC, ce.time_spent_minutes ASC
                  LIMIT 10";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $performers = [];
        $rank = 1;
        while ($row = $result->fetch_assoc()) {
            $performers[] = [
                'user_id' => (int) $row['user_id'],
                'full_name' => $row['full_name'],
                'email' => $row['email'],
                'avatar_url' => $row['avatar'],
                'department' => $row['department'] ?? 'N/A',
                'progress' => (int) $row['progress'],
                'average_score' => round((float) ($row['average_score'] ?? 0), 1),
                'time_spent' => (int) $row['time_spent'],
                'completed_at' => $row['completed_at'],
                'rank' => $rank++
            ];
        }

        return $performers;
    }

    /**
     * Get at-risk learners
     */
    private function getAtRiskLearners(): array
    {
        $query = "SELECT 
                    u.id as user_id, CONCAT(u.first_name, ' ', u.last_name) as full_name,
                    u.email, u.avatar, d.department_name as department,
                    ce.progress_percentage as progress, ce.last_activity_at,
                    DATEDIFF(NOW(), ce.last_activity_at) as days_inactive
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  LEFT JOIN departments d ON u.department = d.department_id
                  WHERE ce.course_id = ? AND ce.tenant_id = ? AND ce.is_active = 1
                  AND ce.status IN ('not_started', 'in_progress')
                  AND (
                    DATEDIFF(NOW(), ce.last_activity_at) > 7
                    OR (ce.progress_percentage < 50 AND DATEDIFF(NOW(), ce.enrolled_at) > 14)
                  )
                  ORDER BY days_inactive DESC
                  LIMIT 20";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $atRisk = [];
        while ($row = $result->fetch_assoc()) {
            $daysInactive = (int) $row['days_inactive'];
            $progress = (int) $row['progress'];

            // Determine risk level and reason
            $riskLevel = 'low';
            $riskReason = '';

            if ($daysInactive > 14 || $progress < 25) {
                $riskLevel = 'high';
                $riskReason = "No activity in {$daysInactive} days, only {$progress}% complete";
            } elseif ($daysInactive > 7 || $progress < 50) {
                $riskLevel = 'medium';
                $riskReason = "Inactive for {$daysInactive} days with {$progress}% progress";
            } else {
                $riskReason = "Slow progress, may need support";
            }

            $atRisk[] = [
                'user_id' => (int) $row['user_id'],
                'full_name' => $row['full_name'],
                'email' => $row['email'],
                'avatar_url' => $row['avatar'],
                'department' => $row['department'] ?? 'N/A',
                'progress' => $progress,
                'last_activity' => $row['last_activity_at'],
                'days_inactive' => $daysInactive,
                'risk_level' => $riskLevel,
                'risk_reason' => $riskReason
            ];
        }

        return $atRisk;
    }

    /**
     * Get recent completions
     */
    private function getRecentCompletions(): array
    {
        $query = "SELECT 
                    u.id as user_id, CONCAT(u.first_name, ' ', u.last_name) as full_name,
                    u.avatar, ce.completed_at, ce.final_score,
                    DATEDIFF(ce.completed_at, ce.enrolled_at) as time_to_complete,
                    c.certificate_id
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  LEFT JOIN certificates c ON c.user_id = u.id AND c.course_id = ce.course_id
                  WHERE ce.course_id = ? AND ce.tenant_id = ? AND ce.status = 'completed'
                  ORDER BY ce.completed_at DESC
                  LIMIT 10";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $completions = [];
        while ($row = $result->fetch_assoc()) {
            $completions[] = [
                'user_id' => (int) $row['user_id'],
                'full_name' => $row['full_name'],
                'avatar_url' => $row['avatar'],
                'completed_at' => $row['completed_at'],
                'final_score' => (int) ($row['final_score'] ?? 0),
                'time_to_complete' => (int) $row['time_to_complete'],
                'certificate_id' => $row['certificate_id'] ? (int) $row['certificate_id'] : null
            ];
        }

        return $completions;
    }

    /**
     * Get learner segments
     */
    private function getLearnerSegments(): array
    {
        $query = "SELECT 
                    status as segment,
                    COUNT(*) as count,
                    AVG(final_score) as average_score
                  FROM course_enrollments
                  WHERE course_id = ? AND tenant_id = ? AND is_active = 1
                  GROUP BY status";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $segments = [];
        $total = 0;
        $data = [];

        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
            $total += (int) $row['count'];
        }

        // Ensure all segments are represented
        $allSegments = ['completed', 'in_progress', 'not_started', 'dropped'];
        foreach ($allSegments as $seg) {
            $found = false;
            foreach ($data as $row) {
                if ($row['segment'] === $seg) {
                    $count = (int) $row['count'];
                    $segments[] = [
                        'segment' => $seg,
                        'count' => $count,
                        'percentage' => $total > 0 ? round(($count / $total) * 100, 2) : 0,
                        'average_score' => $row['average_score'] !== null ? round((float) $row['average_score'], 1) : null
                    ];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $segments[] = [
                    'segment' => $seg,
                    'count' => 0,
                    'percentage' => 0,
                    'average_score' => null
                ];
            }
        }

        return $segments;
    }

    /**
     * Get trend data for charts
     */
    private function getTrendData(): array
    {
        $dataPoints = [];

        if ($this->period === 'daily') {
            $query = "SELECT 
                        DATE(ce.enrolled_at) as date,
                        DATE_FORMAT(ce.enrolled_at, '%b %d') as label,
                        COUNT(*) as enrollments,
                        SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completions,
                        COUNT(DISTINCT CASE WHEN ce.last_activity_at >= DATE(ce.enrolled_at) 
                              AND ce.last_activity_at < DATE_ADD(DATE(ce.enrolled_at), INTERVAL 1 DAY) 
                              THEN ce.user_id END) as active_users,
                        AVG(CASE WHEN ce.final_score IS NOT NULL THEN ce.final_score END) as average_score,
                        SUM(ce.time_spent_minutes) as time_spent
                      FROM course_enrollments ce
                      WHERE ce.course_id = ? AND ce.tenant_id = ? AND ce.is_active = 1
                      AND ce.enrolled_at >= ? AND ce.enrolled_at <= ?
                      GROUP BY DATE(ce.enrolled_at)
                      ORDER BY date";
        } elseif ($this->period === 'weekly') {
            $query = "SELECT 
                        DATE(DATE_SUB(ce.enrolled_at, INTERVAL WEEKDAY(ce.enrolled_at) DAY)) as date,
                        CONCAT('Week ', WEEK(ce.enrolled_at)) as label,
                        COUNT(*) as enrollments,
                        SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completions,
                        COUNT(DISTINCT ce.user_id) as active_users,
                        AVG(CASE WHEN ce.final_score IS NOT NULL THEN ce.final_score END) as average_score,
                        SUM(ce.time_spent_minutes) as time_spent
                      FROM course_enrollments ce
                      WHERE ce.course_id = ? AND ce.tenant_id = ? AND ce.is_active = 1
                      AND ce.enrolled_at >= ? AND ce.enrolled_at <= ?
                      GROUP BY YEARWEEK(ce.enrolled_at)
                      ORDER BY date";
        } else {
            $query = "SELECT 
                        DATE(DATE_FORMAT(ce.enrolled_at, '%Y-%m-01')) as date,
                        DATE_FORMAT(ce.enrolled_at, '%b %Y') as label,
                        COUNT(*) as enrollments,
                        SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completions,
                        COUNT(DISTINCT ce.user_id) as active_users,
                        AVG(CASE WHEN ce.final_score IS NOT NULL THEN ce.final_score END) as average_score,
                        SUM(ce.time_spent_minutes) as time_spent
                      FROM course_enrollments ce
                      WHERE ce.course_id = ? AND ce.tenant_id = ? AND ce.is_active = 1
                      AND ce.enrolled_at >= ? AND ce.enrolled_at <= ?
                      GROUP BY YEAR(ce.enrolled_at), MONTH(ce.enrolled_at)
                      ORDER BY date";
        }

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iiss", $this->course_id, $this->tenant_id, $this->date_from, $this->date_to);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $dataPoints[] = [
                'label' => $row['label'],
                'date' => $row['date'],
                'enrollments' => (int) $row['enrollments'],
                'completions' => (int) $row['completions'],
                'active_users' => (int) $row['active_users'],
                'average_score' => round((float) ($row['average_score'] ?? 0), 1),
                'time_spent' => (int) $row['time_spent']
            ];
        }

        return [
            'period' => $this->period,
            'data_points' => $dataPoints
        ];
    }
}