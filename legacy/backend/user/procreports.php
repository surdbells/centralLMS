<?php
/**
 * Reports & Analytics API Endpoint
 * Multi-Tenant LMS - Admin Portal
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../class/Reports.php';

$db = new Database();
$conn = $db->getConnection();

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) { $db->send_response(400, 'error', null, 'Invalid JSON'); exit; }

    $token = $input['token'] ?? null;
    $user_id = (int) ($input['user_id'] ?? 0);
    $tenant_id = (int) ($input['tenant_id'] ?? 0);
    $action = $input['action'] ?? '';

    if (!$token || !$tenant_id || !$user_id) { $db->send_response(401, 'error', null, 'Auth required'); exit; }
    if ($db->verify_user($token, $user_id) != 100) { $db->send_response(401, 'error', null, 'Invalid token'); exit; }

    $reports = new Reports($conn);
    $reports->setTenant($tenant_id);
    $reports->setUser($user_id);

    switch ($action) {
        // DASHBOARD
        case 'get-dashboard-stats':
            $stats = $reports->getDashboardStats();
            $db->send_response(200, 'success', $stats, 'Retrieved');
            break;
        case 'get-attempts-over-time':
            $data = $reports->getAttemptsOverTime((int) ($input['days'] ?? 30));
            $db->send_response(200, 'success', $data, 'Retrieved');
            break;
        case 'get-top-performers':
            $data = $reports->getTopPerformers((int) ($input['limit'] ?? 10));
            $db->send_response(200, 'success', $data, 'Retrieved');
            break;

        // ASSESSMENT REPORTS
        case 'get-assessment-summary':
            $data = $reports->getAssessmentSummary((int) ($input['assessment_id'] ?? 0), $input);
            isset($data['error']) ? $db->send_response(404, 'error', null, $data['error']) : $db->send_response(200, 'success', $data, 'Retrieved');
            break;
        case 'get-assessment-attempts':
            $data = $reports->getAssessmentAttempts((int) ($input['assessment_id'] ?? 0), $input);
            $db->send_response(200, 'success', $data, 'Retrieved');
            break;

        // QUESTION ANALYSIS
        case 'calculate-question-stats':
            $result = $reports->calculateQuestionStatistics((int) ($input['assessment_id'] ?? 0));
            $db->send_response(200, 'success', $result, "Calculated {$result['calculated']} questions");
            break;
        case 'get-question-analysis':
            $data = $reports->getQuestionAnalysis((int) ($input['assessment_id'] ?? 0));
            $db->send_response(200, 'success', $data, 'Retrieved');
            break;

        // LEARNER REPORTS
        case 'get-learner-progress':
            $data = $reports->getLearnerProgress((int) ($input['learner_id'] ?? 0));
            $db->send_response(200, 'success', $data, 'Retrieved');
            break;
        case 'get-learner-transcript':
            $data = $reports->getLearnerTranscript((int) ($input['learner_id'] ?? 0));
            $db->send_response(200, 'success', $data, 'Retrieved');
            break;
        case 'calculate-learner-analytics':
            $result = $reports->calculateLearnerAnalytics((int) ($input['learner_id'] ?? 0));
            $db->send_response(200, 'success', $result, 'Calculated');
            break;

        // PROCTORING REPORTS
        case 'get-proctoring-report':
            $data = $reports->getProctoringReport((int) ($input['assessment_id'] ?? 0));
            $db->send_response(200, 'success', $data, 'Retrieved');
            break;

        // REPORT DEFINITIONS
        case 'get-report-definitions':
            $data = $reports->getReportDefinitions();
            $db->send_response(200, 'success', $data, 'Retrieved');
            break;

        // REPORT GENERATION
        case 'generate-report':
            $result = $reports->generateReport((int) ($input['report_id'] ?? 0), $input);
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', $result['success'] ? $result : null, $result['message'] ?? 'Generated');
            break;
        case 'get-generated-reports':
            $data = $reports->getGeneratedReports((int) ($input['limit'] ?? 20));
            $db->send_response(200, 'success', $data, 'Retrieved');
            break;

        default:
            $db->send_response(400, 'error', null, 'Invalid action');
    }
} catch (Exception $e) {
    error_log('Reports API Error: ' . $e->getMessage());
    $db->send_response(500, 'error', null, 'Server error: ' . $e->getMessage());
}
