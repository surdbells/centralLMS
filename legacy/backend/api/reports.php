<?php

/**
 * Reports API Endpoint
 * Handles all report execution, catalog, export, and filter lookups
 *
 * Multi-Tenant LMS Report Module - Phase 1
 *
 * All requests are POST with JSON body: { action, token, user_id, tenant_id, ...params }
 *
 * Actions:
 * - get-categories         Get report categories
 * - get-catalog            Get available reports (optional: category, audience)
 * - run-report             Execute a report (required: report_key)
 * - export-report          Export report as CSV (required: report_key)
 * - get-courses            Courses dropdown
 * - get-departments        Departments dropdown
 * - get-teams              Teams dropdown
 * - get-branches           Branches dropdown
 * - get-learners           Learners search (optional: search)
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
require_once __DIR__ . '/../class/reporting/ReportService.php';
require_once __DIR__ . '/../class/User.php';

$db = new Database();
$conn = $db->getConnection();

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        $db->send_response(400, 'error', null, 'Invalid JSON input');
        exit;
    }

    // Authentication
    $token = $input['token'] ?? null;
    $user_id = (int) ($input['user_id'] ?? 0);
    $tenant_id = (int) ($input['tenant_id'] ?? 0);
    $action = $input['action'] ?? '';

    if (!$token || !$tenant_id || !$user_id) {
        $db->send_response(401, 'error', null, 'Authentication required');
        exit;
    }

    // Verify token
    $token_check = $db->verify_user($token, $user_id);
    if ($token_check != 100) {
        $db->send_response(401, 'error', null, 'Invalid or expired token');
        exit;
    }

    // Initialize Report Service
    $reportService = new ReportService($conn);
    $reportService->setTenant($tenant_id);
    $reportService->setUser($user_id);

    switch ($action) {

        // ==========================================
        // CATALOG & METADATA
        // ==========================================

        case 'get-categories':
            $data = $reportService->getCategories();
            $db->send_response(200, 'success', $data, 'Categories loaded');
            break;

        case 'get-catalog':
            $category = $input['category'] ?? null;
            $audience = $input['audience'] ?? null;
            $data = $reportService->getCatalog($category, $audience);
            $db->send_response(200, 'success', $data, 'Catalog loaded');
            break;

        // ==========================================
        // REPORT EXECUTION
        // ==========================================

        case 'run-report':
            $reportKey = $input['report_key'] ?? '';
            if (!$reportKey) {
                $db->send_response(400, 'error', null, 'report_key required');
                break;
            }

            $params = [
                // Scope
                'course_id' => $input['course_id'] ?? null,
                'department_id' => $input['department_id'] ?? null,
                'team_id' => $input['team_id'] ?? null,
                'branch_id' => $input['branch_id'] ?? null,
                'learner_id' => $input['learner_id'] ?? null,
                // Date range
                'date_from' => $input['date_from'] ?? null,
                'date_to' => $input['date_to'] ?? null,
                // Pagination
                'page' => $input['page'] ?? 1,
                'per_page' => $input['per_page'] ?? 25,
                // Sorting
                'sort_by' => $input['sort_by'] ?? null,
                'sort_dir' => $input['sort_dir'] ?? 'DESC',
                // Extra filters passed through
                'filters' => $input['filters'] ?? [],
                // Convenience params (also placed in filters)
                'status' => $input['status'] ?? null,
                'compliance_status' => $input['compliance_status'] ?? null,
                'is_mandatory' => $input['is_mandatory'] ?? null,
                'search' => $input['search'] ?? null,
                'group_by' => $input['group_by'] ?? null,
                'days_ahead' => $input['days_ahead'] ?? null,
            ];

            // Merge flat convenience params into filters
            foreach (['status', 'compliance_status', 'is_mandatory', 'search', 'group_by', 'days_ahead'] as $k) {
                if ($params[$k] !== null && !isset($params['filters'][$k])) {
                    $params['filters'][$k] = $params[$k];
                }
            }

            $result = $reportService->runReport($reportKey, $params);

            if (isset($result['error'])) {
                $db->send_response(400, 'error', null, $result['error']);
            } else {
                $db->send_response(200, 'success', $result, 'Report generated');
            }
            break;

        // ==========================================
        // EXPORT
        // ==========================================

        case 'export-report':
            $reportKey = $input['report_key'] ?? '';
            if (!$reportKey) {
                $db->send_response(400, 'error', null, 'report_key required');
                break;
            }

            $params = [
                'course_id' => $input['course_id'] ?? null,
                'department_id' => $input['department_id'] ?? null,
                'team_id' => $input['team_id'] ?? null,
                'branch_id' => $input['branch_id'] ?? null,
                'learner_id' => $input['learner_id'] ?? null,
                'date_from' => $input['date_from'] ?? null,
                'date_to' => $input['date_to'] ?? null,
                'filters' => $input['filters'] ?? [],
                'status' => $input['status'] ?? null,
                'compliance_status' => $input['compliance_status'] ?? null,
                'search' => $input['search'] ?? null,
                'group_by' => $input['group_by'] ?? null,
                'days_ahead' => $input['days_ahead'] ?? null,
            ];

            // Merge flat convenience params into filters
            foreach (['status', 'compliance_status', 'search', 'group_by', 'days_ahead'] as $k) {
                if ($params[$k] !== null && !isset($params['filters'][$k])) {
                    $params['filters'][$k] = $params[$k];
                }
            }

            $csv = $reportService->exportReport($reportKey, $params);

            if ($csv === null) {
                $db->send_response(400, 'error', null, 'Report not found or not exportable');
                break;
            }

            // Return CSV as download
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $reportKey . '_' . date('Y-m-d_His') . '.csv"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            echo $csv;
            exit;

        // ==========================================
        // FILTER LOOKUPS (for dropdowns)
        // ==========================================

        case 'get-courses':
            $data = $reportService->getCourses();
            $db->send_response(200, 'success', $data, 'Courses loaded');
            break;

        case 'get-departments':
            $data = $reportService->getDepartments();
            $db->send_response(200, 'success', $data, 'Departments loaded');
            break;

        case 'get-teams':
            $data = $reportService->getTeams();
            $db->send_response(200, 'success', $data, 'Teams loaded');
            break;

        case 'get-branches':
            $data = $reportService->getBranches();
            $db->send_response(200, 'success', $data, 'Branches loaded');
            break;

        case 'get-learners':
            $search = $input['search'] ?? '';
            $data = $reportService->getLearners($search);
            $db->send_response(200, 'success', $data, 'Learners loaded');
            break;

        // ==========================================
        // DEFAULT
        // ==========================================

        default:
            $db->send_response(400, 'error', null,
                'Invalid action: "' . $action . '". Available actions: ' .
                'get-categories, get-catalog, run-report, export-report, ' .
                'get-courses, get-departments, get-teams, get-branches, get-learners'
            );
    }

} catch (Exception $e) {
    error_log('Reports API Error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    $db->send_response(500, 'error', null, 'Server error: ' . $e->getMessage());
}