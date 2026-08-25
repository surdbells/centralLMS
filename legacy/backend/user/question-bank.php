<?php

/**
 * Question Bank API Endpoint
 * Handles question banks, categories, questions, options, and tags
 * For Multi-Tenant LMS - Admin Portal
 *
 * All requests are POST with JSON body containing auth + action + params
 *
 * QUESTION BANKS:
 * - create-bank          - Create new question bank
 * - update-bank          - Update existing bank
 * - delete-bank          - Archive bank
 * - get-bank             - Get single bank with stats
 * - get-banks            - Get all banks
 * - get-bank-stats       - Get bank statistics
 *
 * CATEGORIES:
 * - create-category      - Create category in bank
 * - update-category      - Update category
 * - delete-category      - Delete category
 * - get-categories       - Get categories for bank (hierarchical)
 *
 * TAGS:
 * - create-tag           - Create new tag
 * - delete-tag           - Delete tag
 * - get-tags             - Get all tags
 *
 * QUESTIONS:
 * - create-question      - Create new question
 * - update-question      - Update question
 * - delete-question      - Archive question
 * - permanent-delete     - Permanently delete question
 * - get-question         - Get single question with options
 * - get-questions        - Get questions with filters
 * - clone-question       - Clone a question
 * - bulk-delete          - Archive multiple questions
 * - bulk-move            - Move questions to category
 *
 * MEDIA:
 * - add-media            - Add media to question
 * - delete-media         - Delete question media
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
require_once __DIR__ . '/../class/QuestionBank.php';
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

    // Initialize QuestionBank manager
    $questionBank = new QuestionBank($conn);
    $questionBank->setTenant($tenant_id);
    $questionBank->setUser($user_id);

    switch ($action) {
        // ==========================================
        // QUESTION BANKS
        // ==========================================

        case 'create-bank':
            $result = $questionBank->createBank($input);
            $code = $result['success'] ? 201 : 400;
            $db->send_response($code, $result['success'] ? 'success' : 'error', 
                $result['success'] ? ['bank_id' => $result['bank_id']] : null, 
                $result['message']);
            break;

        case 'update-bank':
            $bank_id = (int) ($input['bank_id'] ?? 0);
            if (!$bank_id) {
                $db->send_response(400, 'error', null, 'Bank ID is required');
                exit;
            }
            $result = $questionBank->updateBank($bank_id, $input);
            $db->send_response($result['success'] ? 200 : 400, 
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        case 'delete-bank':
            $bank_id = (int) ($input['bank_id'] ?? 0);
            if (!$bank_id) {
                $db->send_response(400, 'error', null, 'Bank ID is required');
                exit;
            }
            $result = $questionBank->deleteBank($bank_id);
            $db->send_response($result['success'] ? 200 : 400, 
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        case 'get-bank':
            $bank_id = (int) ($input['bank_id'] ?? 0);
            if (!$bank_id) {
                $db->send_response(400, 'error', null, 'Bank ID is required');
                exit;
            }
            $bank = $questionBank->getBank($bank_id);
            if ($bank) {
                $db->send_response(200, 'success', $bank, 'Bank retrieved');
            } else {
                $db->send_response(404, 'error', null, 'Bank not found');
            }
            break;

        case 'get-banks':
            $filters = [
                'status' => $input['status'] ?? 'active',
                'search' => $input['search'] ?? ''
            ];
            $banks = $questionBank->getBanks($filters);
            $db->send_response(200, 'success', $banks, 'Banks retrieved');
            break;

        case 'get-bank-stats':
            $bank_id = (int) ($input['bank_id'] ?? 0);
            if (!$bank_id) {
                $db->send_response(400, 'error', null, 'Bank ID is required');
                exit;
            }
            $stats = $questionBank->getBankStats($bank_id);
            $db->send_response(200, 'success', $stats, 'Bank stats retrieved');
            break;

        // ==========================================
        // CATEGORIES
        // ==========================================

        case 'create-category':
            $result = $questionBank->createCategory($input);
            $code = $result['success'] ? 201 : 400;
            $db->send_response($code, $result['success'] ? 'success' : 'error', 
                $result['success'] ? ['category_id' => $result['category_id']] : null, 
                $result['message']);
            break;

        case 'update-category':
            $category_id = (int) ($input['category_id'] ?? 0);
            if (!$category_id) {
                $db->send_response(400, 'error', null, 'Category ID is required');
                exit;
            }
            $result = $questionBank->updateCategory($category_id, $input);
            $db->send_response($result['success'] ? 200 : 400, 
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        case 'delete-category':
            $category_id = (int) ($input['category_id'] ?? 0);
            if (!$category_id) {
                $db->send_response(400, 'error', null, 'Category ID is required');
                exit;
            }
            $result = $questionBank->deleteCategory($category_id);
            $db->send_response($result['success'] ? 200 : 400, 
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        case 'get-categories':
            $bank_id = (int) ($input['bank_id'] ?? 0);
            if (!$bank_id) {
                $db->send_response(400, 'error', null, 'Bank ID is required');
                exit;
            }
            $categories = $questionBank->getCategories($bank_id);
            $db->send_response(200, 'success', $categories, 'Categories retrieved');
            break;

        // ==========================================
        // TAGS
        // ==========================================

        case 'create-tag':
            $result = $questionBank->createTag($input);
            $code = $result['success'] ? 201 : 400;
            $db->send_response($code, $result['success'] ? 'success' : 'error', 
                $result['success'] ? ['tag_id' => $result['tag_id']] : null, 
                $result['message']);
            break;

        case 'delete-tag':
            $tag_id = (int) ($input['tag_id'] ?? 0);
            if (!$tag_id) {
                $db->send_response(400, 'error', null, 'Tag ID is required');
                exit;
            }
            $result = $questionBank->deleteTag($tag_id);
            $db->send_response($result['success'] ? 200 : 400, 
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        case 'get-tags':
            $tags = $questionBank->getTags();
            $db->send_response(200, 'success', $tags, 'Tags retrieved');
            break;

        // ==========================================
        // QUESTIONS
        // ==========================================

        case 'create-question':
            $result = $questionBank->createQuestion($input);
            $code = $result['success'] ? 201 : 400;
            $db->send_response($code, $result['success'] ? 'success' : 'error', 
                $result['success'] ? ['question_id' => $result['question_id']] : null, 
                $result['message']);
            break;

        case 'update-question':
            $question_id = (int) ($input['question_id'] ?? 0);
            if (!$question_id) {
                $db->send_response(400, 'error', null, 'Question ID is required');
                exit;
            }
            $result = $questionBank->updateQuestion($question_id, $input);
            $db->send_response($result['success'] ? 200 : 400, 
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        case 'delete-question':
            $question_id = (int) ($input['question_id'] ?? 0);
            if (!$question_id) {
                $db->send_response(400, 'error', null, 'Question ID is required');
                exit;
            }
            $result = $questionBank->deleteQuestion($question_id);
            $db->send_response($result['success'] ? 200 : 400, 
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        case 'permanent-delete':
            $question_id = (int) ($input['question_id'] ?? 0);
            if (!$question_id) {
                $db->send_response(400, 'error', null, 'Question ID is required');
                exit;
            }
            $result = $questionBank->permanentDeleteQuestion($question_id);
            $db->send_response($result['success'] ? 200 : 400, 
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        case 'get-question':
            $question_id = (int) ($input['question_id'] ?? 0);
            if (!$question_id) {
                $db->send_response(400, 'error', null, 'Question ID is required');
                exit;
            }
            $question = $questionBank->getQuestion($question_id);
            if ($question) {
                $db->send_response(200, 'success', $question, 'Question retrieved');
            } else {
                $db->send_response(404, 'error', null, 'Question not found');
            }
            break;

        case 'get-questions':
            $filters = [
                'bank_id' => $input['bank_id'] ?? null,
                'category_id' => $input['category_id'] ?? null,
                'question_type' => $input['question_type'] ?? null,
                'difficulty' => $input['difficulty'] ?? null,
                'status' => $input['status'] ?? 'active',
                'search' => $input['search'] ?? '',
                'tag_ids' => $input['tag_ids'] ?? [],
                'page' => $input['page'] ?? 1,
                'limit' => $input['limit'] ?? 20
            ];
            $result = $questionBank->getQuestions($filters);
            $db->send_response(200, 'success', $result, 'Questions retrieved');
            break;

        case 'clone-question':
            $question_id = (int) ($input['question_id'] ?? 0);
            $target_bank_id = !empty($input['target_bank_id']) ? (int) $input['target_bank_id'] : null;
            if (!$question_id) {
                $db->send_response(400, 'error', null, 'Question ID is required');
                exit;
            }
            $result = $questionBank->cloneQuestion($question_id, $target_bank_id);
            $code = $result['success'] ? 201 : 400;
            $db->send_response($code, $result['success'] ? 'success' : 'error', 
                $result['success'] ? ['question_id' => $result['question_id']] : null, 
                $result['message']);
            break;

        case 'bulk-delete':
            $question_ids = $input['question_ids'] ?? [];
            if (empty($question_ids)) {
                $db->send_response(400, 'error', null, 'Question IDs are required');
                exit;
            }
            $result = $questionBank->bulkDeleteQuestions($question_ids);
            $db->send_response(200, 'success', ['affected_count' => $result['affected_count']], $result['message']);
            break;

        case 'bulk-move':
            $question_ids = $input['question_ids'] ?? [];
            $category_id = isset($input['category_id']) ? (int) $input['category_id'] : null;
            if (empty($question_ids)) {
                $db->send_response(400, 'error', null, 'Question IDs are required');
                exit;
            }
            $result = $questionBank->bulkMoveQuestions($question_ids, $category_id);
            $db->send_response(200, 'success', ['affected_count' => $result['affected_count']], $result['message']);
            break;

        // ==========================================
        // MEDIA
        // ==========================================

        case 'add-media':
            $question_id = (int) ($input['question_id'] ?? 0);
            if (!$question_id) {
                $db->send_response(400, 'error', null, 'Question ID is required');
                exit;
            }
            $result = $questionBank->addQuestionMedia($question_id, $input);
            $code = $result['success'] ? 201 : 400;
            $db->send_response($code, $result['success'] ? 'success' : 'error', 
                $result['success'] ? ['media_id' => $result['media_id']] : null, 
                $result['message']);
            break;

        case 'delete-media':
            $media_id = (int) ($input['media_id'] ?? 0);
            if (!$media_id) {
                $db->send_response(400, 'error', null, 'Media ID is required');
                exit;
            }
            $result = $questionBank->deleteQuestionMedia($media_id);
            $db->send_response($result['success'] ? 200 : 400, 
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        default:
            $db->send_response(400, 'error', null, 
                'Invalid action. Available: create-bank, update-bank, delete-bank, get-bank, get-banks, ' .
                'get-bank-stats, create-category, update-category, delete-category, get-categories, ' .
                'create-tag, delete-tag, get-tags, create-question, update-question, delete-question, ' .
                'permanent-delete, get-question, get-questions, clone-question, bulk-delete, bulk-move, ' .
                'add-media, delete-media');
    }

} catch (Exception $e) {
    error_log('Question Bank API Error: ' . $e->getMessage());
    $db->send_response(500, 'error', null, 'Server error: ' . $e->getMessage());
}
