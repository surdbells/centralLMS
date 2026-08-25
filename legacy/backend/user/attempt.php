<?php

/**
 * Attempt API Endpoint
 * Handles learner assessment attempts, answers, and results
 * For Multi-Tenant LMS - Learner Portal
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
require_once __DIR__ . '/../class/Attempt.php';
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

    // Initialize Attempt manager
    $attempt = new Attempt($conn);
    $attempt->setTenant($tenant_id);
    $attempt->setUser($user_id);

    switch ($action) {
        // ==========================================
        // LEARNER ASSESSMENTS
        // ==========================================

        case 'get-my-assessments':
            $filters = [
                'status' => $input['status'] ?? null,
                'search' => $input['search'] ?? '',
                'page' => $input['page'] ?? 1,
                'limit' => $input['limit'] ?? 20
            ];
            $assessments = $attempt->getMyAssessments($filters);
            $db->send_response(200, 'success', $assessments, 'Assessments retrieved');
            break;

        case 'get-assessment':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                exit;
            }
            $data = $attempt->getAssessmentForLearner($assessment_id);
            if ($data) {
                $db->send_response(200, 'success', $data, 'Assessment retrieved');
            } else {
                $db->send_response(404, 'error', null, 'Assessment not found');
            }
            break;

        // ==========================================
        // ATTEMPT MANAGEMENT
        // ==========================================

        case 'start-attempt':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                exit;
            }
            $deviceInfo = [
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'browser_name' => $input['browser_name'] ?? null,
                'browser_version' => $input['browser_version'] ?? null,
                'os_name' => $input['os_name'] ?? null,
                'device_type' => $input['device_type'] ?? 'desktop',
                'screen_width' => $input['screen_width'] ?? null,
                'screen_height' => $input['screen_height'] ?? null
            ];
            $result = $attempt->startAttempt($assessment_id, $deviceInfo);
            $code = $result['success'] ? 200 : 400;
            $db->send_response($code, $result['success'] ? 'success' : 'error',
                $result['success'] ? [
                    'attempt_id' => $result['attempt_id'],
                    'is_resume' => $result['is_resume'] ?? false
                ] : null,
                $result['message']);
            break;

        case 'get-attempt':
            $attempt_id = (int) ($input['attempt_id'] ?? 0);
            if (!$attempt_id) {
                $db->send_response(400, 'error', null, 'Attempt ID is required');
                exit;
            }
            $data = $attempt->getAttemptForPlayer($attempt_id);
            if ($data && !isset($data['error'])) {
                $db->send_response(200, 'success', $data, 'Attempt loaded');
            } elseif (isset($data['error'])) {
                $db->send_response(400, 'error', ['status' => $data['status']], $data['error']);
            } else {
                $db->send_response(404, 'error', null, 'Attempt not found');
            }
            break;

        case 'submit-attempt':
            $attempt_id = (int) ($input['attempt_id'] ?? 0);
            if (!$attempt_id) {
                $db->send_response(400, 'error', null, 'Attempt ID is required');
                exit;
            }
            $result = $attempt->submitAttempt($attempt_id);
            $code = $result['success'] ? 200 : 400;
            $db->send_response($code, $result['success'] ? 'success' : 'error',
                $result['success'] ? $result['result'] : null,
                $result['message']);
            break;

        // ==========================================
        // ANSWERS
        // ==========================================

        case 'save-answer':
            $attempt_id = (int) ($input['attempt_id'] ?? 0);
            $question_id = (int) ($input['question_id'] ?? 0);
            $answer_data = $input['answer_data'] ?? [];
            if (!$attempt_id || !$question_id) {
                $db->send_response(400, 'error', null, 'Attempt ID and Question ID are required');
                exit;
            }
            $result = $attempt->saveAnswer($attempt_id, $question_id, $answer_data);
            $db->send_response($result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        case 'mark-for-review':
            $attempt_id = (int) ($input['attempt_id'] ?? 0);
            $question_id = (int) ($input['question_id'] ?? 0);
            $marked = (bool) ($input['marked'] ?? true);
            if (!$attempt_id || !$question_id) {
                $db->send_response(400, 'error', null, 'Attempt ID and Question ID are required');
                exit;
            }
            $result = $attempt->markForReview($attempt_id, $question_id, $marked);
            $db->send_response($result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        case 'update-navigation':
            $attempt_id = (int) ($input['attempt_id'] ?? 0);
            $question_index = (int) ($input['question_index'] ?? 0);
            if (!$attempt_id) {
                $db->send_response(400, 'error', null, 'Attempt ID is required');
                exit;
            }
            $result = $attempt->updateNavigation($attempt_id, $question_index);
            $db->send_response(200, 'success', null, 'Navigation updated');
            break;

        case 'update-time':
            $attempt_id = (int) ($input['attempt_id'] ?? 0);
            $time_remaining = (int) ($input['time_remaining'] ?? 0);
            if (!$attempt_id) {
                $db->send_response(400, 'error', null, 'Attempt ID is required');
                exit;
            }
            $result = $attempt->updateTimeRemaining($attempt_id, $time_remaining);
            $db->send_response(200, 'success', null, 'Time updated');
            break;

        // ==========================================
        // AUTO-SAVE
        // ==========================================

        case 'auto-save':
            $attempt_id = (int) ($input['attempt_id'] ?? 0);
            $draft_data = $input['draft_data'] ?? [];
            if (!$attempt_id) {
                $db->send_response(400, 'error', null, 'Attempt ID is required');
                exit;
            }
            $result = $attempt->autoSave($attempt_id, $draft_data);
            $db->send_response($result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error',
                $result['success'] ? ['saved_at' => $result['saved_at']] : null,
                $result['success'] ? 'Auto-saved' : $result['message']);
            break;

        case 'recover-draft':
            $attempt_id = (int) ($input['attempt_id'] ?? 0);
            if (!$attempt_id) {
                $db->send_response(400, 'error', null, 'Attempt ID is required');
                exit;
            }
            $draft = $attempt->recoverDraft($attempt_id);
            if ($draft) {
                $db->send_response(200, 'success', $draft, 'Draft recovered');
            } else {
                $db->send_response(404, 'error', null, 'No draft found');
            }
            break;

        // ==========================================
        // RESULTS
        // ==========================================

        case 'get-results':
            $attempt_id = (int) ($input['attempt_id'] ?? 0);
            if (!$attempt_id) {
                $db->send_response(400, 'error', null, 'Attempt ID is required');
                exit;
            }
            $results = $attempt->getAttemptResults($attempt_id);
            if ($results) {
                $db->send_response(200, 'success', $results, 'Results retrieved');
            } else {
                $db->send_response(404, 'error', null, 'Results not available');
            }
            break;

        case 'get-attempt-history':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                exit;
            }
            $history = $attempt->getAttemptHistory($assessment_id);
            $db->send_response(200, 'success', $history, 'History retrieved');
            break;

        // ==========================================
        // TIMELINE
        // ==========================================

        case 'log-event':
            $attempt_id = (int) ($input['attempt_id'] ?? 0);
            $event_type = $input['event_type'] ?? '';
            $question_id = !empty($input['question_id']) ? (int) $input['question_id'] : null;
            $event_data = $input['event_data'] ?? null;
            if (!$attempt_id || !$event_type) {
                $db->send_response(400, 'error', null, 'Attempt ID and event type are required');
                exit;
            }
            $attempt->logTimelineEvent($attempt_id, $event_type, $question_id, $event_data);
            $db->send_response(200, 'success', null, 'Event logged');
            break;

        case 'get-timeline':
            $attempt_id = (int) ($input['attempt_id'] ?? 0);
            if (!$attempt_id) {
                $db->send_response(400, 'error', null, 'Attempt ID is required');
                exit;
            }
            $timeline = $attempt->getAttemptTimeline($attempt_id);
            $db->send_response(200, 'success', $timeline, 'Timeline retrieved');
            break;

        default:
            $db->send_response(400, 'error', null,
                'Invalid action. Available: get-my-assessments, get-assessment, start-attempt, ' .
                'get-attempt, submit-attempt, save-answer, mark-for-review, update-navigation, ' .
                'update-time, auto-save, recover-draft, get-results, get-attempt-history, ' .
                'log-event, get-timeline');
    }

} catch (Exception $e) {
    error_log('Attempt API Error: ' . $e->getMessage());
    $db->send_response(500, 'error', null, 'Server error: ' . $e->getMessage());
}