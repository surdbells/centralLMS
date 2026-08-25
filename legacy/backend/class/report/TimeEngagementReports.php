<?php

namespace report;

require_once __DIR__ . '/BaseReport.php';

/**
 * Time & Engagement Reports
 * Reports related to learning time and user engagement
 */
class TimeEngagementReports extends BaseReport
{

    public function generate(): array
    {
        return $this->timeSpentReport();
    }

    /**
     * Report 44: Time Spent Report
     */
    public function timeSpentReport(): array
    {
        $dateFilter = $this->buildDateFilter('ls.started_at');

        $query = "SELECT u.id as user_id, CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, d.department_name,
                    COUNT(DISTINCT ls.session_id) as sessions,
                    SUM(ls.duration_minutes) as total_minutes,
                    AVG(ls.duration_minutes) as avg_session_minutes,
                    COUNT(DISTINCT ls.course_id) as courses_accessed,
                    COUNT(DISTINCT DATE(ls.started_at)) as active_days
                  FROM users u
                  LEFT JOIN departments d ON u.department = d.department_id
                  LEFT JOIN learning_sessions ls ON u.id = ls.user_id AND ls.tenant_id = ? $dateFilter
                  WHERE u.tenant_id = ? AND u.is_active = 1
                  GROUP BY u.id, u.first_name, u.last_name, u.email, d.department_name
                  HAVING total_minutes > 0
                  ORDER BY total_minutes DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iiii", $this->tenant_id, $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $totalMins = (int)$row['total_minutes'];
            $activeDays = (int)$row['active_days'];
            $data[] = [
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'department' => $row['department_name'] ?? 'N/A',
                'sessions' => (int)$row['sessions'],
                'total_time' => $this->formatDuration($totalMins),
                'total_hours' => round($totalMins / 60, 1),
                'avg_session' => $this->formatDuration((int)($row['avg_session_minutes'] ?? 0)),
                'courses_accessed' => (int)$row['courses_accessed'],
                'active_days' => $activeDays,
                'avg_daily_time' => $activeDays > 0 ? $this->formatDuration((int)($totalMins / $activeDays)) : '0m'
            ];
        }

