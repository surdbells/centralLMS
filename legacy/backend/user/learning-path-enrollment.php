<?php

/**
 * Learning Path Enrollment API Endpoint
 * Admin endpoint for bulk enrollment management
 * All requests are POST with JSON body containing auth + action + params
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
require_once __DIR__ . '/../class/LearningPathEnrollment.php';
require_once __DIR__ . '/../class/User.php';

$input = json_decode(file_get_contents('php://input'), true);

$action = "" ?? null;
$token = "" ?? null;
$userId = "" ?? null;
$tenant_id = "" ?? null;

$search = "" ?? null;
$department_id = "" ?? null;
$team_id = "" ?? null;
$branch_id = "" ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $input['token'];
    $userId = (int) $input['user_id'];
    $tenant_id = (int) $input['tenant_id'];

    $db = new Database();
    $conn = $db->getConnection();
    $user = new User($conn);
    $token_check = $db->verify_user($token, $userId);
    if ($token_check != 100 || !$user->is_admin($userId)) {
        http_response_code(401);
        $db->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }
    $action = $input['action'] ?? '';

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? null;
    $token = $_GET['token'] ?? null;
    $userId = $_GET['user_id'] ?? null;
    $tenant_id = $_GET['tenant_id'] ?? null;

    $search = $_GET['search'] ?? null;
    $department_id = $_GET['department_id'] ?? null;
    $team_id = $_GET['team_id'] ?? null;
    $branch_id = $_GET['branch_id'] ?? null;
    $path_id = $_GET['path_id'] ?? null;

    $db = new Database();
    $conn = $db->getConnection();
    $user = new User($conn);
    $token_check = $db->verify_user($token, $userId);
    if ($token_check != 100 || !$user->is_admin($userId)) {
        http_response_code(401);
        $db->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }

} else {
    http_response_code(405);
    echo json_encode([
        'status'  => false,
        'message' => 'Method Not Allowed'
    ]);
    exit;
}

// Initialize enrollment manager
$enrollmentManager = new LearningPathEnrollment($conn);
$enrollmentManager->setTenant($tenant_id);

try {
    switch ($action) {
        // ==========================================
        // LOOKUP ENDPOINTS
        // ==========================================

        case 'departments':
            $departments = $enrollmentManager->getDepartments();
            $db->send_response(200, 'success', $departments, 'Departments retrieved');
            break;

        case 'teams':
            $teams = $enrollmentManager->getTeams();
            $db->send_response(200, 'success', $teams, 'Teams retrieved');
            break;

        case 'branches':
            $branches = $enrollmentManager->getBranches();
            $db->send_response(200, 'success', $branches, 'Branches retrieved');
            break;

        case 'users':
            $users = $enrollmentManager->getUsers($search, $department_id, $team_id, $branch_id);
            $db->send_response(200, 'success', $users, 'Users retrieved');
            break;

        case 'paths':
            $search = $input['search'] ?? $_GET['search'] ?? null;
            $status = $input['status'] ?? $_GET['status'] ?? 'published';

            $paths = $enrollmentManager->getLearningPaths($search, $status);
            $db->send_response(200, 'success', $paths, 'Learning paths retrieved');
            break;

        case 'path':
            $path_id = (int) ($input['path_id'] ?? $_GET['path_id'] ?? 0);

            if (!$path_id) {
                $db->send_response(400, 'error', null, 'path_id required');
                exit;
            }

            $path = $enrollmentManager->getLearningPathById($path_id);
            $db->send_response(200, 'success', $path, 'Learning path retrieved');
            break;

        case 'unenrolled-users':
            $path_id = (int) ($input['path_id'] ?? $_GET['path_id'] ?? 0);
            $search = $input['search'] ?? $_GET['search'] ?? null;

            if (!$path_id) {
                $db->send_response(400, 'error', null, 'path_id required');
                exit;
            }

            $users = $enrollmentManager->getUnenrolledUsers($path_id, $search, $department_id, $team_id, $branch_id);
            $db->send_response(200, 'success', $users, 'Unenrolled users retrieved');
            break;

        case 'path-stats':
            $path_id = (int) ($input['path_id'] ?? $_GET['path_id'] ?? 0);

            if (!$path_id) {
                $db->send_response(400, 'error', null, 'path_id required');
                exit;
            }

            $stats = $enrollmentManager->getPathEnrollmentStats($path_id);
            $db->send_response(200, 'success', $stats, 'Enrollment stats retrieved');
            break;

        case 'path-enrollees':
            $path_id = (int) ($input['path_id'] ?? $_GET['path_id'] ?? 0);
            $status = $input['status'] ?? $_GET['status'] ?? null;
            $page = (int) ($input['page'] ?? $_GET['page'] ?? 1);
            $limit = (int) ($input['limit'] ?? $_GET['limit'] ?? 20);

            if (!$path_id) {
                $db->send_response(400, 'error', null, 'path_id required');
                exit;
            }

            $result = $enrollmentManager->getPathEnrollees($path_id, $status, $page, $limit);
            $db->send_response(200, 'success', $result, 'Enrollees retrieved');
            break;

        // ==========================================
        // ENROLLMENT ACTIONS
        // ==========================================

        case 'preview':
            $result = $enrollmentManager->previewEnrollment($input);
            $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                $result['success'] ? 'Preview generated' : $result['message']);
            break;

        case 'enroll':
            $input['assigned_by'] = $userId;

            $result = $enrollmentManager->enrollUsers($input);
            $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                $result['success'] ? $result['message'] : $result['message']);
            break;

        case 'unenroll':
            $input['removed_by'] = $userId;

            $result = $enrollmentManager->unenrollUsers($input);
            $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                $result['success'] ? $result['message'] : $result['message']);
            break;

        case 'update-deadline':
            $path_id = (int) ($input['path_id'] ?? 0);
            $user_ids = $input['user_ids'] ?? [];
            $due_date = $input['due_date'] ?? null;

            if (!$path_id) {
                $db->send_response(400, 'error', null, 'path_id required');
                exit;
            }

            $result = $enrollmentManager->updateDueDate($path_id, $user_ids, $due_date);
            $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                $result['success'] ? $result['message'] : $result['message']);
            break;

        case 'set-mandatory':
            $path_id = (int) ($input['path_id'] ?? 0);
            $user_ids = $input['user_ids'] ?? [];
            $is_mandatory = (bool) ($input['is_mandatory'] ?? false);

            if (!$path_id) {
                $db->send_response(400, 'error', null, 'path_id required');
                exit;
            }

            $result = $enrollmentManager->setMandatory($path_id, $user_ids, $is_mandatory);
            $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                $result['success'] ? $result['message'] : $result['message']);
            break;

        case 'suspend':
            $path_id = (int) ($input['path_id'] ?? 0);
            $user_ids = $input['user_ids'] ?? [];
            $reason = $input['reason'] ?? null;

            if (!$path_id) {
                $db->send_response(400, 'error', null, 'path_id required');
                exit;
            }

            $result = $enrollmentManager->suspendEnrollments($path_id, $user_ids, $reason);
            $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                $result['success'] ? $result['message'] : $result['message']);
            break;

        case 'resume':
            $path_id = (int) ($input['path_id'] ?? 0);
            $user_ids = $input['user_ids'] ?? [];

            if (!$path_id) {
                $db->send_response(400, 'error', null, 'path_id required');
                exit;
            }

            $result = $enrollmentManager->resumeEnrollments($path_id, $user_ids);
            $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                $result['success'] ? $result['message'] : $result['message']);
            break;

        case 'send-reminder':
            $path_id = (int) ($input['path_id'] ?? 0);
            $user_ids = $input['user_ids'] ?? [];
            $message = $input['message'] ?? null;

            if (!$path_id) {
                $db->send_response(400, 'error', null, 'path_id required');
                exit;
            }

            $result = $enrollmentManager->sendReminder($path_id, $user_ids, $message, $userId);
            $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                $result['success'] ? $result['message'] : $result['message']);
            break;

        default:
            $db->send_response(400, 'error', null,
                'Invalid action. Available: departments, teams, branches, users, paths, path, ' .
                'unenrolled-users, path-stats, path-enrollees, preview, enroll, unenroll, ' .
                'update-deadline, set-mandatory, suspend, resume, send-reminder');
    }

} catch (Exception $e) {
    error_log('Learning Path Enrollment API Error: ' . $e->getMessage());
    $db->send_response(500, 'error', null, 'Server error: ' . $e->getMessage());
}