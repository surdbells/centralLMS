<?php

/**
 * Assessment API Endpoint
 * Handles assessments, sections, question assignment, and learner assignment
 * For Multi-Tenant LMS - Admin Portal
 *
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
require_once __DIR__ . '/../class/Assessment.php';
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

    // Initialize Assessment manager
    $assessment = new Assessment($conn);
    $assessment->setTenant($tenant_id);
    $assessment->setUser($user_id);

    switch ($action) {
        // ==========================================
        // ASSESSMENTS
        // ==========================================

        case 'create-assessment':
            $result = $assessment->createAssessment($input);
            $code = $result['success'] ? 201 : 400;
            $db->send_response($code, $result['success'] ? 'success' : 'error',
                $result['success'] ? ['assessment_id' => $result['assessment_id']] : null,
                $result['message']);
            break;

        case 'update-assessment':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                exit;
            }
            $result = $assessment->updateAssessment($assessment_id, $input);
            $db->send_response($result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        case 'delete-assessment':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                exit;
            }
            $result = $assessment->deleteAssessment($assessment_id);
            $db->send_response($result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        case 'get-assessment':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                exit;
            }
            $data = $assessment->getAssessment($assessment_id);
            if ($data) {
                $db->send_response(200, 'success', $data, 'Assessment retrieved');
            } else {
                $db->send_response(404, 'error', null, 'Assessment not found');
            }
            break;

        case 'get-assessments':
            $filters = [
                'status' => $input['status'] ?? null,
                'course_id' => $input['course_id'] ?? null,
                'live_training_id' => $input['live_training_id'] ?? null,
                'is_standalone' => $input['is_standalone'] ?? null,
                'proctoring_level' => $input['proctoring_level'] ?? null,
                'search' => $input['search'] ?? '',
                'page' => $input['page'] ?? 1,
                'limit' => $input['limit'] ?? 20
            ];
            $result = $assessment->getAssessments($filters);
            $db->send_response(200, 'success', $result, 'Assessments retrieved');
            break;

        case 'publish-assessment':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                exit;
            }
            $result = $assessment->publishAssessment($assessment_id);
            $db->send_response($result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        case 'unpublish-assessment':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                exit;
            }
            $result = $assessment->unpublishAssessment($assessment_id);
            $db->send_response($result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        case 'close-assessment':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                exit;
            }
            $result = $assessment->closeAssessment($assessment_id);
            $db->send_response($result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        case 'clone-assessment':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                exit;
            }
            $result = $assessment->cloneAssessment($assessment_id);
            $code = $result['success'] ? 201 : 400;
            $db->send_response($code, $result['success'] ? 'success' : 'error',
                $result['success'] ? ['assessment_id' => $result['assessment_id']] : null,
                $result['message']);
            break;

        case 'get-assessment-stats':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                exit;
            }
            $stats = $assessment->getAssessmentStats($assessment_id);
            $db->send_response(200, 'success', $stats, 'Statistics retrieved');
            break;

        // ==========================================
        // SECTIONS
        // ==========================================

        case 'create-section':
            $result = $assessment->createSection($input);
            $code = $result['success'] ? 201 : 400;
            $db->send_response($code, $result['success'] ? 'success' : 'error',
                $result['success'] ? ['section_id' => $result['section_id']] : null,
                $result['message']);
            break;

        case 'update-section':
            $section_id = (int) ($input['section_id'] ?? 0);
            if (!$section_id) {
                $db->send_response(400, 'error', null, 'Section ID is required');
                exit;
            }
            $result = $assessment->updateSection($section_id, $input);
            $db->send_response($result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        case 'delete-section':
            $section_id = (int) ($input['section_id'] ?? 0);
            if (!$section_id) {
                $db->send_response(400, 'error', null, 'Section ID is required');
                exit;
            }
            $result = $assessment->deleteSection($section_id);
            $db->send_response($result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        case 'get-sections':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                exit;
            }
            $sections = $assessment->getSections($assessment_id);
            $db->send_response(200, 'success', $sections, 'Sections retrieved');
            break;

        case 'reorder-sections':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            $section_orders = $input['section_orders'] ?? [];
            if (!$assessment_id || empty($section_orders)) {
                $db->send_response(400, 'error', null, 'Assessment ID and section orders are required');
                exit;
            }
            $result = $assessment->reorderSections($assessment_id, $section_orders);
            $db->send_response(200, 'success', null, $result['message']);
            break;

        // ==========================================
        // QUESTION ASSIGNMENT
        // ==========================================

        case 'add-question':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            $question_id = (int) ($input['question_id'] ?? 0);
            if (!$assessment_id || !$question_id) {
                $db->send_response(400, 'error', null, 'Assessment ID and Question ID are required');
                exit;
            }
            $options = [
                'section_id' => $input['section_id'] ?? null,
                'points_override' => $input['points_override'] ?? null,
                'time_limit_override' => $input['time_limit_override'] ?? null,
                'required' => $input['required'] ?? 0,
                'sort_order' => $input['sort_order'] ?? 0
            ];
            $result = $assessment->addQuestionToAssessment($assessment_id, $question_id, $options);
            $db->send_response($result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        case 'remove-question':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            $question_id = (int) ($input['question_id'] ?? 0);
            if (!$assessment_id || !$question_id) {
                $db->send_response(400, 'error', null, 'Assessment ID and Question ID are required');
                exit;
            }
            $result = $assessment->removeQuestionFromAssessment($assessment_id, $question_id);
            $db->send_response($result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        case 'update-question':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            $question_id = (int) ($input['question_id'] ?? 0);
            if (!$assessment_id || !$question_id) {
                $db->send_response(400, 'error', null, 'Assessment ID and Question ID are required');
                exit;
            }
            $options = [
                'section_id' => $input['section_id'] ?? null,
                'points_override' => $input['points_override'] ?? null,
                'time_limit_override' => $input['time_limit_override'] ?? null,
                'required' => $input['required'] ?? 0,
                'sort_order' => $input['sort_order'] ?? 0
            ];
            $result = $assessment->updateAssessmentQuestion($assessment_id, $question_id, $options);
            $db->send_response($result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        case 'get-questions':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                exit;
            }
            $questions = $assessment->getAssessmentQuestions($assessment_id);
            $db->send_response(200, 'success', $questions, 'Questions retrieved');
            break;

        case 'bulk-add-questions':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            $question_ids = $input['question_ids'] ?? [];
            $section_id = !empty($input['section_id']) ? (int) $input['section_id'] : null;
            if (!$assessment_id || empty($question_ids)) {
                $db->send_response(400, 'error', null, 'Assessment ID and Question IDs are required');
                exit;
            }
            $result = $assessment->bulkAddQuestions($assessment_id, $question_ids, $section_id);
            $db->send_response(200, 'success', [
                'added' => $result['added'],
                'skipped' => $result['skipped']
            ], $result['message']);
            break;

        case 'reorder-questions':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            $question_orders = $input['question_orders'] ?? [];
            if (!$assessment_id || empty($question_orders)) {
                $db->send_response(400, 'error', null, 'Assessment ID and question orders are required');
                exit;
            }
            $result = $assessment->reorderQuestions($assessment_id, $question_orders);
            $db->send_response(200, 'success', null, $result['message']);
            break;

        // ==========================================
        // LEARNER ASSIGNMENT
        // ==========================================

        case 'assign-assessment':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                exit;
            }
            $result = $assessment->assignAssessment($assessment_id, $input);
            $db->send_response($result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error',
                ['assigned_count' => $result['assigned_count'] ?? 0],
                $result['message']);
            break;

        case 'remove-assignment':
            $assignment_id = (int) ($input['assignment_id'] ?? 0);
            if (!$assignment_id) {
                $db->send_response(400, 'error', null, 'Assignment ID is required');
                exit;
            }
            $result = $assessment->removeAssignment($assignment_id);
            $db->send_response($result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        case 'get-assignments':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                exit;
            }
            $assignments = $assessment->getAssignments($assessment_id);
            $db->send_response(200, 'success', $assignments, 'Assignments retrieved');
            break;

        case 'check-assignment':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            $check_user_id = (int) ($input['check_user_id'] ?? $user_id);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                exit;
            }
            $isAssigned = $assessment->isUserAssigned($assessment_id, $check_user_id);
            $db->send_response(200, 'success', ['is_assigned' => $isAssigned], 'Assignment checked');
            break;

        // ==========================================
        // LOOKUPS
        // ==========================================

        case 'get-courses':
            $courses = $assessment->getCourses();
            $db->send_response(200, 'success', $courses, 'Courses retrieved');
            break;

        case 'get-trainings':
            $trainings = $assessment->getLiveTrainings();
            $db->send_response(200, 'success', $trainings, 'Trainings retrieved');
            break;

        case 'get-departments':
            $departments = $assessment->getDepartments();
            $db->send_response(200, 'success', $departments, 'Departments retrieved');
            break;

        case 'get-teams':
            $teams = $assessment->getTeams();
            $db->send_response(200, 'success', $teams, 'Teams retrieved');
            break;

        case 'get-branches':
            $branches = $assessment->getBranches();
            $db->send_response(200, 'success', $branches, 'Branches retrieved');
            break;

        case 'get-users':
            $search = $input['search'] ?? '';
            $limit = (int) ($input['limit'] ?? 50);
            $users = $assessment->getUsers($search, $limit);
            $db->send_response(200, 'success', $users, 'Users retrieved');
            break;

        case 'get-grading-scales':
            $scales = $assessment->getGradingScales();
            $db->send_response(200, 'success', $scales, 'Grading scales retrieved');
            break;

        case 'create-grading-scale':
            $result = $assessment->createGradingScale($input);
            $code = $result['success'] ? 201 : 400;
            $db->send_response($code, $result['success'] ? 'success' : 'error',
                $result['success'] ? ['scale_id' => $result['scale_id']] : null,
                $result['message']);
            break;

        // ==========================================
        // PHASE 15: DASHBOARDS, CATALOG & REPORTS
        // ==========================================

        case 'get-admin-dashboard':
            $range = $input['range'] ?? '30d';
            $data = $assessment->getAdminDashboard($range);
            $db->send_response(200, 'success', $data, 'Admin dashboard loaded');
            break;

        case 'get-learner-dashboard':
            $data = $assessment->getLearnerDashboard($user_id);
            $db->send_response(200, 'success', $data, 'Learner dashboard loaded');
            break;

        case 'get-catalog':
            $data = $assessment->getCatalog($user_id);
            $db->send_response(200, 'success', $data, 'Catalog loaded');
            break;

        case 'get-categories':
            $data = $assessment->getCategories();
            $db->send_response(200, 'success', $data, 'Categories loaded');
            break;

        case 'get-learner-report':
            $filters = [
                'assessment_id' => $input['assessment_id'] ?? null,
                'category_id' => $input['category_id'] ?? null,
                'status' => $input['status'] ?? null,
                'range' => $input['range'] ?? '30d'
            ];
            $data = $assessment->getLearnerReport($filters);
            $db->send_response(200, 'success', $data, 'Report generated');
            break;

        case 'get-question-analytics':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'assessment_id required');
                break;
            }
            $range = $input['range'] ?? '30d';
            $data = $assessment->getQuestionAnalytics($assessment_id, $range);
            $db->send_response(200, 'success', $data, 'Question analytics loaded');
            break;

        case 'get-assessments-list':
            $data = $assessment->getAssessmentsList();
            $db->send_response(200, 'success', $data, 'Assessments list loaded');
            break;

        case 'export-report':
            $filters = [
                'assessment_id' => $input['assessment_id'] ?? null,
                'category_id' => $input['category_id'] ?? null,
                'status' => $input['status'] ?? null,
                'range' => $input['range'] ?? '30d'
            ];
            $format = $input['format'] ?? 'csv';
            $csv = $assessment->exportReport($filters, $format);
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="assessment-report.csv"');
            echo $csv;
            exit;

        default:
            $db->send_response(400, 'error', null,
                'Invalid action. Available: create-assessment, update-assessment, delete-assessment, ' .
                'get-assessment, get-assessments, publish-assessment, unpublish-assessment, close-assessment, ' .
                'clone-assessment, get-assessment-stats, create-section, update-section, delete-section, ' .
                'get-sections, reorder-sections, add-question, remove-question, update-question, get-questions, ' .
                'bulk-add-questions, reorder-questions, assign-assessment, remove-assignment, get-assignments, ' .
                'check-assignment, get-courses, get-trainings, get-departments, get-teams, get-branches, ' .
                'get-users, get-grading-scales, create-grading-scale, get-admin-dashboard, get-learner-dashboard, ' .
                'get-catalog, get-categories, get-learner-report, get-question-analytics, get-assessments-list, ' .
                'export-report');
    }

} catch (Exception $e) {
    error_log('Assessment API Error: ' . $e->getMessage());
    $db->send_response(500, 'error', null, 'Server error: ' . $e->getMessage());
}