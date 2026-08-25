<?php
/**
 * Statistics API Endpoint
 * Multi-Tenant LMS - Dashboard Statistics
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config/database.php';

class StatisticsAPI
{
    private $conn;
    private $tenantId;
    private $startDate;
    private $endDate;

    public function __construct($conn)
    {
        $this->conn = $conn;
    }

    public function handleRequest()
    {
        try {
            // Validate tenant_id
            if (!isset($_GET['tenant_id']) || !is_numeric($_GET['tenant_id'])) {
                $this->sendError('tenant_id is required', 400);
                return;
            }

            $this->tenantId = (int)$_GET['tenant_id'];
            
            // Set date range (default: last 6 months)
            $this->endDate = $_GET['end_date'] ?? date('Y-m-d');
            $this->startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-6 months'));

            // Handle specific sections or full dashboard
            $section = $_GET['section'] ?? 'all';
            $action = $_GET['action'] ?? null;

            // Handle export
            if ($action === 'export') {
                $this->handleExport($_GET['format'] ?? 'csv');
                return;
            }

            // Return data based on section
            switch ($section) {
                case 'overview':
                    $this->sendResponse($this->getOverviewStats());
                    break;
                case 'trends':
                    $period = $_GET['period'] ?? 'month';
                    $this->sendResponse($this->getTrends($period));
                    break;
                case 'top_courses':
                    $limit = (int)($_GET['limit'] ?? 5);
                    $this->sendResponse($this->getTopCourses($limit));
                    break;
                case 'recent_activity':
                    $limit = (int)($_GET['limit'] ?? 10);
                    $this->sendResponse($this->getRecentActivity($limit));
                    break;
                case 'all':
                default:
                    $this->sendResponse($this->getFullDashboard());
            }
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 500);
        }
    }

    private function getFullDashboard(): array
    {
        return [
            'overview' => $this->getOverviewStats(),
            'trends' => $this->getTrends('month'),
            'top_courses' => $this->getTopCourses(5),
            'recent_activity' => $this->getRecentActivity(10),
            'user_engagement' => $this->getUserEngagement(),
            'quiz_performance' => $this->getQuizPerformance()
        ];
    }

    private function getOverviewStats(): array
    {
        // Total Users
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as count FROM users 
            WHERE tenant_id = ? AND is_active = 1
        ");
        $stmt->bind_param('i', $this->tenantId);
        $stmt->execute();
        $totalUsers = $stmt->get_result()->fetch_assoc()['count'];

        // Total Courses
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as count FROM courses 
            WHERE tenant_id = ? AND is_active = 1
        ");
        $stmt->bind_param('i', $this->tenantId);
        $stmt->execute();
        $totalCourses = $stmt->get_result()->fetch_assoc()['count'];

        // Total Enrollments
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as count FROM enrollments 
            WHERE tenant_id = ? AND is_active = 1
        ");
        $stmt->bind_param('i', $this->tenantId);
        $stmt->execute();
        $totalEnrollments = $stmt->get_result()->fetch_assoc()['count'];

        // Total Completions
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as count FROM enrollments 
            WHERE tenant_id = ? AND is_active = 1 AND status = 'completed'
        ");
        $stmt->bind_param('i', $this->tenantId);
        $stmt->execute();
        $totalCompletions = $stmt->get_result()->fetch_assoc()['count'];

        // Active Users Today
        $stmt = $this->conn->prepare("
            SELECT COUNT(DISTINCT user_id) as count FROM user_activity_logs 
            WHERE tenant_id = ? AND DATE(created_at) = CURDATE()
        ");
        $stmt->bind_param('i', $this->tenantId);
        $stmt->execute();
        $activeToday = $stmt->get_result()->fetch_assoc()['count'];

        // Revenue This Month
        $stmt = $this->conn->prepare("
            SELECT COALESCE(SUM(amount), 0) as total FROM payments 
            WHERE tenant_id = ? 
            AND status = 'completed'
            AND MONTH(created_at) = MONTH(CURDATE()) 
            AND YEAR(created_at) = YEAR(CURDATE())
        ");
        $stmt->bind_param('i', $this->tenantId);
        $stmt->execute();
        $revenueThisMonth = (float)$stmt->get_result()->fetch_assoc()['total'];

        return [
            'total_users' => (int)$totalUsers,
            'total_courses' => (int)$totalCourses,
            'total_enrollments' => (int)$totalEnrollments,
            'total_completions' => (int)$totalCompletions,
            'active_users_today' => (int)$activeToday,
            'revenue_this_month' => $revenueThisMonth
        ];
    }

    private function getTrends(string $period): array
    {
        $groupBy = $this->getDateGrouping($period);
        $dateFormat = $this->getDateFormat($period);

        // Enrollments by period
        $stmt = $this->conn->prepare("
            SELECT DATE_FORMAT(created_at, ?) as period, COUNT(*) as count
            FROM enrollments
            WHERE tenant_id = ? 
            AND created_at BETWEEN ? AND ?
            AND is_active = 1
            GROUP BY period
            ORDER BY MIN(created_at)
        ");
        $stmt->bind_param('siss', $dateFormat, $this->tenantId, $this->startDate, $this->endDate);
        $stmt->execute();
        $enrollmentsByMonth = [];
        while ($row = $stmt->get_result()->fetch_assoc()) {
            $enrollmentsByMonth[] = ['month' => $row['period'], 'count' => (int)$row['count']];
        }

        // Completions by period
        $stmt = $this->conn->prepare("
            SELECT DATE_FORMAT(completed_at, ?) as period, COUNT(*) as count
            FROM enrollments
            WHERE tenant_id = ? 
            AND completed_at BETWEEN ? AND ?
            AND status = 'completed'
            AND is_active = 1
            GROUP BY period
            ORDER BY MIN(completed_at)
        ");
        $stmt->bind_param('siss', $dateFormat, $this->tenantId, $this->startDate, $this->endDate);
        $stmt->execute();
        $completionsByMonth = [];
        while ($row = $stmt->get_result()->fetch_assoc()) {
            $completionsByMonth[] = ['month' => $row['period'], 'count' => (int)$row['count']];
        }

        // Revenue by period
        $stmt = $this->conn->prepare("
            SELECT DATE_FORMAT(created_at, ?) as period, COALESCE(SUM(amount), 0) as amount
            FROM payments
            WHERE tenant_id = ? 
            AND created_at BETWEEN ? AND ?
            AND status = 'completed'
            GROUP BY period
            ORDER BY MIN(created_at)
        ");
        $stmt->bind_param('siss', $dateFormat, $this->tenantId, $this->startDate, $this->endDate);
        $stmt->execute();
        $revenueByMonth = [];
        while ($row = $stmt->get_result()->fetch_assoc()) {
            $revenueByMonth[] = ['month' => $row['period'], 'amount' => (float)$row['amount']];
        }

        return [
            'enrollments_by_month' => $enrollmentsByMonth,
            'completions_by_month' => $completionsByMonth,
            'revenue_by_month' => $revenueByMonth
        ];
    }

    private function getTopCourses(int $limit): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                c.course_id,
                c.title,
                COUNT(e.enrollment_id) as enrollments,
                ROUND(
                    (SUM(CASE WHEN e.status = 'completed' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 
                    1
                ) as completion_rate,
                COALESCE(AVG(r.rating), 0) as rating
            FROM courses c
            LEFT JOIN enrollments e ON c.course_id = e.course_id AND e.tenant_id = c.tenant_id
            LEFT JOIN course_reviews r ON c.course_id = r.course_id AND r.tenant_id = c.tenant_id
            WHERE c.tenant_id = ? AND c.is_active = 1
            GROUP BY c.course_id, c.title
            ORDER BY enrollments DESC
            LIMIT ?
        ");
        $stmt->bind_param('ii', $this->tenantId, $limit);
        $stmt->execute();
        
        $courses = [];
        while ($row = $stmt->get_result()->fetch_assoc()) {
            $courses[] = [
                'course_id' => (int)$row['course_id'],
                'title' => $row['title'],
                'enrollments' => (int)$row['enrollments'],
                'completion_rate' => (float)$row['completion_rate'],
                'rating' => round((float)$row['rating'], 1)
            ];
        }
        
        return $courses;
    }

    private function getRecentActivity(int $limit): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                a.log_id as id,
                CONCAT(u.first_name, ' ', u.last_name) as user_name,
                a.action,
                a.target_type as target,
                a.created_at as timestamp
            FROM user_activity_logs a
            JOIN users u ON a.user_id = u.user_id AND a.tenant_id = u.tenant_id
            WHERE a.tenant_id = ?
            ORDER BY a.created_at DESC
            LIMIT ?
        ");
        $stmt->bind_param('ii', $this->tenantId, $limit);
        $stmt->execute();
        
        $activities = [];
        while ($row = $stmt->get_result()->fetch_assoc()) {
            $activities[] = [
                'id' => (int)$row['id'],
                'user_name' => $row['user_name'],
                'action' => $row['action'],
                'target' => $row['target'],
                'timestamp' => $row['timestamp']
            ];
        }
        
        return $activities;
    }

    private function getUserEngagement(): array
    {
        // Daily active users (last 7 days)
        $dailyActiveUsers = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $stmt = $this->conn->prepare("
                SELECT COUNT(DISTINCT user_id) as count
                FROM user_activity_logs
                WHERE tenant_id = ? AND DATE(created_at) = ?
            ");
            $stmt->bind_param('is', $this->tenantId, $date);
            $stmt->execute();
            $dailyActiveUsers[] = (int)$stmt->get_result()->fetch_assoc()['count'];
        }

        // Weekly active users
        $stmt = $this->conn->prepare("
            SELECT COUNT(DISTINCT user_id) as count
            FROM user_activity_logs
            WHERE tenant_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $stmt->bind_param('i', $this->tenantId);
        $stmt->execute();
        $weeklyActiveUsers = (int)$stmt->get_result()->fetch_assoc()['count'];

        // Average session duration (in minutes)
        $stmt = $this->conn->prepare("
            SELECT COALESCE(AVG(session_duration), 0) as avg_duration
            FROM user_sessions
            WHERE tenant_id = ? AND ended_at IS NOT NULL
        ");
        $stmt->bind_param('i', $this->tenantId);
        $stmt->execute();
        $avgSessionDuration = round((float)$stmt->get_result()->fetch_assoc()['avg_duration'] / 60, 1);

        // Average courses per user
        $stmt = $this->conn->prepare("
            SELECT COALESCE(AVG(course_count), 0) as avg_courses
            FROM (
                SELECT user_id, COUNT(DISTINCT course_id) as course_count
                FROM enrollments
                WHERE tenant_id = ? AND is_active = 1
                GROUP BY user_id
            ) as user_courses
        ");
        $stmt->bind_param('i', $this->tenantId);
        $stmt->execute();
        $avgCoursesPerUser = round((float)$stmt->get_result()->fetch_assoc()['avg_courses'], 1);

        return [
            'daily_active_users' => $dailyActiveUsers,
            'weekly_active_users' => $weeklyActiveUsers,
            'avg_session_duration' => $avgSessionDuration,
            'avg_courses_per_user' => $avgCoursesPerUser
        ];
    }

    private function getQuizPerformance(): array
    {
        // Total attempts
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as count FROM quiz_attempts
            WHERE tenant_id = ?
        ");
        $stmt->bind_param('i', $this->tenantId);
        $stmt->execute();
        $totalAttempts = (int)$stmt->get_result()->fetch_assoc()['count'];

        // Average score
        $stmt = $this->conn->prepare("
            SELECT COALESCE(AVG(score), 0) as avg_score
            FROM quiz_attempts
            WHERE tenant_id = ?
        ");
        $stmt->bind_param('i', $this->tenantId);
        $stmt->execute();
        $avgScore = round((float)$stmt->get_result()->fetch_assoc()['avg_score'], 1);

        // Pass rate (assuming passing score is 60%)
        $stmt = $this->conn->prepare("
            SELECT 
                ROUND((SUM(CASE WHEN score >= 60 THEN 1 ELSE 0 END) / COUNT(*)) * 100, 1) as pass_rate
            FROM quiz_attempts
            WHERE tenant_id = ?
        ");
        $stmt->bind_param('i', $this->tenantId);
        $stmt->execute();
        $passRate = (float)$stmt->get_result()->fetch_assoc()['pass_rate'];

        // Score distribution
        $scoreRanges = [
            ['min' => 0, 'max' => 20, 'label' => '0-20%'],
            ['min' => 21, 'max' => 40, 'label' => '21-40%'],
            ['min' => 41, 'max' => 60, 'label' => '41-60%'],
            ['min' => 61, 'max' => 80, 'label' => '61-80%'],
            ['min' => 81, 'max' => 100, 'label' => '81-100%']
        ];

        $scoreDistribution = [];
        foreach ($scoreRanges as $range) {
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) as count
                FROM quiz_attempts
                WHERE tenant_id = ? AND score >= ? AND score <= ?
            ");
            $stmt->bind_param('iii', $this->tenantId, $range['min'], $range['max']);
            $stmt->execute();
            $count = (int)$stmt->get_result()->fetch_assoc()['count'];
            $scoreDistribution[] = [
                'range' => $range['label'],
                'count' => $count
            ];
        }

        return [
            'total_attempts' => $totalAttempts,
            'avg_score' => $avgScore,
            'pass_rate' => $passRate,
            'score_distribution' => $scoreDistribution
        ];
    }

    private function handleExport(string $format): void
    {
        $data = $this->getFullDashboard();
        
        switch ($format) {
            case 'csv':
                $this->exportCSV($data);
                break;
            case 'pdf':
                $this->exportPDF($data);
                break;
            case 'excel':
                $this->exportExcel($data);
                break;
            default:
                $this->sendError('Invalid export format', 400);
        }
    }

    private function exportCSV(array $data): void
    {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="lms-report-' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Overview
        fputcsv($output, ['LMS Statistics Report - ' . date('Y-m-d')]);
        fputcsv($output, []);
        fputcsv($output, ['Overview']);
        foreach ($data['overview'] as $key => $value) {
            fputcsv($output, [ucwords(str_replace('_', ' ', $key)), $value]);
        }
        
        // Top Courses
        fputcsv($output, []);
        fputcsv($output, ['Top Courses']);
        fputcsv($output, ['Title', 'Enrollments', 'Completion Rate', 'Rating']);
        foreach ($data['top_courses'] as $course) {
            fputcsv($output, [
                $course['title'],
                $course['enrollments'],
                $course['completion_rate'] . '%',
                $course['rating']
            ]);
        }
        
        fclose($output);
        exit;
    }

    private function exportPDF(array $data): void
    {
        // For PDF export, you would typically use a library like TCPDF or FPDF
        // This is a placeholder that returns JSON with a note
        $this->sendError('PDF export requires TCPDF library installation', 501);
    }

    private function exportExcel(array $data): void
    {
        // For Excel export, you would typically use PhpSpreadsheet
        // This is a placeholder that returns JSON with a note
        $this->sendError('Excel export requires PhpSpreadsheet library installation', 501);
    }

    private function getDateGrouping(string $period): string
    {
        return match($period) {
            'week' => 'YEARWEEK(created_at)',
            'year' => 'YEAR(created_at)',
            default => 'DATE_FORMAT(created_at, "%Y-%m")'
        };
    }

    private function getDateFormat(string $period): string
    {
        return match($period) {
            'week' => '%Y-W%v',
            'year' => '%Y',
            default => '%b %Y'
        };
    }

    private function sendResponse(array $data): void
    {
        echo json_encode($data, JSON_PRETTY_PRINT);
    }

    private function sendError(string $message, int $code): void
    {
        http_response_code($code);
        echo json_encode(['error' => $message, 'code' => $code]);
    }
}

// Initialize and run
$conn = getDbConnection();
$api = new StatisticsAPI($conn);
$api->handleRequest();
$conn->close();
