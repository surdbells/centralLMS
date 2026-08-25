<?php

/**
 * Learning Paths API Endpoint
 *
 * Handles all Learning Path operations via RESTful API
 *
 * @package LMS
 * @version 1.0.0
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../class/LearningPath.php';
require_once __DIR__ . '/../class/LearningPathSection.php';
require_once __DIR__ . '/../class/LearningPathItem.php';
require_once __DIR__ . '/../class/LearningPathEnrollment.php';
require_once __DIR__ . '/../class/LearningPathProgress.php';
require_once __DIR__ . '/../class/LearningPathReview.php';
require_once __DIR__ . '/../class/LearningPathAssignment.php';

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
    $tenant_id = (int) ($input['tenant_id'] ?? 0);
    $user_id = (int) ($input['user_id'] ?? 0);
    $action = $input['action'] ?? '';

    if (!$token || !$tenant_id || !$user_id) {
        $db->send_response(401, 'error', null, 'Authentication required');
        exit;
    }

    // Validate token here (your existing auth logic)
    // ...

    // Initialize models
    $learningPath = new LearningPath($conn);
    $learningPath->tenant_id = $tenant_id;

    $section = new LearningPathSection($conn);
    $section->tenant_id = $tenant_id;

    $item = new LearningPathItem($conn);
    $item->tenant_id = $tenant_id;

    $enrollment = new LearningPathEnrollment($conn);
    $enrollment->tenant_id = $tenant_id;

    $progress = new LearningPathProgress($conn);
    $progress->tenant_id = $tenant_id;

    $review = new LearningPathReview($conn);
    $review->tenant_id = $tenant_id;

    $assignment = new LearningPathAssignment($conn);
    $assignment->tenant_id = $tenant_id;

    switch ($action) {
        // ==========================================
        // LEARNING PATH CRUD
        // ==========================================

        case 'create-path':
            $learningPath->title = $input['title'] ?? '';
            $learningPath->short_description = $input['short_description'] ?? '';
            $learningPath->description = $input['description'] ?? '';
            $learningPath->thumbnail_url = $input['thumbnail_url'] ?? null;
            $learningPath->banner_url = $input['banner_url'] ?? null;
            $learningPath->category_id = $input['category_id'] ?? null;
            $learningPath->skill_level = $input['skill_level'] ?? 'beginner';
            $learningPath->path_type = $input['path_type'] ?? 'sequential';
            $learningPath->completion_type = $input['completion_type'] ?? 'all_items';
            $learningPath->completion_threshold = $input['completion_threshold'] ?? 100;
            $learningPath->passing_score = $input['passing_score'] ?? 70;
            $learningPath->estimated_duration_hours = $input['estimated_duration_hours'] ?? null;
            $learningPath->estimated_weeks = $input['estimated_weeks'] ?? null;
            $learningPath->weekly_commitment_hours = $input['weekly_commitment_hours'] ?? null;
            $learningPath->is_mandatory = $input['is_mandatory'] ?? false;
            $learningPath->enforce_prerequisites = $input['enforce_prerequisites'] ?? true;
            $learningPath->allow_skip = $input['allow_skip'] ?? false;
            $learningPath->certificate_enabled = $input['certificate_enabled'] ?? false;
            $learningPath->certificate_template_id = $input['certificate_template_id'] ?? null;
            $learningPath->certificate_validity_months = $input['certificate_validity_months'] ?? null;
            $learningPath->total_points = $input['total_points'] ?? 0;
            $learningPath->badge_id = $input['badge_id'] ?? null;
            $learningPath->visibility = $input['visibility'] ?? 'public';
            $learningPath->enrollment_type = $input['enrollment_type'] ?? 'open';
            $learningPath->max_enrollments = $input['max_enrollments'] ?? null;
            $learningPath->enrollment_start_date = $input['enrollment_start_date'] ?? null;
            $learningPath->enrollment_end_date = $input['enrollment_end_date'] ?? null;
            $learningPath->is_free = $input['is_free'] ?? true;
            $learningPath->price = $input['price'] ?? 0;
            $learningPath->currency = $input['currency'] ?? 'USD';
            $learningPath->tags = $input['tags'] ?? [];
            $learningPath->learning_objectives = $input['learning_objectives'] ?? [];
            $learningPath->prerequisites_description = $input['prerequisites_description'] ?? '';
            $learningPath->created_by = $user_id;

            $result = $learningPath->create();
            if ($result['success']) {
                $db->send_response(201, 'success', $result['data'], 'Learning path created successfully');
            } else {
                $db->send_response(400, 'error', null, $result['error']);
            }
            break;

        case 'update-path':
            $path_id = (int) ($input['path_id'] ?? 0);
            if (!$path_id) {
                $db->send_response(400, 'error', null, 'Path ID is required');
                break;
            }

            $learningPath->path_id = $path_id;
            $learningPath->updated_by = $user_id;

            // Update only provided fields
            $fields = ['title', 'short_description', 'description', 'thumbnail_url', 'banner_url',
                'category_id', 'skill_level', 'path_type', 'completion_type', 'completion_threshold',
                'passing_score', 'estimated_duration_hours', 'estimated_weeks', 'weekly_commitment_hours',
                'is_mandatory', 'enforce_prerequisites', 'allow_skip', 'certificate_enabled',
                'certificate_template_id', 'certificate_validity_months', 'total_points', 'badge_id',
                'visibility', 'enrollment_type', 'max_enrollments', 'enrollment_start_date',
                'enrollment_end_date', 'is_free', 'price', 'currency', 'tags', 'learning_objectives',
                'prerequisites_description'];

            foreach ($fields as $field) {
                if (isset($input[$field])) {
                    $learningPath->$field = $input[$field];
                }
            }

            $result = $learningPath->update();
            if ($result['success']) {
                $db->send_response(200, 'success', null, 'Learning path updated successfully');
            } else {
                $db->send_response(400, 'error', null, $result['error']);
            }
            break;

        case 'get-path':
            $path_id = (int) ($input['path_id'] ?? 0);
            if (!$path_id) {
                $db->send_response(400, 'error', null, 'Path ID is required');
                break;
            }
            $data = $learningPath->getById($path_id);
            if (!$data) {
                $db->send_response(404, 'error', null, 'Learning path not found');
                break;
            }
            $db->send_response(200, 'success', $data, 'Learning path retrieved');
            break;

        case 'get-path-by-slug':
            $slug = $input['slug'] ?? '';
            if (!$slug) {
                $db->send_response(400, 'error', null, 'Slug is required');
                break;
            }
            $data = $learningPath->getBySlug($slug);
            if (!$data) {
                $db->send_response(404, 'error', null, 'Learning path not found');
                break;
            }
            $db->send_response(200, 'success', $data, 'Learning path retrieved');
            break;

        case 'get-paths':
            $filters = [
                'status' => $input['status'] ?? null,
                'skill_level' => $input['skill_level'] ?? null,
                'category_id' => $input['category_id'] ?? null,
                'search' => $input['search'] ?? null,
                'is_mandatory' => isset($input['is_mandatory']) ? (int) $input['is_mandatory'] : null,
                'visibility' => $input['visibility'] ?? null
            ];

            $learningPath->page = (int) ($input['page'] ?? 1);
            $learningPath->per_page = (int) ($input['per_page'] ?? 12);
            $learningPath->sort_by = $input['sort_by'] ?? 'created_at';
            $learningPath->sort_dir = $input['sort_dir'] ?? 'desc';

            $data = $learningPath->getAll(array_filter($filters, fn($v) => $v !== null));
            $db->send_response(200, 'success', $data, 'Learning paths retrieved');
            break;

        case 'get-catalog':
            $filters = [
                'skill_level' => $input['skill_level'] ?? null,
                'category_id' => $input['category_id'] ?? null,
                'search' => $input['search'] ?? null
            ];

            $learningPath->page = (int) ($input['page'] ?? 1);
            $learningPath->per_page = (int) ($input['per_page'] ?? 12);

            $data = $learningPath->getCatalog();
            $db->send_response(200, 'success', $data, 'Catalog retrieved');
            break;

        case 'delete-path':
            $path_id = (int) ($input['path_id'] ?? 0);
            if (!$path_id) {
                $db->send_response(400, 'error', null, 'Path ID is required');
                break;
            }
            $learningPath->path_id = $path_id;
            $learningPath->updated_by = $user_id;
            $learningPath->delete();
            $db->send_response(200, 'success', null, 'Learning path deleted');
            break;

        case 'publish-path':
            $path_id = (int) ($input['path_id'] ?? 0);
            if (!$path_id) {
                $db->send_response(400, 'error', null, 'Path ID is required');
                break;
            }
            $learningPath->path_id = $path_id;
            $learningPath->updated_by = $user_id;
            $result = $learningPath->publish();
            if ($result['success']) {
                $db->send_response(200, 'success', null, 'Learning path published');
            } else {
                $db->send_response(400, 'error', $result['errors'] ?? null, 'Cannot publish learning path');
            }
            break;

        case 'archive-path':
            $path_id = (int) ($input['path_id'] ?? 0);
            if (!$path_id) {
                $db->send_response(400, 'error', null, 'Path ID is required');
                break;
            }
            $learningPath->path_id = $path_id;
            $learningPath->updated_by = $user_id;
            $learningPath->archive();
            $db->send_response(200, 'success', null, 'Learning path archived');
            break;

        case 'duplicate-path':
            $path_id = (int) ($input['path_id'] ?? 0);
            if (!$path_id) {
                $db->send_response(400, 'error', null, 'Path ID is required');
                break;
            }
            $learningPath->path_id = $path_id;
            $learningPath->created_by = $user_id;
            $new_title = $input['new_title'] ?? null;
            $result = $learningPath->duplicate($new_title);
            if ($result['success']) {
                $db->send_response(201, 'success', $result['data'], 'Learning path duplicated');
            } else {
                $db->send_response(400, 'error', null, $result['error']);
            }
            break;

        case 'get-path-statistics':
            $path_id = (int) ($input['path_id'] ?? 0);
            if (!$path_id) {
                $db->send_response(400, 'error', null, 'Path ID is required');
                break;
            }
            $learningPath->path_id = $path_id;
            $data = $learningPath->getStatistics();
            $db->send_response(200, 'success', $data, 'Statistics retrieved');
            break;

        case 'get-path-details':
            $path_id = (int) ($input['path_id'] ?? 0);
            if (!$path_id) {
                $db->send_response(400, 'error', null, 'Path ID is required');
                break;
            }

            // Get path info
            $pathData = $learningPath->getById($path_id);
            if (!$pathData) {
                $db->send_response(404, 'error', null, 'Learning path not found');
                break;
            }

            // Get sections with items
            $sections = $section->getWithItems($path_id);

            // Get user enrollment and progress
            $userEnrollment = $enrollment->getUserEnrollment($path_id, $user_id);
            $userProgress = null;
            if ($userEnrollment) {
                $userProgress = $progress->getProgressBySection($userEnrollment['enrollment_id']);
            }

            $data = [
                'path' => $pathData,
                'sections' => $sections,
                'enrollment' => $userEnrollment,
                'progress' => $userProgress
            ];

            $db->send_response(200, 'success', $data, 'Path details retrieved');
            break;

        // ==========================================
        // SECTION MANAGEMENT
        // ==========================================

        case 'create-section':
            $path_id = (int) ($input['path_id'] ?? 0);
            if (!$path_id) {
                $db->send_response(400, 'error', null, 'Path ID is required');
                break;
            }

            $section->path_id = $path_id;
            $section->title = $input['title'] ?? '';
            $section->description = $input['description'] ?? '';
            $section->is_milestone = $input['is_milestone'] ?? false;
            $section->milestone_title = $input['milestone_title'] ?? null;
            $section->unlock_type = $input['unlock_type'] ?? 'sequential';
            $section->unlock_after_section_id = $input['unlock_after_section_id'] ?? null;
            $section->unlock_date = $input['unlock_date'] ?? null;

            $result = $section->create();
            if ($result['success']) {
                $db->send_response(201, 'success', $result['data'], 'Section created successfully');
            } else {
                $db->send_response(400, 'error', null, $result['error']);
            }
            break;

        case 'update-section':
            $section_id = (int) ($input['section_id'] ?? 0);
            if (!$section_id) {
                $db->send_response(400, 'error', null, 'Section ID is required');
                break;
            }

            $section->section_id = $section_id;

            $fields = ['title', 'description', 'is_milestone', 'milestone_title',
                'unlock_type', 'unlock_after_section_id', 'unlock_date'];
            foreach ($fields as $field) {
                if (isset($input[$field])) {
                    $section->$field = $input[$field];
                }
            }

            $section->update();
            $db->send_response(200, 'success', null, 'Section updated successfully');
            break;

        case 'get-sections':
            $path_id = (int) ($input['path_id'] ?? 0);
            if (!$path_id) {
                $db->send_response(400, 'error', null, 'Path ID is required');
                break;
            }

            $include_items = $input['include_items'] ?? false;
            if ($include_items) {
                $data = $section->getWithItems($path_id);
            } else {
                $data = $section->getByPathId($path_id);
            }
            $db->send_response(200, 'success', $data, 'Sections retrieved');
            break;

        case 'delete-section':
            $section_id = (int) ($input['section_id'] ?? 0);
            if (!$section_id) {
                $db->send_response(400, 'error', null, 'Section ID is required');
                break;
            }
            $section->section_id = $section_id;
            $section->delete();
            $db->send_response(200, 'success', null, 'Section deleted');
            break;

        case 'reorder-sections':
            $sections_order = $input['sections'] ?? [];
            if (empty($sections_order)) {
                $db->send_response(400, 'error', null, 'Sections array is required');
                break;
            }
            $section->reorder($sections_order);
            $db->send_response(200, 'success', null, 'Sections reordered');
            break;

        // ==========================================
        // ITEM MANAGEMENT
        // ==========================================

        case 'create-item':
            $path_id = (int) ($input['path_id'] ?? 0);
            if (!$path_id) {
                $db->send_response(400, 'error', null, 'Path ID is required');
                break;
            }

            $item->path_id = $path_id;
            $item->section_id = $input['section_id'] ?? null;
            $item->item_type = $input['item_type'] ?? '';
            $item->course_id = $input['course_id'] ?? null;
            $item->assessment_id = $input['assessment_id'] ?? null;
            $item->live_session_id = $input['live_session_id'] ?? null;
            $item->media_id = $input['media_id'] ?? null;
            $item->external_url = $input['external_url'] ?? null;
            $item->external_title = $input['external_title'] ?? null;
            $item->external_description = $input['external_description'] ?? null;
            $item->estimated_duration_minutes = $input['estimated_duration_minutes'] ?? null;
            $item->is_required = $input['is_required'] ?? true;
            $item->points_value = $input['points_value'] ?? 0;
            $item->passing_score = $input['passing_score'] ?? null;
            $item->max_attempts = $input['max_attempts'] ?? null;
            $item->unlock_type = $input['unlock_type'] ?? 'sequential';
            $item->unlock_after_item_id = $input['unlock_after_item_id'] ?? null;
            $item->unlock_date = $input['unlock_date'] ?? null;
            $item->unlock_score = $input['unlock_score'] ?? null;

            $result = $item->create();
            if ($result['success']) {
                $db->send_response(201, 'success', $result['data'], 'Item created successfully');
            } else {
                $db->send_response(400, 'error', null, $result['error']);
            }
            break;

        case 'add-course':
            $path_id = (int) ($input['path_id'] ?? 0);
            $course_id = (int) ($input['course_id'] ?? 0);
            if (!$path_id || !$course_id) {
                $db->send_response(400, 'error', null, 'Path ID and Course ID are required');
                break;
            }

            $item->path_id = $path_id;
            $item->section_id = $input['section_id'] ?? null;
            $result = $item->addCourse($course_id);

            if ($result['success']) {
                $db->send_response(201, 'success', $result['data'], 'Course added to path');
            } else {
                $db->send_response(400, 'error', null, $result['error']);
            }
            break;

        case 'add-assessment':
            $path_id = (int) ($input['path_id'] ?? 0);
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$path_id || !$assessment_id) {
                $db->send_response(400, 'error', null, 'Path ID and Assessment ID are required');
                break;
            }

            $item->path_id = $path_id;
            $item->section_id = $input['section_id'] ?? null;
            $result = $item->addAssessment($assessment_id);

            if ($result['success']) {
                $db->send_response(201, 'success', $result['data'], 'Assessment added to path');
            } else {
                $db->send_response(400, 'error', null, $result['error']);
            }
            break;

        case 'add-external-link':
            $path_id = (int) ($input['path_id'] ?? 0);
            if (!$path_id) {
                $db->send_response(400, 'error', null, 'Path ID is required');
                break;
            }

            $item->path_id = $path_id;
            $item->section_id = $input['section_id'] ?? null;
            $result = $item->addExternalLink(
                $input['url'] ?? '',
                $input['title'] ?? '',
                $input['description'] ?? ''
            );

            if ($result['success']) {
                $db->send_response(201, 'success', $result['data'], 'External link added to path');
            } else {
                $db->send_response(400, 'error', null, $result['error']);
            }
            break;

        case 'update-item':
            $item_id = (int) ($input['item_id'] ?? 0);
            if (!$item_id) {
                $db->send_response(400, 'error', null, 'Item ID is required');
                break;
            }

            $item->item_id = $item_id;

            $fields = ['section_id', 'external_url', 'external_title', 'external_description',
                'estimated_duration_minutes', 'is_required', 'points_value', 'passing_score',
                'max_attempts', 'unlock_type', 'unlock_after_item_id', 'unlock_date', 'unlock_score'];
            foreach ($fields as $field) {
                if (isset($input[$field])) {
                    $item->$field = $input[$field];
                }
            }

            $item->update();
            $db->send_response(200, 'success', null, 'Item updated successfully');
            break;

        case 'get-items':
            $path_id = (int) ($input['path_id'] ?? 0);
            if (!$path_id) {
                $db->send_response(400, 'error', null, 'Path ID is required');
                break;
            }
            $data = $item->getByPathId($path_id);
            $db->send_response(200, 'success', $data, 'Items retrieved');
            break;

        case 'delete-item':
            $item_id = (int) ($input['item_id'] ?? 0);
            $path_id = (int) ($input['path_id'] ?? 0);
            if (!$item_id) {
                $db->send_response(400, 'error', null, 'Item ID is required');
                break;
            }
            $item->item_id = $item_id;
            $item->path_id = $path_id;
            $item->delete();
            $db->send_response(200, 'success', null, 'Item deleted');
            break;

        case 'reorder-items':
            $path_id = (int) ($input['path_id'] ?? 0);
            $items_order = $input['items'] ?? [];
            if (!$path_id || empty($items_order)) {
                $db->send_response(400, 'error', null, 'Path ID and Items array are required');
                break;
            }
            $item->path_id = $path_id;
            $item->reorder($items_order);
            $db->send_response(200, 'success', null, 'Items reordered');
            break;

        // ==========================================
        // ENROLLMENT MANAGEMENT
        // ==========================================

        case 'enroll':
            $path_id = (int) ($input['path_id'] ?? 0);
            if (!$path_id) {
                $db->send_response(400, 'error', null, 'Path ID is required');
                break;
            }
            $result = $enrollment->createEnrollment($path_id, $user_id, true, '', $user_id, '', 0,'self');
            if ($result['success']) {
                $db->send_response(201, 'success', $result['data'], 'Enrolled successfully');
            } else {
                $db->send_response(400, 'error', null, $result['error']);
            }
            break;

        case 'enroll-user':
            $path_id = (int) ($input['path_id'] ?? 0);
            $target_user_id = (int) ($input['target_user_id'] ?? 0);
            if (!$path_id || !$target_user_id) {
                $db->send_response(400, 'error', null, 'Path ID and User ID are required');
                break;
            }

            $enrollment->path_id = $path_id;
            $enrollment->user_id = $target_user_id;
            $enrollment->enrollment_type = 'admin';
            $enrollment->enrolled_by = $user_id;
            $enrollment->due_date = $input['due_date'] ?? null;

            $result = $enrollment->enroll();
            if ($result['success']) {
                $db->send_response(201, 'success', $result['data'], 'User enrolled successfully');
            } else {
                $db->send_response(400, 'error', null, $result['error']);
            }
            break;

        case 'bulk-enroll':
            $path_id = (int) ($input['path_id'] ?? 0);
            $user_ids = $input['user_ids'] ?? [];
            if (!$path_id || empty($user_ids)) {
                $db->send_response(400, 'error', null, 'Path ID and User IDs are required');
                break;
            }

            $enrollment->path_id = $path_id;
            $enrollment->enrolled_by = $user_id;
            $result = $enrollment->bulkEnroll($user_ids);

            $message = sprintf(
                'Enrolled %d users, skipped %d (already enrolled)',
                $result['enrolled'],
                $result['skipped']
            );
            $db->send_response(200, 'success', $result, $message);
            break;

        case 'get-enrollments':
            $path_id = (int) ($input['path_id'] ?? 0);
            if (!$path_id) {
                $db->send_response(400, 'error', null, 'Path ID is required');
                break;
            }

            $filters = [
                'status' => $input['status'] ?? null,
                'search' => $input['search'] ?? null
            ];

            $enrollment->page = (int) ($input['page'] ?? 1);
            $enrollment->per_page = (int) ($input['per_page'] ?? 20);

            $data = $enrollment->getByPathId($path_id, array_filter($filters, fn($v) => $v !== null));
            $db->send_response(200, 'success', $data, 'Enrollments retrieved');
            break;

        case 'get-my-enrollment':
            $path_id = (int) ($input['path_id'] ?? 0);
            if (!$path_id) {
                $db->send_response(400, 'error', null, 'Path ID is required');
                break;
            }
            $data = $enrollment->getUserEnrollment($path_id, $user_id);
            if (!$data) {
                $db->send_response(404, 'error', null, 'Not enrolled in this path');
                break;
            }
            $db->send_response(200, 'success', $data, 'Enrollment retrieved');
            break;

        case 'get-my-enrollments':
            $data = $enrollment->getUserEnrollments($user_id);
            $db->send_response(200, 'success', $data, 'Enrollments retrieved');
            break;

        case 'start-path':
            $path_id = (int) ($input['path_id'] ?? 0);
            if (!$path_id) {
                $db->send_response(400, 'error', null, 'Path ID is required');
                break;
            }

            $userEnrollment = $enrollment->getUserEnrollment($path_id, $user_id);
            if (!$userEnrollment) {
                $db->send_response(404, 'error', null, 'Not enrolled in this path');
                break;
            }

            $enrollment->enrollment_id = $userEnrollment['enrollment_id'];
            $enrollment->start();
            $db->send_response(200, 'success', null, 'Path started');
            break;

        case 'withdraw':
            $path_id = (int) ($input['path_id'] ?? 0);
            if (!$path_id) {
                $db->send_response(400, 'error', null, 'Path ID is required');
                break;
            }

            $userEnrollment = $enrollment->getUserEnrollment($path_id, $user_id);
            if (!$userEnrollment) {
                $db->send_response(404, 'error', null, 'Not enrolled in this path');
                break;
            }

            $enrollment->enrollment_id = $userEnrollment['enrollment_id'];
            $enrollment->withdraw();
            $db->send_response(200, 'success', null, 'Withdrawn from path');
            break;

        case 'suspend-enrollment':
            $enrollment_id = (int) ($input['enrollment_id'] ?? 0);
            if (!$enrollment_id) {
                $db->send_response(400, 'error', null, 'Enrollment ID is required');
                break;
            }
            $enrollment->enrollment_id = $enrollment_id;
            $enrollment->suspend();
            $db->send_response(200, 'success', null, 'Enrollment suspended');
            break;

        case 'resume-enrollment':
            $enrollment_id = (int) ($input['enrollment_id'] ?? 0);
            if (!$enrollment_id) {
                $db->send_response(400, 'error', null, 'Enrollment ID is required');
                break;
            }
            $enrollment->enrollment_id = $enrollment_id;
            $enrollment->resume();
            $db->send_response(200, 'success', null, 'Enrollment resumed');
            break;

        case 'extend-due-date':
            $enrollment_id = (int) ($input['enrollment_id'] ?? 0);
            $new_due_date = $input['due_date'] ?? null;
            if (!$enrollment_id || !$new_due_date) {
                $db->send_response(400, 'error', null, 'Enrollment ID and Due Date are required');
                break;
            }
            $enrollment->enrollment_id = $enrollment_id;
            $enrollment->extendDueDate($new_due_date, $input['reason'] ?? null);
            $db->send_response(200, 'success', null, 'Due date extended');
            break;

        case 'get-enrollment-statistics':
            $path_id = (int) ($input['path_id'] ?? 0);
            if (!$path_id) {
                $db->send_response(400, 'error', null, 'Path ID is required');
                break;
            }
            $data = $enrollment->getStatistics($path_id);
            $db->send_response(200, 'success', $data, 'Statistics retrieved');
            break;

        case 'check-prerequisites':
            $path_id = (int) ($input['path_id'] ?? 0);
            if (!$path_id) {
                $db->send_response(400, 'error', null, 'Path ID is required');
                break;
            }
            $data = $enrollment->checkPrerequisites();
            $db->send_response(200, 'success', $data, 'Prerequisites checked');
            break;

        // ==========================================
        // PROGRESS TRACKING
        // ==========================================

        case 'get-my-progress':
            $path_id = (int) ($input['path_id'] ?? 0);
            if (!$path_id) {
                $db->send_response(400, 'error', null, 'Path ID is required');
                break;
            }

            $userEnrollment = $enrollment->getUserEnrollment($path_id, $user_id);
            if (!$userEnrollment) {
                $db->send_response(404, 'error', null, 'Not enrolled in this path');
                break;
            }

            $data = $progress->getProgressBySection($userEnrollment['enrollment_id']);
            $db->send_response(200, 'success', $data, 'Progress retrieved');
            break;

        case 'start-item':
            $path_id = (int) ($input['path_id'] ?? 0);
            $item_id = (int) ($input['item_id'] ?? 0);
            if (!$path_id || !$item_id) {
                $db->send_response(400, 'error', null, 'Path ID and Item ID are required');
                break;
            }

            $userEnrollment = $enrollment->getUserEnrollment($path_id, $user_id);
            if (!$userEnrollment) {
                $db->send_response(404, 'error', null, 'Not enrolled in this path');
                break;
            }

            $progress->enrollment_id = $userEnrollment['enrollment_id'];
            $progress->item_id = $item_id;
            $progress->user_id = $user_id;

            $result = $progress->startItem();
            if ($result['success']) {
                $db->send_response(200, 'success', $result['data'], 'Item started');
            } else {
                $db->send_response(400, 'error', null, $result['error']);
            }
            break;

        case 'complete-item':
            $path_id = (int) ($input['path_id'] ?? 0);
            $item_id = (int) ($input['item_id'] ?? 0);
            if (!$path_id || !$item_id) {
                $db->send_response(400, 'error', null, 'Path ID and Item ID are required');
                break;
            }

            $userEnrollment = $enrollment->getUserEnrollment($path_id, $user_id);
            if (!$userEnrollment) {
                $db->send_response(404, 'error', null, 'Not enrolled in this path');
                break;
            }

            $progress->enrollment_id = $userEnrollment['enrollment_id'];
            $progress->item_id = $item_id;
            $progress->user_id = $user_id;

            $score = $input['score'] ?? null;
            $result = $progress->completeItem($score);

            if ($result['success']) {
                $message = 'Item completed';
                if ($result['path_completed'] ?? false) {
                    $message = 'Congratulations! You have completed the learning path!';
                }
                $db->send_response(200, 'success', $result, $message);
            } else {
                $db->send_response(400, 'error', null, $result['error']);
            }
            break;

        case 'skip-item':
            $path_id = (int) ($input['path_id'] ?? 0);
            $item_id = (int) ($input['item_id'] ?? 0);
            if (!$path_id || !$item_id) {
                $db->send_response(400, 'error', null, 'Path ID and Item ID are required');
                break;
            }

            $userEnrollment = $enrollment->getUserEnrollment($path_id, $user_id);
            if (!$userEnrollment) {
                $db->send_response(404, 'error', null, 'Not enrolled in this path');
                break;
            }

            $progress->enrollment_id = $userEnrollment['enrollment_id'];
            $progress->item_id = $item_id;

            $result = $progress->skipItem();
            if ($result['success']) {
                $db->send_response(200, 'success', null, 'Item skipped');
            } else {
                $db->send_response(400, 'error', null, $result['error']);
            }
            break;

        case 'update-time-spent':
            $path_id = (int) ($input['path_id'] ?? 0);
            $item_id = (int) ($input['item_id'] ?? 0);
            $minutes = (int) ($input['minutes'] ?? 0);
            if (!$path_id || !$item_id || !$minutes) {
                $db->send_response(400, 'error', null, 'Path ID, Item ID, and Minutes are required');
                break;
            }

            $userEnrollment = $enrollment->getUserEnrollment($path_id, $user_id);
            if (!$userEnrollment) {
                $db->send_response(404, 'error', null, 'Not enrolled in this path');
                break;
            }

            $progress->enrollment_id = $userEnrollment['enrollment_id'];
            $progress->item_id = $item_id;
            $progress->updateTimeSpent($minutes);
            $db->send_response(200, 'success', null, 'Time updated');
            break;

        case 'reset-item-progress':
            $enrollment_id = (int) ($input['enrollment_id'] ?? 0);
            $item_id = (int) ($input['item_id'] ?? 0);
            if (!$enrollment_id || !$item_id) {
                $db->send_response(400, 'error', null, 'Enrollment ID and Item ID are required');
                break;
            }
            $progress->enrollment_id = $enrollment_id;
            $progress->item_id = $item_id;
            $progress->resetProgress();
            $db->send_response(200, 'success', null, 'Progress reset');
            break;

        case 'unlock-item':
            $enrollment_id = (int) ($input['enrollment_id'] ?? 0);
            $item_id = (int) ($input['item_id'] ?? 0);
            if (!$enrollment_id || !$item_id) {
                $db->send_response(400, 'error', null, 'Enrollment ID and Item ID are required');
                break;
            }
            $progress->enrollment_id = $enrollment_id;
            $progress->item_id = $item_id;
            $progress->unlockItem();
            $db->send_response(200, 'success', null, 'Item unlocked');
            break;

        // ==========================================
        // REVIEWS & RATINGS
        // ==========================================

        case 'get-reviews':
            $path_id = (int) ($input['path_id'] ?? 0);
            if (!$path_id) {
                $db->send_response(400, 'error', null, 'Path ID is required');
                break;
            }

            $review->page = (int) ($input['page'] ?? 1);
            $review->per_page = (int) ($input['per_page'] ?? 10);

            $data = $review->getByPathId($path_id);
            $db->send_response(200, 'success', $data, 'Reviews retrieved');
            break;

        case 'get-rating-summary':
            $path_id = (int) ($input['path_id'] ?? 0);
            if (!$path_id) {
                $db->send_response(400, 'error', null, 'Path ID is required');
                break;
            }
            $data = $review->getRatingSummary($path_id);
            $db->send_response(200, 'success', $data, 'Rating summary retrieved');
            break;

        case 'create-review':
            $path_id = (int) ($input['path_id'] ?? 0);
            $rating = (int) ($input['rating'] ?? 0);
            if (!$path_id || $rating < 1 || $rating > 5) {
                $db->send_response(400, 'error', null, 'Path ID and valid rating (1-5) are required');
                break;
            }

            $review->path_id = $path_id;
            $review->user_id = $user_id;
            $review->rating = $rating;
            $review->title = $input['title'] ?? '';
            $review->review_text = $input['review_text'] ?? '';

            $result = $review->create();
            if ($result['success']) {
                $db->send_response(201, 'success', $result['data'], 'Review submitted');
            } else {
                $db->send_response(400, 'error', null, $result['error']);
            }
            break;

        case 'update-review':
            $review_id = (int) ($input['review_id'] ?? 0);
            if (!$review_id) {
                $db->send_response(400, 'error', null, 'Review ID is required');
                break;
            }

            $review->review_id = $review_id;

            if (isset($input['rating'])) $review->rating = $input['rating'];
            if (isset($input['title'])) $review->title = $input['title'];
            if (isset($input['review_text'])) $review->review_text = $input['review_text'];

            $review->update();
            $db->send_response(200, 'success', null, 'Review updated');
            break;

        case 'delete-review':
            $review_id = (int) ($input['review_id'] ?? 0);
            if (!$review_id) {
                $db->send_response(400, 'error', null, 'Review ID is required');
                break;
            }
            $review->review_id = $review_id;
            $review->delete();
            $db->send_response(200, 'success', null, 'Review deleted');
            break;

        case 'approve-review':
            $review_id = (int) ($input['review_id'] ?? 0);
            if (!$review_id) {
                $db->send_response(400, 'error', null, 'Review ID is required');
                break;
            }
            $review->review_id = $review_id;
            $review->approve();
            $db->send_response(200, 'success', null, 'Review approved');
            break;

        case 'reject-review':
            $review_id = (int) ($input['review_id'] ?? 0);
            if (!$review_id) {
                $db->send_response(400, 'error', null, 'Review ID is required');
                break;
            }
            $review->review_id = $review_id;
            $review->reject($input['reason'] ?? null);
            $db->send_response(200, 'success', null, 'Review rejected');
            break;

        case 'respond-to-review':
            $review_id = (int) ($input['review_id'] ?? 0);
            $response = $input['response'] ?? '';
            if (!$review_id || !$response) {
                $db->send_response(400, 'error', null, 'Review ID and Response are required');
                break;
            }
            $review->review_id = $review_id;
            $review->respond($response);
            $db->send_response(200, 'success', null, 'Response added');
            break;

        case 'mark-review-helpful':
            $review_id = (int) ($input['review_id'] ?? 0);
            if (!$review_id) {
                $db->send_response(400, 'error', null, 'Review ID is required');
                break;
            }
            $review->review_id = $review_id;
            $review->markHelpful();
            $db->send_response(200, 'success', null, 'Marked as helpful');
            break;

        case 'report-review':
            $review_id = (int) ($input['review_id'] ?? 0);
            $reason = $input['reason'] ?? '';
            if (!$review_id) {
                $db->send_response(400, 'error', null, 'Review ID is required');
                break;
            }
            $review->review_id = $review_id;
            $review->report();
            $db->send_response(200, 'success', null, 'Review reported');
            break;

        case 'get-pending-reviews':
            $review->page = (int) ($input['page'] ?? 1);
            $review->per_page = (int) ($input['per_page'] ?? 20);
            $data = $review->getPendingReviews();
            $db->send_response(200, 'success', $data, 'Pending reviews retrieved');
            break;

        // ==========================================
        // ASSIGNMENTS
        // ==========================================

        case 'preview-assignment':
            $path_id = (int) ($input['path_id'] ?? 0);
            $assignment_type = $input['assignment_type'] ?? '';
            if (!$path_id || !$assignment_type) {
                $db->send_response(400, 'error', null, 'Path ID and Assignment Type are required');
                break;
            }

            $assignment->path_id = $path_id;
            $assignment->assignment_type = $assignment_type;

            // Set target based on assignment type
            switch ($assignment_type) {
                case 'department':
                    $assignment->department_id = isset($input['department_id']) ? (int)$input['department_id'] : null;
                    if (!$assignment->department_id) {
                        $db->send_response(400, 'error', null, 'Department ID is required for department assignment');
                        break 2;
                    }
                    break;

                case 'team':
                    $assignment->team_id = isset($input['team_id']) ? (int)$input['team_id'] : null;
                    if (!$assignment->team_id) {
                        $db->send_response(400, 'error', null, 'Team ID is required for team assignment');
                        break 2;
                    }
                    break;

                case 'branch':
                    $assignment->branch_id = isset($input['branch_id']) ? (int)$input['branch_id'] : null;
                    if (!$assignment->branch_id) {
                        $db->send_response(400, 'error', null, 'Branch ID is required for branch assignment');
                        break 2;
                    }
                    break;

                case 'users':
                    $assignment->user_ids = isset($input['user_ids']) ? array_map('intval', (array)$input['user_ids']) : [];
                    if (empty($assignment->user_ids)) {
                        $db->send_response(400, 'error', null, 'User IDs are required for user assignment');
                        break 2;
                    }
                    break;

                default:
                    $db->send_response(400, 'error', null, 'Invalid assignment type');
                    break 2;
            }

            $result = $assignment->preview();

            if ($result['success']) {
                $db->send_response(200, 'success', $result, 'Preview generated');
            } else {
                $db->send_response(400, 'error', null, $result['error'] ?? 'Failed to generate preview');
            }
            break;

        case 'create-assignment':
            $path_id = (int) ($input['path_id'] ?? 0);
            $assignment_type = $input['assignment_type'] ?? '';
            if (!$path_id || !$assignment_type) {
                $db->send_response(400, 'error', null, 'Path ID and Assignment Type are required');
                break;
            }

            $assignment->path_id = $path_id;
            $assignment->assignment_type = $assignment_type;

            // Set target based on assignment type
            switch ($assignment_type) {
                case 'department':
                    $assignment->department_id = isset($input['department_id']) ? (int)$input['department_id'] : null;
                    if (!$assignment->department_id) {
                        $db->send_response(400, 'error', null, 'Department ID is required');
                        break 2;
                    }
                    break;

                case 'team':
                    $assignment->team_id = isset($input['team_id']) ? (int)$input['team_id'] : null;
                    if (!$assignment->team_id) {
                        $db->send_response(400, 'error', null, 'Team ID is required');
                        break 2;
                    }
                    break;

                case 'branch':
                    $assignment->branch_id = isset($input['branch_id']) ? (int)$input['branch_id'] : null;
                    if (!$assignment->branch_id) {
                        $db->send_response(400, 'error', null, 'Branch ID is required');
                        break 2;
                    }
                    break;

                case 'users':
                    $assignment->user_ids = isset($input['user_ids']) ? array_map('intval', (array)$input['user_ids']) : [];
                    if (empty($assignment->user_ids)) {
                        $db->send_response(400, 'error', null, 'User IDs are required');
                        break 2;
                    }
                    break;

                default:
                    $db->send_response(400, 'error', null, 'Invalid assignment type');
                    break 2;
            }

            // Set options
            $assignment->due_date = $input['due_date'] ?? null;
            $assignment->due_days_from_assignment = isset($input['due_days_from_assignment'])
                ? (int)$input['due_days_from_assignment']
                : null;
            $assignment->priority = $input['priority'] ?? 'normal';
            $assignment->is_mandatory = filter_var($input['is_mandatory'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $assignment->send_notification = filter_var($input['send_notification'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $assignment->send_reminder = filter_var($input['send_reminder'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $assignment->reminder_days_before = isset($input['reminder_days_before'])
                ? (int)$input['reminder_days_before']
                : 7;
            $assignment->assignment_notes = $input['assignment_notes'] ?? '';
            $assignment->assigned_by = $user_id;

            $result = $assignment->create();
            if ($result['success']) {
                $message = sprintf('Assignment created. %d users enrolled.', $result['enrollments_created']);
                $db->send_response(201, 'success', $result, $message);
            } else {
                $db->send_response(400, 'error', null, $result['error']);
            }
            break;

        case 'get-assignments':
            $path_id = (int) ($input['path_id'] ?? 0);
            if (!$path_id) {
                $db->send_response(400, 'error', null, 'Path ID is required');
                break;
            }
            $data = $assignment->list($path_id);
            $db->send_response(200, 'success', $data, 'Assignments retrieved');
            break;

        case 'cancel-assignment':
            $assignment_id = (int) ($input['assignment_id'] ?? 0);
            if (!$assignment_id) {
                $db->send_response(400, 'error', null, 'Assignment ID is required');
                break;
            }
            $assignment->assignment_id = $assignment_id;
            $assignment->cancel();
            $db->send_response(200, 'success', null, 'Assignment cancelled');
            break;

        case 'pause-assignment':
            $assignment_id = (int) ($input['assignment_id'] ?? 0);
            if (!$assignment_id) {
                $db->send_response(400, 'error', null, 'Assignment ID is required');
                break;
            }
            $assignment->assignment_id = $assignment_id;
            $assignment->pause();
            $db->send_response(200, 'success', null, 'Assignment paused');
            break;

        case 'resume-assignment':
            $assignment_id = (int) ($input['assignment_id'] ?? 0);
            if (!$assignment_id) {
                $db->send_response(400, 'error', null, 'Assignment ID is required');
                break;
            }
            $assignment->assignment_id = $assignment_id;
            $assignment->resume();
            $db->send_response(200, 'success', null, 'Assignment resumed');
            break;

        case 'get-assignment-statistics':
            $assignment_id = (int) ($input['assignment_id'] ?? 0);
            if (!$assignment_id) {
                $db->send_response(400, 'error', null, 'Assignment ID is required');
                break;
            }
            $data = $assignment->getStatistics($assignment_id);
            $db->send_response(200, 'success', $data, 'Statistics retrieved');
            break;

        case 'send-assignment-reminders':
            $assignment_id = (int) ($input['assignment_id'] ?? 0);
            if (!$assignment_id) {
                $db->send_response(400, 'error', null, 'Assignment ID is required');
                break;
            }
            $assignment->assignment_id = $assignment_id;
            $result = $assignment->sendReminders();
            $db->send_response(200, 'success', $result, 'Reminders sent');
            break;

        // ==========================================
        // SEARCH USERS FOR ENROLLMENT
        // ==========================================

        case 'search-users-for-enrollment':
            $filters = [
                'search' => $input['search'] ?? null,
                'department_id' => $input['department_id'] ?? null,
                'branch_id' => $input['branch_id'] ?? null,
                'team_id' => $input['team_id'] ?? null,
                'exclude_path_id' => $input['path_id'] ?? null,
                'limit' => $input['limit'] ?? 100
            ];
            $data = $enrollment->searchUsersForEnrollment(array_filter($filters, fn($v) => $v !== null));
            $db->send_response(200, 'success', $data, 'Users retrieved');
            break;

        // ==========================================
        // BUILDER HELPERS - COURSES & ASSESSMENTS
        // ==========================================

        case 'get-available-courses':
            $search = $input['search'] ?? '';
            $status = $input['status'] ?? 'published';
            $limit = (int) ($input['limit'] ?? 100);

            $sql = "SELECT 
                        c.course_id,
                        c.title,
                        c.summary as short_description,
                        c.thumbnail_url,
                        c.duration_minutes as estimated_duration_minutes,
                        c.level as skill_level,
                        c.status,
                        cat.name as category_name,
                        (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.course_id AND l.is_active = 1) as lessons_count
                    FROM courses c
                    LEFT JOIN categories cat ON cat.category_id = c.category_id
                    WHERE c.tenant_id = ? AND c.is_active = 1";

            $params = [$tenant_id];
            $types = "i";

            if ($status) {
                $sql .= " AND c.status = ?";
                $params[] = $status;
                $types .= "s";
            }

            if ($search) {
                $sql .= " AND (c.title LIKE ? OR c.short_description LIKE ?)";
                $searchTerm = "%" . $search . "%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $types .= "ss";
            }

            $sql .= " ORDER BY c.title ASC LIMIT ?";
            $params[] = $limit;
            $types .= "i";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();

            $courses = [];
            while ($row = $result->fetch_assoc()) {
                $row['course_id'] = (int) $row['course_id'];
                $row['estimated_duration_minutes'] = (int) $row['estimated_duration_minutes'];
                $row['lessons_count'] = (int) $row['lessons_count'];
                $courses[] = $row;
            }

            $db->send_response(200, 'success', $courses, 'Courses retrieved');
            break;

        case 'get-available-assessments':
            $search = $input['search'] ?? '';
            $status = $input['status'] ?? 'published';
            $limit = (int) ($input['limit'] ?? 100);

            $sql = "SELECT 
                        a.assessment_id,
                        a.title,
                        a.description,
                        a.time_limit_minutes,
                        a.pass_percentage as passing_score,
                        a.total_questions as questions_count,
                        a.assessment_type,
                        (SELECT COUNT(*) FROM questions aq WHERE aq.assessment_id = a.assessment_id AND aq.is_active = 1) as questions_count
                    FROM assessments a WHERE a.tenant_id = ? AND a.is_active = 1";

            $params = [$tenant_id];
            $types = "i";

            if ($status) {
                $sql .= " AND a.status = ?";
                $params[] = $status;
                $types .= "s";
            }

            if ($search) {
                $sql .= " AND (a.title LIKE ? OR a.description LIKE ?)";
                $searchTerm = "%" . $search . "%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $types .= "ss";
            }

            $sql .= " ORDER BY a.title ASC LIMIT ?";
            $params[] = $limit;
            $types .= "i";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();

            $assessments = [];
            while ($row = $result->fetch_assoc()) {
                $row['assessment_id'] = (int) $row['assessment_id'];
                $row['time_limit_minutes'] = (int) $row['time_limit_minutes'];
                $row['passing_score'] = (int) $row['passing_score'];
                $row['questions_count'] = (int) $row['questions_count'];
                $assessments[] = $row;
            }

            $db->send_response(200, 'success', $assessments, 'Assessments retrieved');
            break;

        default:
            $db->send_response(400, 'error', null,
                'Invalid action. Available actions: ' .
                // Path CRUD
                'create-path, update-path, get-path, get-path-by-slug, get-paths, get-catalog, delete-path, ' .
                'publish-path, archive-path, duplicate-path, get-path-statistics, get-path-details, ' .
                // Sections
                'create-section, update-section, get-sections, delete-section, reorder-sections, ' .
                // Items
                'create-item, add-course, add-assessment, add-external-link, update-item, get-items, delete-item, reorder-items, ' .
                // Enrollments
                'enroll, enroll-user, bulk-enroll, get-enrollments, get-my-enrollment, get-my-enrollments, ' .
                'start-path, withdraw, suspend-enrollment, resume-enrollment, extend-due-date, ' .
                'get-enrollment-statistics, check-prerequisites, search-users-for-enrollment, ' .
                // Progress
                'get-my-progress, start-item, complete-item, skip-item, update-time-spent, reset-item-progress, unlock-item, ' .
                // Reviews
                'get-reviews, get-rating-summary, create-review, update-review, delete-review, ' .
                'approve-review, reject-review, respond-to-review, mark-review-helpful, report-review, get-pending-reviews, ' .
                // Assignments
                'create-assignment, get-assignments, cancel-assignment, pause-assignment, resume-assignment, ' .
                'get-assignment-statistics, send-assignment-reminders, ' .
                // Builder helpers
                'get-available-courses, get-available-assessments');
    }

} catch (Exception $e) {
    error_log('Learning Paths API Error: ' . $e->getMessage());
    $db->send_response(500, 'error', null, $e->getMessage());
}