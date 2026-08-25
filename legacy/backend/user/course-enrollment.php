<?php

/**
 * Course Enrollment API Endpoint
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
require_once __DIR__ . '/../class/CourseEnrollment.php';
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

    // Handle POST request
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get authentication from request
    $action = $_GET['action'] ?? null;
    $token = $_GET['token'] ?? null;
    $userId = $_GET['user_id'] ?? null;
    $tenant_id = $_GET['tenant_id'] ?? null;

    $search = $_GET['search'] ?? null;
    $department_id = $_GET['department_id'] ?? null;
    $team_id = $_GET['team_id'] ?? null;
    $branch_id = $_GET['branch_id'] ?? null;

    $db = new Database();
    $conn = $db->getConnection();
    $user = new User($conn);
    $token_check = $db->verify_user($token, $userId);
    if ($token_check != 100 || !$user->is_admin($userId)) {
        http_response_code(401);
        $db->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }
    // Handle GET request
} else {
    // Optional: handle other methods (PUT, DELETE, etc.)
    http_response_code(405);
    echo json_encode([
        'status'  => false,
        'message' => 'Method Not Allowed'
    ]);
    exit;
}







// Initialize enrollment manager
$enrollmentManager = new CourseEnrollment($conn);
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
           // $search = $input['search'] ?? null;
           // $department_id = isset($input['department_id']) ? (int) $input['department_id'] : null;
           // $team_id = isset($input['team_id']) ? (int) $input['team_id'] : null;
           // $branch_id = isset($input['branch_id']) ? (int) $input['branch_id'] : null;

            $users = $enrollmentManager->getUsers($search, $department_id, $team_id, $branch_id);
            $db->send_response(200, 'success', $users, 'Users retrieved');
            break;

        case 'courses':
            $search = $input['search'] ?? null;
            $status = $input['status'] ?? 'published';

            $courses = $enrollmentManager->getCourses($search, $status);
            $db->send_response(200, 'success', $courses, 'Courses retrieved');
            break;

        case 'unenrolled-users':
            $course_id = (int) ($input['course_id'] ?? 0);
            $search = $input['search'] ?? null;

            if (!$course_id) {
                $db->send_response(400, 'error', null, 'course_id required');
                exit;
            }

            $users = $enrollmentManager->getUnenrolledUsers($course_id, $search);
            $db->send_response(200, 'success', $users, 'Unenrolled users retrieved');
            break;

        case 'course-stats':
            $course_id = (int) ($input['course_id'] ?? 0);

            if (!$course_id) {
                $db->send_response(400, 'error', null, 'course_id required');
                exit;
            }

            $stats = $enrollmentManager->getCourseEnrollmentStats($course_id);
            $db->send_response(200, 'success', $stats, 'Enrollment stats retrieved');
            break;

        case 'course-enrollees':
            $course_id = (int) ($input['course_id'] ?? 0);
            $status = $input['status'] ?? null;
            $page = (int) ($input['page'] ?? 1);
            $limit = (int) ($input['limit'] ?? 20);

            if (!$course_id) {
                $db->send_response(400, 'error', null, 'course_id required');
                exit;
            }

            $result = $enrollmentManager->getCourseEnrollees($course_id, $status, $page, $limit);
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
            // Add assigned_by from authenticated user
            $input['assigned_by'] = $userId;

            $result = $enrollmentManager->enrollUsers($input);
            $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                $result['success'] ? $result['message'] : $result['message']);
            break;

        case 'unenroll':
            // Add removed_by from authenticated user
            $input['removed_by'] = $userId;

            $result = $enrollmentManager->unenrollUsers($input);
            $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                $result['success'] ? $result['message'] : $result['message']);
            break;

        case 'update-deadline':
            $course_id = (int) ($input['course_id'] ?? 0);
            $user_ids = $input['user_ids'] ?? [];
            $due_date = $input['due_date'] ?? null;

            if (!$course_id) {
                $db->send_response(400, 'error', null, 'course_id required');
                exit;
            }

            $result = $enrollmentManager->updateDueDate($course_id, $user_ids, $due_date);
            $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                $result['success'] ? $result['message'] : $result['message']);
            break;

        case 'set-mandatory':
            $course_id = (int) ($input['course_id'] ?? 0);
            $user_ids = $input['user_ids'] ?? [];
            $is_mandatory = (bool) ($input['is_mandatory'] ?? false);

            if (!$course_id) {
                $db->send_response(400, 'error', null, 'course_id required');
                exit;
            }

            $result = $enrollmentManager->setMandatory($course_id, $user_ids, $is_mandatory);
            $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                $result['success'] ? $result['message'] : $result['message']);
            break;

        default:
            $db->send_response(400, 'error', null,
                'Invalid action. Available: departments, teams, branches, users, courses, ' .
                'unenrolled-users, course-stats, course-enrollees, preview, enroll, unenroll, ' .
                'update-deadline, set-mandatory');
    }

} catch (Exception $e) {
    error_log('Course Enrollment API Error: ' . $e->getMessage());
    $db->send_response(500, 'error', null, 'Server error: ' . $e->getMessage());
}