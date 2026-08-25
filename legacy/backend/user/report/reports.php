<?php

/**
 * Reports API Endpoint
 * Handles all report requests
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../class/reports/ReportService.php';

// Authenticate request
$auth = authenticate();
if (!$auth['success']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user = $auth['user'];
$tenant_id = $user['tenant_id'];
$user_id = $user['id'];
$user_role = $user['role'];

// Initialize database and service
$db = new Database();
$conn = $db->getConnection();
$reportService = new ReportService($conn);
$reportService->setContext($tenant_id, $user_id);

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        // Get report catalog
        case 'catalog':
            $category = $_GET['category'] ?? null;
            $audience = determineAudience($user_role);
            $reports = $reportService->getReportCatalog($category, $audience);
            respond(['success' => true, 'data' => $reports]);
            break;

        // Get report categories
        case 'categories':
            $categories = $reportService->getCategories();
            respond(['success' => true, 'data' => $categories]);
            break;

        // Run a specific report
        case 'run':
            $reportKey = $_GET['report'] ?? $_POST['report'] ?? null;
            if (!$reportKey) {
                respond(['success' => false, 'message' => 'Report key required'], 400);
            }

            // Check access
            if (!canAccessReport($reportKey, $user_role, $reportService)) {
                respond(['success' => false, 'message' => 'Access denied'], 403);
            }

            // Gather parameters
            $params = getReportParams();

            $result = $reportService->runReport($reportKey, $params);
            respond($result);
            break;

        // Export report to CSV
        case 'export':
            $reportKey = $_GET['report'] ?? null;
            if (!$reportKey) {
                respond(['success' => false, 'message' => 'Report key required'], 400);
            }

            if (!canAccessReport($reportKey, $user_role, $reportService)) {
                respond(['success' => false, 'message' => 'Access denied'], 403);
            }

            $params = getReportParams();
            $result = $reportService->exportReport($reportKey, $params);

            if ($result['success']) {
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
                echo $result['content'];
                exit;
            } else {
                respond($result, 400);
            }
            break;

        // Get dashboard summary
        case 'dashboard':
            if (!in_array($user_role, ['admin', 'hr'])) {
                respond(['success' => false, 'message' => 'Access denied'], 403);
            }
            $summary = $reportService->getDashboardSummary();
            respond(['success' => true, 'data' => $summary]);
            break;

        // Learner dashboard
        case 'my-dashboard':
            $reportService->setContext($tenant_id, $user_id);
            $result = $reportService->runReport('my_learning_summary');
            respond($result);
            break;

        // Schedule report
        case 'schedule':
            if ($method !== 'POST') {
                respond(['success' => false, 'message' => 'POST required'], 405);
            }

            $data = json_decode(file_get_contents('php://input'), true);
            $reportKey = $data['report'] ?? null;
            $schedule = $data['schedule'] ?? null;

            if (!$reportKey || !$schedule) {
                respond(['success' => false, 'message' => 'Report and schedule required'], 400);
            }

            if (!canAccessReport($reportKey, $user_role, $reportService)) {
                respond(['success' => false, 'message' => 'Access denied'], 403);
            }

            $result = $reportService->scheduleReport($reportKey, $schedule);
            respond(['success' => true, 'data' => $result]);
            break;

        // Get saved/scheduled reports
        case 'saved':
            $saved = getSavedReports($conn, $user_id, $tenant_id);
            respond(['success' => true, 'data' => $saved]);
            break;

        // Save report configuration
        case 'save':
            if ($method !== 'POST') {
                respond(['success' => false, 'message' => 'POST required'], 405);
            }

            $data = json_decode(file_get_contents('php://input'), true);
            $result = saveReportConfig($conn, $user_id, $tenant_id, $data);
            respond($result);
            break;

        default:
            respond(['success' => false, 'message' => 'Invalid action'], 400);
    }
} catch (Exception $e) {
    error_log('Report API Error: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()], 500);
}

/**
 * Helper functions
 */

function respond(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function determineAudience(string $role): string {
    return match($role) {
        'superadmin', 'admin' => 'admin',
        'hr', 'hr_manager' => 'hr',
        'manager' => 'manager',
        'executive' => 'executive',
        default => 'learner'
    };
}

function canAccessReport(string $reportKey, string $role, ReportService $service): bool {
    $catalog = $service->getReportCatalog();
    foreach ($catalog as $report) {
        if ($report['report_key'] === $reportKey) {
            $audience = determineAudience($role);
            // Admin can access all
            if ($role === 'admin' || $role === 'superadmin') return true;
            // Learner reports for everyone
            if ($report['category'] === 'learner') return true;
            // Check if role's audience is allowed
            return in_array($audience, $report['audience']);
        }
    }
    return false;
}

function getReportParams(): array {
    $params = [];

    // Date filters
    if (isset($_GET['date_from'])) $params['date_from'] = $_GET['date_from'];
    if (isset($_GET['date_to'])) $params['date_to'] = $_GET['date_to'];

    // Pagination
    if (isset($_GET['page'])) $params['page'] = (int) $_GET['page'];
    if (isset($_GET['per_page'])) $params['per_page'] = (int) $_GET['per_page'];

    // Sorting
    if (isset($_GET['sort_by'])) $params['sort_by'] = $_GET['sort_by'];
    if (isset($_GET['sort_dir'])) $params['sort_dir'] = $_GET['sort_dir'];

    // Report-specific params
    $specificParams = ['user_id', 'course_id', 'assessment_id', 'manager_id', 'days', 'days_ahead', 'months', 'month', 'period'];
    foreach ($specificParams as $param) {
        if (isset($_GET[$param])) {
            $params[$param] = is_numeric($_GET[$param]) ? (int) $_GET[$param] : $_GET[$param];
        }
    }

    // POST body params
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        if ($body) {
            $params = array_merge($params, $body);
        }
    }

    return $params;
}

function getSavedReports(mysqli $conn, int $user_id, int $tenant_id): array {
    $query = "SELECT * FROM saved_reports WHERE user_id = ? AND tenant_id = ? ORDER BY created_at DESC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $user_id, $tenant_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function saveReportConfig(mysqli $conn, int $user_id, int $tenant_id, array $data): array {
    $query = "INSERT INTO saved_reports (user_id, tenant_id, report_key, name, params, schedule, created_at)
              VALUES (?, ?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($query);
    $report_key = $data['report_key'];
    $name = $data['name'];
    $params = json_encode($data['params'] ?? []);
    $schedule = json_encode($data['schedule'] ?? null);
    $stmt->bind_param("iissss", $user_id, $tenant_id, $report_key, $name, $params, $schedule);

    if ($stmt->execute()) {
        return ['success' => true, 'id' => $conn->insert_id];
    }
    return ['success' => false, 'message' => 'Failed to save report'];
}
