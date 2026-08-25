<?php

/**
 * Learner Report API Endpoint
 * Personal learning analytics and reports
 *
 * All requests are POST with JSON body containing auth + action + params
 * 
 * Actions:
 * - overview             - Get learning overview stats
 * - course-progress      - Get detailed course progress report
 * - daily-activity       - Get daily activity for chart (last N days)
 * - weekly-activity      - Get weekly activity summary
 * - monthly-activity     - Get monthly activity summary
 * - category-breakdown   - Get learning by category
 * - quiz-performance     - Get quiz history and performance
 * - certificates         - Get all certificates
 * - recent-activities    - Get recent learning activities
 * - skills               - Get skills acquired
 * - full-report          - Get complete report for export
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Token, X-User-ID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../class/LearnerReport.php';

$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required = ['token', 'user_id', 'tenant_id'];
foreach ($required as $field) {
    if (!isset($input[$field])) {
        echo json_encode([
            'code' => 400,
            'status' => 'error',
            'message' => "Missing required field: {$field}"
        ]);
        exit();
    }
}

$db = new Database();
$conn = $db->getConnection();

$token = $input['token'];
$userId = (int) $input['user_id'];
$tenant_id = (int) $input['tenant_id'];

// Verify user
$token_check = $db->verify_user($token, $userId);
if ($token_check != 100) {
    http_response_code(401);
    $db->send_response(401, "failed", [], "Unauthorized request");
    exit;
}

// Initialize report manager
$report = new LearnerReport($conn);
$report->setTenant($tenant_id);
$report->setUser($userId);

// Get action from input
$action = $input['action'] ?? '';

try {
    switch ($action) {
        // ==========================================
        // OVERVIEW
        // ==========================================

        case 'overview':
            $data = $report->getOverview();
            $db->send_response(200, 'success', $data, 'Overview retrieved');
            break;

        // ==========================================
        // COURSE PROGRESS
        // ==========================================

        case 'course-progress':
            $data = $report->getCourseProgressReport();
            $db->send_response(200, 'success', $data, 'Course progress retrieved');
            break;

        // ==========================================
        // ACTIVITY CHARTS
        // ==========================================

        case 'daily-activity':
            $days = (int) ($input['days'] ?? 30);
            $data = $report->getDailyActivity($days);
            $db->send_response(200, 'success', $data, 'Daily activity retrieved');
            break;

        case 'weekly-activity':
            $weeks = (int) ($input['weeks'] ?? 12);
            $data = $report->getWeeklyActivity($weeks);
            $db->send_response(200, 'success', $data, 'Weekly activity retrieved');
            break;

        case 'monthly-activity':
            $months = (int) ($input['months'] ?? 12);
            $data = $report->getMonthlyActivity($months);
            $db->send_response(200, 'success', $data, 'Monthly activity retrieved');
            break;

        // ==========================================
        // CATEGORY BREAKDOWN
        // ==========================================

        case 'category-breakdown':
            $data = $report->getCategoryBreakdown();
            $db->send_response(200, 'success', $data, 'Category breakdown retrieved');
            break;

        // ==========================================
        // QUIZ PERFORMANCE
        // ==========================================

        case 'quiz-performance':
            $limit = (int) ($input['limit'] ?? 20);
            $data = $report->getQuizPerformance($limit);
            $db->send_response(200, 'success', $data, 'Quiz performance retrieved');
            break;

        // ==========================================
        // CERTIFICATES
        // ==========================================

        case 'certificates':
            $data = $report->getCertificates();
            $db->send_response(200, 'success', $data, 'Certificates retrieved');
            break;

        // ==========================================
        // RECENT ACTIVITIES
        // ==========================================

        case 'recent-activities':
            $limit = (int) ($input['limit'] ?? 20);
            $data = $report->getRecentActivities($limit);
            $db->send_response(200, 'success', $data, 'Recent activities retrieved');
            break;

        // ==========================================
        // SKILLS
        // ==========================================

        case 'skills':
            $data = $report->getSkillsAcquired();
            $db->send_response(200, 'success', $data, 'Skills retrieved');
            break;

        // ==========================================
        // FULL REPORT EXPORT
        // ==========================================

        case 'full-report':
            $data = $report->getFullReport();
            $db->send_response(200, 'success', $data, 'Full report generated');
            break;

        default:
            $db->send_response(400, 'error', null,
                'Invalid action. Available: overview, course-progress, daily-activity, ' .
                'weekly-activity, monthly-activity, category-breakdown, quiz-performance, ' .
                'certificates, recent-activities, skills, full-report');
    }

} catch (Exception $e) {
    error_log('Learner Report API Error: ' . $e->getMessage());
    $db->send_response(500, 'error', null, 'Server error: ' . $e->getMessage());
}