        return [
            'report_name' => 'Time Spent Report',
            'data' => $data,
            'meta' => $this->buildMeta(count($data)),
            'summary' => $this->getTimeSummaryStats()
        ];
    }

    /**
     * Report 45: Learning Hours by Department
     */
    public function learningHoursByDepartment(): array
    {
        $dateFilter = $this->buildDateFilter('ls.started_at');

        $query = "SELECT d.department_id, d.department_name,
                    COUNT(DISTINCT u.id) as total_users,
                    COUNT(DISTINCT ls.user_id) as active_learners,
                    SUM(ls.duration_minutes) as total_minutes,
                    AVG(ls.duration_minutes) as avg_session,
                    COUNT(ls.session_id) as total_sessions
                  FROM departments d
                  LEFT JOIN users u ON d.department_id = u.department AND u.tenant_id = ? AND u.is_active = 1
                  LEFT JOIN learning_sessions ls ON u.id = ls.user_id $dateFilter
                  WHERE d.tenant_id = ?
                  GROUP BY d.department_id, d.department_name
                  ORDER BY total_minutes DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iiii", $this->tenant_id, $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $totalMins = (int)$row['total_minutes'];
            $totalUsers = (int)$row['total_users'];
            $activeUsers = (int)$row['active_learners'];

            $data[] = [
                'department_id' => $row['department'],
                'department_name' => $row['department_name'],
                'total_users' => $totalUsers,
                'active_learners' => $activeUsers,
                'participation_rate' => $totalUsers > 0 ? round(($activeUsers / $totalUsers) * 100, 1) : 0,
                'total_hours' => round($totalMins / 60, 1),
                'total_time' => $this->formatDuration($totalMins),
                'avg_per_user' => $activeUsers > 0 ? $this->formatDuration((int)($totalMins / $activeUsers)) : '0m',
                'total_sessions' => (int)$row['total_sessions'],
                'avg_session' => $this->formatDuration((int)($row['avg_session'] ?? 0))
            ];
        }

        return [
            'report_name' => 'Learning Hours by Department',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 46: Peak Usage Report
     */
    public function peakUsageReport(): array
    {
        $dateFilter = $this->buildDateFilter('ls.started_at');

        // By hour of day
        $hourQuery = "SELECT HOUR(ls.started_at) as hour,
                        COUNT(*) as sessions,
                        COUNT(DISTINCT ls.user_id) as unique_users,
                        SUM(ls.duration_minutes) as total_minutes
                      FROM learning_sessions ls
                      WHERE ls.tenant_id = ? $dateFilter
                      GROUP BY HOUR(ls.started_at)
                      ORDER BY hour";

        $stmt = $this->conn->prepare($hourQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $hourlyData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // By day of week
        $dayQuery = "SELECT DAYOFWEEK(ls.started_at) as day_num,
                       DAYNAME(ls.started_at) as day_name,
                       COUNT(*) as sessions,
                       COUNT(DISTINCT ls.user_id) as unique_users,
                       SUM(ls.duration_minutes) as total_minutes
                     FROM learning_sessions ls
                     WHERE ls.tenant_id = ? $dateFilter
                     GROUP BY DAYOFWEEK(ls.started_at), DAYNAME(ls.started_at)
                     ORDER BY day_num";

        $stmt = $this->conn->prepare($dayQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $dailyData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Format hourly data
        $byHour = [];
        foreach ($hourlyData as $row) {
            $hour = (int)$row['hour'];
            $byHour[] = [
                'hour' => $hour,
                'time_label' => sprintf('%02d:00', $hour),
                'sessions' => (int)$row['sessions'],
                'unique_users' => (int)$row['unique_users'],
                'total_time' => $this->formatDuration((int)$row['total_minutes'])
            ];
        }

        // Format daily data
        $byDay = [];
        foreach ($dailyData as $row) {
            $byDay[] = [
                'day_num' => (int)$row['day_num'],
                'day_name' => $row['day_name'],
                'sessions' => (int)$row['sessions'],
                'unique_users' => (int)$row['unique_users'],
                'total_time' => $this->formatDuration((int)$row['total_minutes'])
            ];
        }

        // Find peaks
        $peakHour = !empty($byHour) ? array_reduce($byHour, fn($a, $b) => ($a['sessions'] ?? 0) > $b['sessions'] ? $a : $b) : null;
        $peakDay = !empty($byDay) ? array_reduce($byDay, fn($a, $b) => ($a['sessions'] ?? 0) > $b['sessions'] ? $a : $b) : null;

        return [
            'report_name' => 'Peak Usage Report',
            'by_hour' => $byHour,
            'by_day' => $byDay,
            'peak_hour' => $peakHour ? $peakHour['time_label'] : 'N/A',
            'peak_day' => $peakDay ? $peakDay['day_name'] : 'N/A'
        ];
    }

    /**
     * Report 47: Session Duration Report
     */
    public function sessionDurationReport(): array
    {
        $dateFilter = $this->buildDateFilter('ls.started_at');

        $query = "SELECT c.course_id, c.title as course_title,
                    COUNT(ls.session_id) as total_sessions,
                    AVG(ls.duration_minutes) as avg_duration,
                    MIN(ls.duration_minutes) as min_duration,
                    MAX(ls.duration_minutes) as max_duration,
                    STDDEV(ls.duration_minutes) as std_deviation
                  FROM courses c
                  LEFT JOIN learning_sessions ls ON c.course_id = ls.course_id $dateFilter
                  WHERE c.tenant_id = ? AND c.is_active = 1
                  GROUP BY c.course_id, c.title
                  HAVING total_sessions > 0
                  ORDER BY avg_duration DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'course_id' => (int)$row['course_id'],
                'course_title' => $row['course_title'],
                'total_sessions' => (int)$row['total_sessions'],
                'avg_duration' => $this->formatDuration((int)($row['avg_duration'] ?? 0)),
                'min_duration' => $this->formatDuration((int)($row['min_duration'] ?? 0)),
                'max_duration' => $this->formatDuration((int)($row['max_duration'] ?? 0)),
                'avg_minutes' => round((float)($row['avg_duration'] ?? 0), 1),
                'consistency' => $this->getConsistencyLabel((float)($row['std_deviation'] ?? 0))
            ];
        }

        return [
            'report_name' => 'Session Duration Report',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 48: Monthly Training Hours
     */
    public function monthlyTrainingHours(int $months = 12): array
    {
        $query = "SELECT DATE_FORMAT(ls.started_at, '%Y-%m') as month,
                    COUNT(DISTINCT ls.user_id) as active_users,
                    COUNT(ls.session_id) as total_sessions,
                    SUM(ls.time_spent_minutes) as total_minutes,
                    AVG(ls.time_spent_minutes) as avg_session
                  FROM learning_sessions ls
                  WHERE ls.tenant_id = ?
                  AND ls.started_at >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
                  GROUP BY DATE_FORMAT(ls.started_at, '%Y-%m')
                  ORDER BY month DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->tenant_id, $months);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        $prevHours = null;
        while ($row = $result->fetch_assoc()) {
            $totalMins = (int)$row['total_minutes'];
            $hours = round($totalMins / 60, 1);

            // Calculate trend
            $trend = 0;
            if ($prevHours !== null && $prevHours > 0) {
                $trend = round((($hours - $prevHours) / $prevHours) * 100, 1);
            }
            $prevHours = $hours;

            $data[] = [
                'month' => $row['month'],
                'month_label' => date('M Y', strtotime($row['month'] . '-01')),
                'active_users' => (int)$row['active_users'],
                'total_sessions' => (int)$row['total_sessions'],
                'total_hours' => $hours,
                'total_time' => $this->formatDuration($totalMins),
                'avg_session' => $this->formatDuration((int)($row['avg_session'] ?? 0)),
                'trend_percent' => $trend
            ];
        }

        // Reverse to show chronological order
        $data = array_reverse($data);

        return [
            'report_name' => 'Monthly Training Hours',
            'months_shown' => $months,
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 49: Engagement Score Report
     */
    public function engagementScoreReport(): array
    {
        $query = "SELECT u.id as user_id, CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, d.department_name,
                    COUNT(DISTINCT ls.session_id) as sessions_30d,
                    SUM(ls.time_spent_minutes) as time_30d,
                    COUNT(DISTINCT DATE(ls.started_at)) as active_days_30d,
                    COUNT(DISTINCT ce.course_id) as courses_enrolled,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as courses_completed,
                    COUNT(DISTINCT aa.attempt_id) as assessments_taken,
                    COALESCE(SUM(up.points), 0) as points_earned
                  FROM users u
                  LEFT JOIN departments d ON u.department = d.department_id
                  LEFT JOIN learning_sessions ls ON u.id = ls.user_id 
                    AND ls.started_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                  LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.is_active = 1
                  LEFT JOIN assessment_attempts aa ON u.id = aa.user_id 
                    AND aa.completed_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                  LEFT JOIN user_points up ON u.id = up.user_id AND up.tenant_id = ?
                  WHERE u.tenant_id = ? AND u.is_active = 1
                  GROUP BY u.id, u.first_name, u.last_name, u.email, d.department_name
                  ORDER BY sessions_30d DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iiii", $this->tenant_id, $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            // Calculate engagement score (0-100)
            $sessions = (int)$row['sessions_30d'];
            $timeMins = (int)$row['time_30d'];
            $activeDays = (int)$row['active_days_30d'];
            $enrolled = (int)$row['courses_enrolled'];
            $completed = (int)$row['courses_completed'];
            $assessments = (int)$row['assessments_taken'];

            // Weighted scoring
            $sessionScore = min(25, $sessions * 2.5); // Max 25 for 10+ sessions
            $timeScore = min(25, ($timeMins / 60) * 2.5); // Max 25 for 10+ hours
            $consistencyScore = min(20, $activeDays * 2); // Max 20 for 10+ active days
            $completionScore = $enrolled > 0 ? min(20, ($completed / $enrolled) * 20) : 0; // Max 20
            $assessmentScore = min(10, $assessments * 2); // Max 10 for 5+ assessments

            $engagementScore = round($sessionScore + $timeScore + $consistencyScore + $completionScore + $assessmentScore);

            $data[] = [
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'department' => $row['department_name'] ?? 'N/A',
                'sessions_30d' => $sessions,
                'time_30d' => $this->formatDuration($timeMins),
                'active_days_30d' => $activeDays,
                'courses_completed' => $completed,
                'assessments_taken' => $assessments,
                'points_earned' => (int)$row['points_earned'],
                'engagement_score' => $engagementScore,
                'engagement_level' => $this->getEngagementLevel($engagementScore)
            ];
        }

        // Sort by engagement score
        usort($data, fn($a, $b) => $b['engagement_score'] <=> $a['engagement_score']);

        return [
            'report_name' => 'Engagement Score Report',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    private function getTimeSummaryStats(): array
    {
        $query = "SELECT 
                    COUNT(DISTINCT user_id) as active_learners,
                    SUM(time_spent_minutes) as total_minutes,
                    AVG(time_spent_minutes) as avg_session,
                    COUNT(*) as total_sessions
                  FROM learning_sessions
                  WHERE tenant_id = ?
                  AND started_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        return [
            'active_learners_30d' => (int)($row['active_learners'] ?? 0),
            'total_hours_30d' => round((int)($row['total_minutes'] ?? 0) / 60, 1),
            'avg_session_duration' => $this->formatDuration((int)($row['avg_session'] ?? 0)),
            'total_sessions_30d' => (int)($row['total_sessions'] ?? 0)
        ];
    }

    private function getConsistencyLabel(float $stdDev): string
    {
        if ($stdDev < 5) return 'Very Consistent';
        if ($stdDev < 15) return 'Consistent';
        if ($stdDev < 30) return 'Variable';
        return 'Highly Variable';
    }

    private function getEngagementLevel(int $score): string
    {
        if ($score >= 80) return 'Highly Engaged';
        if ($score >= 60) return 'Engaged';
        if ($score >= 40) return 'Moderately Engaged';
        if ($score >= 20) return 'Low Engagement';
        return 'Minimal Engagement';
    }

    public function getSummary(): array
    {
        return $this->getTimeSummaryStats();
    }
}
