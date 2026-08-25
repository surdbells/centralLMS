<?php

/**
 * Skills & Competencies API Endpoint
 * Handles skills management for Admin and Learner portals
 *
 * All requests are POST with JSON body containing auth + action + params
 *
 * ADMIN Actions:
 * Category Management:
 * - create-category       - Create new skill category
 * - update-category       - Update existing category
 * - delete-category       - Delete category
 * - get-category          - Get single category
 * - get-categories        - Get all categories
 *
 * Skill Management:
 * - create-skill          - Create new skill
 * - update-skill          - Update existing skill
 * - delete-skill          - Delete skill
 * - get-skill             - Get single skill with details
 * - get-skills            - Get all skills with filters
 *
 * Course-Skill Mapping:
 * - assign-course-skills  - Assign skills to a course
 * - get-course-skills     - Get skills for a course
 * - get-skill-courses     - Get courses that teach a skill
 *
 * Analytics (Admin):
 * - skills-summary        - Dashboard summary stats
 * - skill-stats           - All skills with adoption stats
 * - top-skills            - Top skills by adoption
 * - skill-learners        - Learners who have a skill
 * - skill-gaps            - Skills with low adoption
 *
 * LEARNER Actions:
 * - my-skills             - Get learner's acquired skills
 * - my-skills-by-category - Get learner skills grouped by category
 * - my-skill-progress     - Get skill progress (in-progress and completed)
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
require_once __DIR__ . '/../class/Skills.php';

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

// Initialize skill manager
$skill = new Skill($conn);
$skill->setTenant($tenant_id);

// Get action from input
$action = $input['action'] ?? '';

try {
    switch ($action) {
        // ==========================================
        // SKILL CATEGORY MANAGEMENT (Admin)
        // ==========================================

        case 'create-category':
            $result = $skill->createCategory($input);
            $db->send_response(
                $result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error',
                $result['success'] ? ['category_id' => $result['category_id']] : null,
                $result['message']
            );
            break;

        case 'update-category':
            $category_id = (int) ($input['category_id'] ?? 0);
            if (!$category_id) {
                $db->send_response(400, 'error', null, 'Category ID is required');
                break;
            }
            $result = $skill->updateCategory($category_id, $input);
            $db->send_response(
                $result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error',
                null,
                $result['message']
            );
            break;

        case 'delete-category':
            $category_id = (int) ($input['category_id'] ?? 0);
            if (!$category_id) {
                $db->send_response(400, 'error', null, 'Category ID is required');
                break;
            }
            $result = $skill->deleteCategory($category_id);
            $db->send_response(
                $result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error',
                null,
                $result['message']
            );
            break;

        case 'get-category':
            $category_id = (int) ($input['category_id'] ?? 0);
            if (!$category_id) {
                $db->send_response(400, 'error', null, 'Category ID is required');
                break;
            }
            $data = $skill->getCategory($category_id);
            if ($data) {
                $db->send_response(200, 'success', $data, 'Category retrieved');
            } else {
                $db->send_response(404, 'error', null, 'Category not found');
            }
            break;

        case 'get-categories':
            $activeOnly = (bool) ($input['active_only'] ?? false);
            $data = $skill->getCategories($activeOnly);
            $db->send_response(200, 'success', $data, 'Categories retrieved');
            break;

        // ==========================================
        // SKILL MANAGEMENT (Admin)
        // ==========================================

        case 'create-skill':
            $result = $skill->createSkill($input);
            $db->send_response(
                $result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error',
                $result['success'] ? ['skill_id' => $result['skill_id']] : null,
                $result['message']
            );
            break;

        case 'update-skill':
            $skill_id = (int) ($input['skill_id'] ?? 0);
            if (!$skill_id) {
                $db->send_response(400, 'error', null, 'Skill ID is required');
                break;
            }
            $result = $skill->updateSkill($skill_id, $input);
            $db->send_response(
                $result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error',
                null,
                $result['message']
            );
            break;

        case 'delete-skill':
            $skill_id = (int) ($input['skill_id'] ?? 0);
            if (!$skill_id) {
                $db->send_response(400, 'error', null, 'Skill ID is required');
                break;
            }
            $result = $skill->deleteSkill($skill_id);
            $db->send_response(
                $result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error',
                null,
                $result['message']
            );
            break;

        case 'get-skill':
            $skill_id = (int) ($input['skill_id'] ?? 0);
            if (!$skill_id) {
                $db->send_response(400, 'error', null, 'Skill ID is required');
                break;
            }
            $data = $skill->getSkill($skill_id);
            if ($data) {
                $db->send_response(200, 'success', $data, 'Skill retrieved');
            } else {
                $db->send_response(404, 'error', null, 'Skill not found');
            }
            break;

        case 'get-skills':
            $filters = [
                'active_only' => (bool) ($input['active_only'] ?? false),
                'category_id' => isset($input['category_id']) ? (int) $input['category_id'] : null,
                'level' => $input['level'] ?? null,
                'search' => $input['search'] ?? ''
            ];
            $data = $skill->getSkills($filters);
            $db->send_response(200, 'success', $data, 'Skills retrieved');
            break;

        // ==========================================
        // COURSE-SKILL MAPPING
        // ==========================================

        case 'assign-course-skills':
            $course_id = (int) ($input['course_id'] ?? 0);
            $skills = $input['skills'] ?? [];
            if (!$course_id) {
                $db->send_response(400, 'error', null, 'Course ID is required');
                break;
            }
            $result = $skill->assignSkillsToCourse($course_id, $skills);
            $db->send_response(200, 'success', [
                'assigned_count' => $result['assigned_count'] ?? 0
            ], $result['message']);
            break;

        case 'get-course-skills':
            $course_id = (int) ($input['course_id'] ?? 0);
            if (!$course_id) {
                $db->send_response(400, 'error', null, 'Course ID is required');
                break;
            }
            $data = $skill->getCourseSkills($course_id);
            $db->send_response(200, 'success', $data, 'Course skills retrieved');
            break;

        case 'get-skill-courses':
            $skill_id = (int) ($input['skill_id'] ?? 0);
            if (!$skill_id) {
                $db->send_response(400, 'error', null, 'Skill ID is required');
                break;
            }
            $data = $skill->getSkillCourses($skill_id);
            $db->send_response(200, 'success', $data, 'Skill courses retrieved');
            break;

        // ==========================================
        // ADMIN ANALYTICS
        // ==========================================

        case 'skills-summary':
            $data = $skill->getSkillsSummary();
            $db->send_response(200, 'success', $data, 'Skills summary retrieved');
            break;

        case 'skill-stats':
            $data = $skill->getSkillStats();
            $db->send_response(200, 'success', $data, 'Skill statistics retrieved');
            break;

        case 'top-skills':
            $limit = (int) ($input['limit'] ?? 10);
            $data = $skill->getTopSkills($limit);
            $db->send_response(200, 'success', $data, 'Top skills retrieved');
            break;

        case 'skill-learners':
            $skill_id = (int) ($input['skill_id'] ?? 0);
            $limit = (int) ($input['limit'] ?? 50);
            if (!$skill_id) {
                $db->send_response(400, 'error', null, 'Skill ID is required');
                break;
            }
            $data = $skill->getSkillLearners($skill_id, $limit);
            $db->send_response(200, 'success', $data, 'Skill learners retrieved');
            break;

        case 'skill-gaps':
            $data = $skill->getSkillGaps();
            $db->send_response(200, 'success', $data, 'Skill gaps retrieved');
            break;

        // ==========================================
        // LEARNER SKILLS
        // ==========================================

        case 'my-skills':
            $data = $skill->getLearnerSkills($userId);
            $db->send_response(200, 'success', $data, 'Your skills retrieved');
            break;

        case 'my-skills-by-category':
            $data = $skill->getLearnerSkillsByCategory($userId);
            $db->send_response(200, 'success', $data, 'Your skills by category retrieved');
            break;

        case 'my-skill-progress':
            $data = $skill->getLearnerSkillProgress($userId);
            $db->send_response(200, 'success', $data, 'Your skill progress retrieved');
            break;

        // Also allow getting another user's skills (for admin viewing learner profile)
        case 'user-skills':
            $target_user_id = (int) ($input['target_user_id'] ?? 0);
            if (!$target_user_id) {
                $db->send_response(400, 'error', null, 'Target user ID is required');
                break;
            }
            $data = $skill->getLearnerSkills($target_user_id);
            $db->send_response(200, 'success', $data, 'User skills retrieved');
            break;

        case 'user-skill-progress':
            $target_user_id = (int) ($input['target_user_id'] ?? 0);
            if (!$target_user_id) {
                $db->send_response(400, 'error', null, 'Target user ID is required');
                break;
            }
            $data = $skill->getLearnerSkillProgress($target_user_id);
            $db->send_response(200, 'success', $data, 'User skill progress retrieved');
            break;

        // ==========================================
        // HELPER ENDPOINTS FOR DROPDOWNS
        // ==========================================

        case 'get-users':
            $search = $input['search'] ?? '';
            $limit = (int) ($input['limit'] ?? 100);
            $data = $skill->getUsers($search, $limit);
            $db->send_response(200, 'success', $data, 'Users retrieved');
            break;

        case 'get-courses':
            $activeOnly = (bool) ($input['active_only'] ?? true);
            $data = $skill->getCourses($activeOnly);
            $db->send_response(200, 'success', $data, 'Courses retrieved');
            break;

        default:
            $db->send_response(400, 'error', null,
                'Invalid action. Available actions: ' .
                'create-category, update-category, delete-category, get-category, get-categories, ' .
                'create-skill, update-skill, delete-skill, get-skill, get-skills, ' .
                'assign-course-skills, get-course-skills, get-skill-courses, ' .
                'skills-summary, skill-stats, top-skills, skill-learners, skill-gaps, ' .
                'my-skills, my-skills-by-category, my-skill-progress, user-skills, user-skill-progress, ' .
                'get-users, get-courses');
    }

} catch (Exception $e) {
    error_log('Skills API Error: ' . $e->getMessage());
    $db->send_response(500, 'error', null, 'Server error: ' . $e->getMessage());
}