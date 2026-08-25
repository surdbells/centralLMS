<?php

/**
 * My Learning API Endpoint
 * Learner endpoint for managing enrolled courses and learning progress
 *
 * All requests are POST with JSON body containing auth + action + params
 * 
 * Actions:
 * - stats                - Get learning statistics
 * - enrolled             - Get all enrolled courses
 * - in-progress          - Get courses in progress
 * - completed            - Get completed courses
 * - course-details       - Get detailed course info with lessons
 * - start-course         - Start a course (change status to in_progress)
 * - next-lesson          - Get next lesson to continue
 * - update-accessed      - Update last accessed time
 * - available-courses    - Get courses available for self-enrollment
 * - self-enroll          - Enroll in a course
 * - categories           - Get course categories
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
require_once __DIR__ . '/../class/MyLearning.php';
require_once __DIR__ . '/../class/User.php';

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
$user = new User($conn);

$token = $input['token'];
$userId = (int) $input['user_id'];
$tenant_id = (int) $input['tenant_id'];

// Verify user (any authenticated user can access their own learning)
$token_check = $db->verify_user($token, $userId);
if ($token_check != 100) {
    http_response_code(401);
    $db->send_response(401, "failed", [], "Unauthorized request");
    exit;
}

// Initialize learning manager
$myLearning = new MyLearning($conn);
$myLearning->setTenant($tenant_id);
$myLearning->setUser($userId);

// Get action from input
$action = $input['action'] ?? '';

try {
    switch ($action) {
        // ==========================================
        // DASHBOARD & STATS
        // ==========================================

        case 'stats':
            $stats = $myLearning->getLearningStats();
            $db->send_response(200, 'success', $stats, 'Stats retrieved');
            break;

        // ==========================================
        // ENROLLED COURSES
        // ==========================================

        case 'enrolled':
            $status = $input['status'] ?? null;
            $search = $input['search'] ?? null;
            
            $courses = $myLearning->getEnrolledCourses($status, $search);
            $db->send_response(200, 'success', $courses, 'Enrolled courses retrieved');
            break;

        case 'in-progress':
            $limit = (int) ($input['limit'] ?? 5);
            $courses = $myLearning->getInProgressCourses($limit);
            $db->send_response(200, 'success', $courses, 'In-progress courses retrieved');
            break;

        case 'completed':
            $limit = (int) ($input['limit'] ?? 10);
            $courses = $myLearning->getCompletedCourses($limit);
            $db->send_response(200, 'success', $courses, 'Completed courses retrieved');
            break;

        // ==========================================
        // COURSE DETAILS
        // ==========================================

        case 'course-details':
            $course_id = (int) ($input['course_id'] ?? 0);
            
            if (!$course_id) {
                $db->send_response(400, 'error', null, 'course_id required');
                exit;
            }

            $course = $myLearning->getCourseDetails($course_id);
            if (!$course) {
                $db->send_response(404, 'error', null, 'Course not found or not enrolled');
                exit;
            }
            
            $db->send_response(200, 'success', $course, 'Course details retrieved');
            break;

        // ==========================================
        // LEARNING ACTIONS
        // ==========================================

        case 'start-course':
            $course_id = (int) ($input['course_id'] ?? 0);
            
            if (!$course_id) {
                $db->send_response(400, 'error', null, 'course_id required');
                exit;
            }

            $result = $myLearning->startCourse($course_id);
            $db->send_response(200, $result['success'] ? 'success' : 'error', $result, $result['message']);
            break;

        case 'next-lesson':
            $course_id = (int) ($input['course_id'] ?? 0);
            
            if (!$course_id) {
                $db->send_response(400, 'error', null, 'course_id required');
                exit;
            }

            $lesson = $myLearning->getNextLesson($course_id);
            $db->send_response(200, 'success', $lesson, 'Next lesson retrieved');
            break;

        case 'update-accessed':
            $course_id = (int) ($input['course_id'] ?? 0);
            
            if (!$course_id) {
                $db->send_response(400, 'error', null, 'course_id required');
                exit;
            }

            $success = $myLearning->updateLastAccessed($course_id);
            $db->send_response(200, $success ? 'success' : 'error', null, 
                $success ? 'Updated' : 'Failed to update');
            break;

        // ==========================================
        // AVAILABLE / SELF-ENROLLMENT
        // ==========================================

        case 'available-courses':
            $search = $input['search'] ?? null;
            $category_id = isset($input['category_id']) ? (int) $input['category_id'] : null;
            
            $courses = $myLearning->getAvailableCourses($search, $category_id);
            $db->send_response(200, 'success', $courses, 'Available courses retrieved');
            break;

        case 'self-enroll':
            $course_id = (int) ($input['course_id'] ?? 0);
            
            if (!$course_id) {
                $db->send_response(400, 'error', null, 'course_id required');
                exit;
            }

            $result = $myLearning->selfEnroll($course_id);
            $db->send_response(200, $result['success'] ? 'success' : 'error', $result, $result['message']);
            break;

        // ==========================================
        // CATEGORIES
        // ==========================================

        case 'categories':
            $categories = $myLearning->getCategories();
            $db->send_response(200, 'success', $categories, 'Categories retrieved');
            break;

        default:
            $db->send_response(400, 'error', null,
                'Invalid action. Available: stats, enrolled, in-progress, completed, ' .
                'course-details, start-course, next-lesson, update-accessed, ' .
                'available-courses, self-enroll, categories');
    }

} catch (Exception $e) {
    error_log('My Learning API Error: ' . $e->getMessage());
    $db->send_response(500, 'error', null, 'Server error: ' . $e);
}
