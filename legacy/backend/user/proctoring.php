<?php

/**
 * Proctoring API Endpoint
 * Handles proctoring settings, sessions, violations, and snapshots
 * For Multi-Tenant LMS - Admin & Learner Portal
 *
 * SETTINGS (Admin):
 * - get-settings          - Get proctoring settings for assessment
 * - save-settings         - Save proctoring settings
 * - apply-preset          - Apply preset to assessment
 * - get-presets           - Get available presets
 *
 * SESSIONS (Learner & Admin):
 * - create-session        - Create proctoring session for attempt
 * - get-session           - Get session details
 * - update-session-status - Update session status
 * - save-system-check     - Save system check results
 *
 * VIOLATIONS (Learner & Admin):
 * - log-violation         - Log a violation
 * - get-violations        - Get violations for attempt
 * - acknowledge-violation - Learner acknowledges violation
 *
 * SNAPSHOTS (Learner & Admin):
 * - save-snapshot         - Save a snapshot
 * - get-snapshots         - Get snapshots for attempt
 *
 * REVIEW (Admin):
 * - get-sessions-review   - Get sessions pending review
 * - review-session        - Review a session
 * - review-violation      - Review a violation
 *
 * STATISTICS (Admin):
 * - get-proctoring-stats  - Get proctoring statistics
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
require_once __DIR__ . '/../class/Proctoring.php';
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

    // Initialize Proctoring manager
    $proctoring = new Proctoring($conn);
    $proctoring->setTenant($tenant_id);
    $proctoring->setUser($user_id);

    switch ($action) {
        // ==========================================
        // SETTINGS (Admin)
        // ==========================================

        case 'get-settings':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                exit;
            }
            $settings = $proctoring->getSettings($assessment_id);
            $db->send_response(200, 'success', $settings, 'Settings retrieved');
            break;

        case 'save-settings':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                exit;
            }
            $result = $proctoring->saveSettings($assessment_id, $input);
            $db->send_response($result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        case 'apply-preset':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            $preset_id = (int) ($input['preset_id'] ?? 0);
            if (!$assessment_id || !$preset_id) {
                $db->send_response(400, 'error', null, 'Assessment ID and Preset ID are required');
                exit;
            }
            $result = $proctoring->applyPreset($assessment_id, $preset_id);
            $db->send_response($result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        case 'get-presets':
            $presets = $proctoring->getPresets();
            $db->send_response(200, 'success', $presets, 'Presets retrieved');
            break;

        // ==========================================
        // SESSIONS
        // ==========================================

        case 'create-session':
            $attempt_id = (int) ($input['attempt_id'] ?? 0);
            if (!$attempt_id) {
                $db->send_response(400, 'error', null, 'Attempt ID is required');
                exit;
            }
            $result = $proctoring->getOrCreateSession($attempt_id);
            $db->send_response($result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error',
                $result['success'] ? ['session' => $result['session'], 'is_new' => $result['is_new']] : null,
                $result['success'] ? 'Session ready' : $result['message']);
            break;

        case 'get-session':
            $attempt_id = (int) ($input['attempt_id'] ?? 0);
            if (!$attempt_id) {
                $db->send_response(400, 'error', null, 'Attempt ID is required');
                exit;
            }
            $session = $proctoring->getSession($attempt_id);
            if ($session) {
                $db->send_response(200, 'success', $session, 'Session retrieved');
            } else {
                $db->send_response(404, 'error', null, 'Session not found');
            }
            break;

        case 'update-session-status':
            $session_id = (int) ($input['session_id'] ?? 0);
            $status = $input['status'] ?? '';
            if (!$session_id || !$status) {
                $db->send_response(400, 'error', null, 'Session ID and status are required');
                exit;
            }
            $result = $proctoring->updateSessionStatus($session_id, $status);
            $db->send_response($result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        case 'save-system-check':
            $session_id = (int) ($input['session_id'] ?? 0);
            if (!$session_id) {
                $db->send_response(400, 'error', null, 'Session ID is required');
                exit;
            }
            $result = $proctoring->saveSystemCheck($session_id, $input);
            $db->send_response($result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error',
                $result['success'] ? ['passed' => $result['passed']] : null,
                $result['message']);
            break;

        // ==========================================
        // VIOLATIONS
        // ==========================================

        case 'log-violation':
            $session_id = (int) ($input['session_id'] ?? 0);
            $attempt_id = (int) ($input['attempt_id'] ?? 0);
            if (!$session_id || !$attempt_id) {
                $db->send_response(400, 'error', null, 'Session ID and Attempt ID are required');
                exit;
            }
            $result = $proctoring->logViolation($session_id, $attempt_id, $input);
            $db->send_response($result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error',
                $result['success'] ? [
                    'violation_id' => $result['violation_id'],
                    'trust_score' => $result['trust_score'],
                    'total_violations' => $result['total_violations']
                ] : null,
                $result['success'] ? 'Violation logged' : $result['message']);
            break;

        case 'get-violations':
            $attempt_id = (int) ($input['attempt_id'] ?? 0);
            if (!$attempt_id) {
                $db->send_response(400, 'error', null, 'Attempt ID is required');
                exit;
            }
            $violations = $proctoring->getViolations($attempt_id);
            $db->send_response(200, 'success', $violations, 'Violations retrieved');
            break;

        case 'acknowledge-violation':
            $violation_id = (int) ($input['violation_id'] ?? 0);
            if (!$violation_id) {
                $db->send_response(400, 'error', null, 'Violation ID is required');
                exit;
            }
            $result = $proctoring->acknowledgeViolation($violation_id);
            $db->send_response($result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        // ==========================================
        // SNAPSHOTS
        // ==========================================

        case 'save-snapshot':
            $session_id = (int) ($input['session_id'] ?? 0);
            $attempt_id = (int) ($input['attempt_id'] ?? 0);
            if (!$session_id || !$attempt_id) {
                $db->send_response(400, 'error', null, 'Session ID and Attempt ID are required');
                exit;
            }
            $result = $proctoring->saveSnapshot($session_id, $attempt_id, $input);
            $db->send_response($result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error',
                $result['success'] ? ['snapshot_id' => $result['snapshot_id']] : null,
                $result['success'] ? 'Snapshot saved' : $result['message']);
            break;

        case 'get-snapshots':
            $attempt_id = (int) ($input['attempt_id'] ?? 0);
            $type = $input['type'] ?? null;
            if (!$attempt_id) {
                $db->send_response(400, 'error', null, 'Attempt ID is required');
                exit;
            }
            $snapshots = $proctoring->getSnapshots($attempt_id, $type);
            $db->send_response(200, 'success', $snapshots, 'Snapshots retrieved');
            break;

        // ==========================================
        // REVIEW (Admin)
        // ==========================================

        case 'get-sessions-review':
            $filters = [
                'flagged_only' => $input['flagged_only'] ?? true,
                'reviewed' => $input['reviewed'] ?? false,
                'assessment_id' => $input['assessment_id'] ?? null,
                'page' => $input['page'] ?? 1,
                'limit' => $input['limit'] ?? 20
            ];
            $sessions = $proctoring->getSessionsForReview($filters);
            $db->send_response(200, 'success', $sessions, 'Sessions retrieved');
            break;

        case 'review-session':
            $session_id = (int) ($input['session_id'] ?? 0);
            $decision = $input['decision'] ?? '';
            $notes = $input['notes'] ?? '';
            if (!$session_id || !$decision) {
                $db->send_response(400, 'error', null, 'Session ID and decision are required');
                exit;
            }
            $result = $proctoring->reviewSession($session_id, $decision, $notes);
            $db->send_response($result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        case 'review-violation':
            $violation_id = (int) ($input['violation_id'] ?? 0);
            $status = $input['status'] ?? '';
            $notes = $input['notes'] ?? '';
            if (!$violation_id || !$status) {
                $db->send_response(400, 'error', null, 'Violation ID and status are required');
                exit;
            }
            $result = $proctoring->reviewViolation($violation_id, $status, $notes);
            $db->send_response($result['success'] ? 200 : 400,
                $result['success'] ? 'success' : 'error', null, $result['message']);
            break;

        // ==========================================
        // STATISTICS
        // ==========================================

        case 'get-proctoring-stats':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                exit;
            }
            $stats = $proctoring->getAssessmentProctoringStats($assessment_id);
            $db->send_response(200, 'success', $stats, 'Statistics retrieved');
            break;

        default:
            $db->send_response(400, 'error', null,
                'Invalid action. Available: get-settings, save-settings, apply-preset, get-presets, ' .
                'create-session, get-session, update-session-status, save-system-check, ' .
                'log-violation, get-violations, acknowledge-violation, ' .
                'save-snapshot, get-snapshots, ' .
                'get-sessions-review, review-session, review-violation, get-proctoring-stats');
    }

} catch (Exception $e) {
    error_log('Proctoring API Error: ' . $e->getMessage());
    $db->send_response(500, 'error', null, 'Server error: ' . $e->getMessage());
}
