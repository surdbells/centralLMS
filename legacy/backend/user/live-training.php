<?php

/**
 * Live Training API Endpoint
 *
 * Handles all Live Training operations via RESTful API
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
require_once __DIR__ . '/../class/LiveTraining.php';

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

    $liveTraining = new LiveTraining($conn, $tenant_id, $user_id);

    switch ($action) {
        // ==========================================
        // TRAINING CRUD
        // ==========================================

        case 'create-training':
            $data = $liveTraining->createTraining($input);
            $db->send_response(201, 'success', $data, 'Training created successfully');
            break;

        case 'update-training':
            $training_id = (int) ($input['training_id'] ?? 0);
            if (!$training_id) {
                $db->send_response(400, 'error', null, 'Training ID is required');
                break;
            }
            $data = $liveTraining->updateTraining($training_id, $input);
            $db->send_response(200, 'success', $data, 'Training updated successfully');
            break;

        case 'get-training':
            $training_id = (int) ($input['training_id'] ?? 0);
            if (!$training_id) {
                $db->send_response(400, 'error', null, 'Training ID is required');
                break;
            }
            $data = $liveTraining->getTraining($training_id);
            if (!$data) {
                $db->send_response(404, 'error', null, 'Training not found');
                break;
            }
            $db->send_response(200, 'success', $data, 'Training retrieved');
            break;

        case 'get-trainings':
            $filters = [
                'status' => $input['status'] ?? null,
                'is_active' => isset($input['is_active']) ? (int) $input['is_active'] : null,
                'training_type' => $input['training_type'] ?? null,
                'category_id' => $input['category_id'] ?? null,
                'search' => $input['search'] ?? null,
                'limit' => $input['limit'] ?? null,
                'offset' => $input['offset'] ?? null
            ];
            $data = $liveTraining->getTrainings(array_filter($filters, fn($v) => $v !== null));
            $db->send_response(200, 'success', $data, 'Trainings retrieved');
            break;

        case 'delete-training':
            $training_id = (int) ($input['training_id'] ?? 0);
            if (!$training_id) {
                $db->send_response(400, 'error', null, 'Training ID is required');
                break;
            }
            $liveTraining->deleteTraining($training_id);
            $db->send_response(200, 'success', null, 'Training deleted');
            break;

        // ==========================================
        // SESSION MANAGEMENT
        // ==========================================

        case 'create-session':
            $training_id = (int) ($input['training_id'] ?? 0);
            if (!$training_id) {
                $db->send_response(400, 'error', null, 'Training ID is required');
                break;
            }
            $data = $liveTraining->createSession($training_id, $input);
            $db->send_response(201, 'success', $data, 'Session created successfully');
            break;

        case 'update-session':
            $session_id = (int) ($input['session_id'] ?? 0);
            if (!$session_id) {
                $db->send_response(400, 'error', null, 'Session ID is required');
                break;
            }
            $data = $liveTraining->updateSession($session_id, $input);
            $db->send_response(200, 'success', $data, 'Session updated successfully');
            break;

        case 'get-session':
            $session_id = (int) ($input['session_id'] ?? 0);
            if (!$session_id) {
                $db->send_response(400, 'error', null, 'Session ID is required');
                break;
            }
            $data = $liveTraining->getSession($session_id);
            if (!$data) {
                $db->send_response(404, 'error', null, 'Session not found');
                break;
            }
            $db->send_response(200, 'success', $data, 'Session retrieved');
            break;

        case 'get-training-sessions':
            $training_id = (int) ($input['training_id'] ?? 0);
            if (!$training_id) {
                $db->send_response(400, 'error', null, 'Training ID is required');
                break;
            }
            $data = $liveTraining->getTrainingSessions($training_id);
            $db->send_response(200, 'success', $data, 'Sessions retrieved');
            break;

        case 'delete-session':
            $session_id = (int) ($input['session_id'] ?? 0);
            if (!$session_id) {
                $db->send_response(400, 'error', null, 'Session ID is required');
                break;
            }
            $liveTraining->deleteSession($session_id);
            $db->send_response(200, 'success', null, 'Session deleted');
            break;

        // ==========================================
        // INSTRUCTOR MANAGEMENT
        // ==========================================

        case 'assign-instructor':
            $session_id = (int) ($input['session_id'] ?? 0);
            $instructor_id = (int) ($input['instructor_id'] ?? 0);
            if (!$session_id || !$instructor_id) {
                $db->send_response(400, 'error', null, 'Session ID and Instructor ID are required');
                break;
            }
            $liveTraining->assignInstructor(
                $session_id,
                $instructor_id,
                $input['role'] ?? 'primary',
                $input['is_host'] ?? false
            );
            $db->send_response(200, 'success', null, 'Instructor assigned');
            break;

        case 'remove-instructor':
            $session_id = (int) ($input['session_id'] ?? 0);
            $instructor_id = (int) ($input['instructor_id'] ?? 0);
            if (!$session_id || !$instructor_id) {
                $db->send_response(400, 'error', null, 'Session ID and Instructor ID are required');
                break;
            }
            $liveTraining->removeInstructor($session_id, $instructor_id);
            $db->send_response(200, 'success', null, 'Instructor removed');
            break;

        case 'get-session-instructors':
            $session_id = (int) ($input['session_id'] ?? 0);
            if (!$session_id) {
                $db->send_response(400, 'error', null, 'Session ID is required');
                break;
            }
            $data = $liveTraining->getSessionInstructors($session_id);
            $db->send_response(200, 'success', $data, 'Instructors retrieved');
            break;

        // ==========================================
        // ENROLLMENT MANAGEMENT
        // ==========================================

        case 'enroll-learner':
            $training_id = (int) ($input['training_id'] ?? 0);
            $learner_id = (int) ($input['learner_id'] ?? $user_id);
            if (!$training_id) {
                $db->send_response(400, 'error', null, 'Training ID is required');
                break;
            }
            $data = $liveTraining->enrollLearner($training_id, $learner_id, $user_id);
            $db->send_response(201, 'success', $data, 'Enrollment successful');
            break;

        case 'bulk-enroll':
            $training_id = (int) ($input['training_id'] ?? 0);
            $learner_ids = $input['learner_ids'] ?? [];
            if (!$training_id || empty($learner_ids)) {
                $db->send_response(400, 'error', null, 'Training ID and learner IDs are required');
                break;
            }
            $results = ['success' => [], 'failed' => []];
            foreach ($learner_ids as $learner_id) {
                try {
                    $liveTraining->enrollLearner($training_id, (int) $learner_id, $user_id);
                    $results['success'][] = $learner_id;
                } catch (Exception $e) {
                    $results['failed'][] = ['user_id' => $learner_id, 'error' => $e->getMessage()];
                }
            }
            $db->send_response(200, 'success', $results, 'Bulk enrollment completed');
            break;

        case 'cancel-enrollment':
            $enrollment_id = (int) ($input['enrollment_id'] ?? 0);
            if (!$enrollment_id) {
                $db->send_response(400, 'error', null, 'Enrollment ID is required');
                break;
            }
            $liveTraining->cancelEnrollment($enrollment_id, $input['reason'] ?? null);
            $db->send_response(200, 'success', null, 'Enrollment cancelled');
            break;

        case 'get-enrollment':
            $enrollment_id = (int) ($input['enrollment_id'] ?? 0);
            if (!$enrollment_id) {
                $db->send_response(400, 'error', null, 'Enrollment ID is required');
                break;
            }
            $data = $liveTraining->getEnrollment($enrollment_id);
            if (!$data) {
                $db->send_response(404, 'error', null, 'Enrollment not found');
                break;
            }
            $db->send_response(200, 'success', $data, 'Enrollment retrieved');
            break;

        case 'get-training-enrollments':
            $training_id = (int) ($input['training_id'] ?? 0);
            if (!$training_id) {
                $db->send_response(400, 'error', null, 'Training ID is required');
                break;
            }
            $data = $liveTraining->getTrainingEnrollments($training_id, $input['status'] ?? null);
            $db->send_response(200, 'success', $data, 'Enrollments retrieved');
            break;

        case 'get-my-enrollments':
            $status = $input['status'] ?? null;
            $limit = isset($input['limit']) ? (int) $input['limit'] : null;
            $offset = isset($input['offset']) ? (int) $input['offset'] : null;
            $data = $liveTraining->getMyEnrollments($status, $limit, $offset);
            $db->send_response(200, 'success', $data, 'My enrollments retrieved');
            break;

        // ==========================================
        // ATTENDANCE TRACKING
        // ==========================================

        case 'mark-attendance-qr':
            $qr_token = $input['qr_token'] ?? '';
            $learner_id = (int) ($input['learner_id'] ?? $user_id);
            if (!$qr_token) {
                $db->send_response(400, 'error', null, 'QR token is required');
                break;
            }
            $data = $liveTraining->markAttendanceQR($qr_token, $learner_id);
            $db->send_response(200, 'success', $data, 'Attendance marked successfully');
            break;

        case 'mark-attendance-virtual':
            $session_id = (int) ($input['session_id'] ?? 0);
            $learner_id = (int) ($input['learner_id'] ?? 0);
            if (!$session_id || !$learner_id) {
                $db->send_response(400, 'error', null, 'Session ID and learner ID are required');
                break;
            }
            $virtual_data = [
                'join_time' => $input['join_time'] ?? null,
                'leave_time' => $input['leave_time'] ?? null,
                'duration_minutes' => $input['duration_minutes'] ?? 0
            ];
            $data = $liveTraining->markAttendanceVirtual($session_id, $learner_id, $virtual_data);
            $db->send_response(200, 'success', $data, 'Virtual attendance recorded');
            break;

        case 'override-attendance':
            $session_id = (int) ($input['session_id'] ?? 0);
            $learner_id = (int) ($input['learner_id'] ?? 0);
            $status = $input['status'] ?? '';
            $reason = $input['reason'] ?? '';
            if (!$session_id || !$learner_id || !$status || !$reason) {
                $db->send_response(400, 'error', null, 'Session ID, learner ID, status and reason are required');
                break;
            }
            $data = $liveTraining->overrideAttendance($session_id, $learner_id, $status, $reason);
            $db->send_response(200, 'success', $data, 'Attendance overridden');
            break;

        case 'get-session-attendance':
            $session_id = (int) ($input['session_id'] ?? 0);
            if (!$session_id) {
                $db->send_response(400, 'error', null, 'Session ID is required');
                break;
            }
            $data = $liveTraining->getSessionAttendance($session_id);
            $db->send_response(200, 'success', $data, 'Attendance retrieved');
            break;

        case 'lock-attendance':
            $session_id = (int) ($input['session_id'] ?? 0);
            if (!$session_id) {
                $db->send_response(400, 'error', null, 'Session ID is required');
                break;
            }
            $liveTraining->lockSessionAttendance($session_id);
            $db->send_response(200, 'success', null, 'Attendance locked');
            break;

        // ==========================================
        // QR CODE MANAGEMENT
        // ==========================================

        case 'generate-qr':
            $session_id = (int) ($input['session_id'] ?? 0);
            if (!$session_id) {
                $db->send_response(400, 'error', null, 'Session ID is required');
                break;
            }
            $token = $liveTraining->generateSessionQRCode($session_id);
            $db->send_response(200, 'success', ['qr_token' => $token], 'QR code generated');
            break;

        case 'get-qr-data':
            $session_id = (int) ($input['session_id'] ?? 0);
            if (!$session_id) {
                $db->send_response(400, 'error', null, 'Session ID is required');
                break;
            }
            $data = $liveTraining->getSessionQRCode($session_id);
            $db->send_response(200, 'success', $data, 'QR data retrieved');
            break;

        // ==========================================
        // CERTIFICATION
        // ==========================================

        case 'check-eligibility':
            $enrollment_id = (int) ($input['enrollment_id'] ?? 0);
            if (!$enrollment_id) {
                $db->send_response(400, 'error', null, 'Enrollment ID is required');
                break;
            }
            $liveTraining->checkCertificationEligibility($enrollment_id);
            $data = $liveTraining->getEnrollment($enrollment_id);
            $db->send_response(200, 'success', $data, 'Eligibility checked');
            break;

        case 'issue-certificate':
            $enrollment_id = (int) ($input['enrollment_id'] ?? 0);
            if (!$enrollment_id) {
                $db->send_response(400, 'error', null, 'Enrollment ID is required');
                break;
            }
            $liveTraining->issueCertificate($enrollment_id);
            $db->send_response(200, 'success', null, 'Certificate issued');
            break;

        case 'revoke-certificate':
            $enrollment_id = (int) ($input['enrollment_id'] ?? 0);
            $reason = $input['reason'] ?? '';
            if (!$enrollment_id || !$reason) {
                $db->send_response(400, 'error', null, 'Enrollment ID and reason are required');
                break;
            }
            $liveTraining->revokeCertificate($enrollment_id, $reason);
            $db->send_response(200, 'success', null, 'Certificate revoked');
            break;

        // ==========================================
        // VIDEO INTEGRATIONS
        // ==========================================

        case 'get-integrations':
            $data = $liveTraining->getTenantIntegrations();
            $db->send_response(200, 'success', $data, 'Integrations retrieved');
            break;

        case 'save-integration':
            $data = $liveTraining->saveTenantIntegration($input);
            $db->send_response(200, 'success', $data, 'Integration saved');
            break;

        case 'disconnect-integration':
            $platform = $input['platform'] ?? '';
            if (!$platform) {
                $db->send_response(400, 'error', null, 'Platform is required');
                break;
            }
            $liveTraining->disconnectIntegration($platform);
            $db->send_response(200, 'success', null, 'Integration disconnected');
            break;

        // ==========================================
        // REPORTING & ANALYTICS
        // ==========================================

        case 'get-training-analytics':
            $training_id = (int) ($input['training_id'] ?? 0);
            if (!$training_id) {
                $db->send_response(400, 'error', null, 'Training ID is required');
                break;
            }
            $data = $liveTraining->getTrainingAnalytics($training_id);
            $db->send_response(200, 'success', $data, 'Analytics retrieved');
            break;

        case 'get-learner-progress':
            $training_id = (int) ($input['training_id'] ?? 0);
            $learner_id = (int) ($input['learner_id'] ?? $user_id);
            if (!$training_id) {
                $db->send_response(400, 'error', null, 'Training ID is required');
                break;
            }
            $data = $liveTraining->getLearnerProgress($training_id, $learner_id);
            $db->send_response(200, 'success', $data, 'Progress retrieved');
            break;

        case 'get-upcoming-sessions':
            $limit = (int) ($input['limit'] ?? 10);
            $learner_id = (int) ($input['learner_id'] ?? $user_id);
            $data = $liveTraining->getLearnerUpcomingSessions($learner_id, $limit);
            $db->send_response(200, 'success', $data, 'Upcoming sessions retrieved');
            break;

        case 'export-attendance':
            $training_id = (int) ($input['training_id'] ?? 0);
            if (!$training_id) {
                $db->send_response(400, 'error', null, 'Training ID is required');
                break;
            }
            $data = $liveTraining->exportAttendanceData($training_id);
            $db->send_response(200, 'success', $data, 'Attendance data exported');
            break;

        // ==========================================
        // CRON JOBS
        // ==========================================

        case 'process-reminders':
            // Should be called by cron, validate cron token
            $data = $liveTraining->processPendingReminders();
            $db->send_response(200, 'success', $data, 'Reminders processed');
            break;


        // ==========================================
        // ENHANCED ENROLLMENT ACTIONS
        // ==========================================

        case 'bulk-enroll-by-rule':
            $training_id = (int) ($input['training_id'] ?? 0);
            $rule = $input['rule'] ?? [];

            if (!$training_id) {
                $db->send_response(400, 'error', null, 'Training ID is required');
                break;
            }

            if (empty($rule) || empty($rule['enrollment_type'])) {
                $db->send_response(400, 'error', null, 'Enrollment rule with type is required');
                break;
            }

            try {
                $data = $liveTraining->bulkEnrollByRule($training_id, $rule);
                $db->send_response(200, 'success', $data, $data['message']);
            } catch (Exception $e) {
                $db->send_response(400, 'error', null, $e->getMessage());
            }
            break;

        case 'get-enrollment-rules':
            $training_id = (int) ($input['training_id'] ?? 0);

            if (!$training_id) {
                $db->send_response(400, 'error', null, 'Training ID is required');
                break;
            }

            $data = $liveTraining->getEnrollmentRules($training_id);
            $db->send_response(200, 'success', $data, 'Enrollment rules retrieved');
            break;

        case 'delete-enrollment-rule':
            $rule_id = (int) ($input['rule_id'] ?? 0);

            if (!$rule_id) {
                $db->send_response(400, 'error', null, 'Rule ID is required');
                break;
            }

            $result = $liveTraining->deleteEnrollmentRule($rule_id);

            if ($result) {
                $db->send_response(200, 'success', null, 'Enrollment rule deleted');
            } else {
                $db->send_response(400, 'error', null, 'Failed to delete enrollment rule');
            }
            break;

        case 'search-users-for-enrollment':
            $filters = [
                'search' => $input['search'] ?? null,
                'department_id' => $input['department_id'] ?? null,
                'branch_id' => $input['branch_id'] ?? null,
                'team_id' => $input['team_id'] ?? null,
                'exclude_training_id' => $input['training_id'] ?? null,
                'limit' => $input['limit'] ?? 100
            ];

            $data = $liveTraining->searchUsersForEnrollment(array_filter($filters, fn($v) => $v !== null));
            $db->send_response(200, 'success', $data, 'Users retrieved');
            break;

            // ==========================================
            // SKILLS MANAGEMENT ACTIONS
            // ==========================================

        case 'assign-skills':
            $training_id = (int) ($input['training_id'] ?? 0);
            $skills = $input['skills'] ?? [];

            if (!$training_id) {
                $db->send_response(400, 'error', null, 'Training ID is required');
                break;
            }

            if (empty($skills) || !is_array($skills)) {
                $db->send_response(400, 'error', null, 'Skills array is required');
                break;
            }

            $data = $liveTraining->assignSkillsToTraining($training_id, $skills);

            $message = sprintf(
                'Added %d, updated %d, skipped %d skills',
                $data['added'],
                $data['updated'],
                $data['skipped']
            );

            $db->send_response(200, 'success', $data, $message);
            break;

        case 'remove-skill':
            $training_id = (int) ($input['training_id'] ?? 0);
            $skill_id = (int) ($input['skill_id'] ?? 0);

            if (!$training_id || !$skill_id) {
                $db->send_response(400, 'error', null, 'Training ID and Skill ID are required');
                break;
            }

            $result = $liveTraining->removeSkillFromTraining($training_id, $skill_id);

            if ($result) {
                $db->send_response(200, 'success', null, 'Skill removed successfully');
            } else {
                $db->send_response(400, 'error', null, 'Failed to remove skill');
            }
            break;

        case 'get-training-skills':
            $training_id = (int) ($input['training_id'] ?? 0);

            if (!$training_id) {
                $db->send_response(400, 'error', null, 'Training ID is required');
                break;
            }

            $data = $liveTraining->getTrainingSkills($training_id);
            $db->send_response(200, 'success', $data, 'Training skills retrieved');
            break;

        case 'get-user-acquired-skills':
            $target_user_id = (int) ($input['user_id'] ?? $user_id);
            $source_type = $input['source_type'] ?? null;

            $data = $liveTraining->getUserAcquiredSkills($target_user_id, $source_type);
            $db->send_response(200, 'success', $data, 'User skills retrieved');
            break;

        case 'get-user-skills-summary':
            $target_user_id = (int) ($input['user_id'] ?? $user_id);

            $data = $liveTraining->getUserSkillsSummary($target_user_id);
            $db->send_response(200, 'success', $data, 'Skills summary retrieved');
            break;

        case 'search-skills':
            $filters = [
                'search' => $input['search'] ?? null,
                'category_id' => $input['category_id'] ?? null,
                'exclude_training_id' => $input['training_id'] ?? null
            ];

            $data = $liveTraining->searchSkills(array_filter($filters, fn($v) => $v !== null));
            $db->send_response(200, 'success', $data, 'Skills retrieved');
            break;

        case 'get-skills-categories':
            $data = $liveTraining->getSkillsCategories();
            $db->send_response(200, 'success', $data, 'Skill categories retrieved');
            break;

        case 'award-skills-manual':
            // Manual skill award (for administrators)
            $enrollment_id = (int) ($input['enrollment_id'] ?? 0);

            if (!$enrollment_id) {
                $db->send_response(400, 'error', null, 'Enrollment ID is required');
                break;
            }

            try {
                $data = $liveTraining->awardSkillsOnCompletion($enrollment_id);
                $db->send_response(200, 'success', $data, $data['message']);
            } catch (Exception $e) {
                $db->send_response(400, 'error', null, $e->getMessage());
            }
            break;

        /**
         * UPDATE THE DEFAULT CASE TO INCLUDE NEW ACTIONS
         * Replace the existing default case with this updated version:
         */
        default:
            $db->send_response(400, 'error', null,
                'Invalid action. Available actions: ' .
                'create-training, update-training, get-training, get-trainings, delete-training, ' .
                'create-session, update-session, get-session, get-training-sessions, delete-session, ' .
                'assign-instructor, remove-instructor, get-session-instructors, ' .
                'enroll-learner, bulk-enroll-by-rule, get-enrollment-rules, delete-enrollment-rule, search-users-for-enrollment, ' .
                'cancel-enrollment, get-enrollment, get-training-enrollments, get-my-enrollments, ' .
                'assign-skills, remove-skill, get-training-skills, get-user-acquired-skills, get-user-skills-summary, search-skills, get-skills-categories, award-skills-manual, ' .
                'mark-attendance-qr, mark-attendance-virtual, override-attendance, get-session-attendance, lock-attendance, ' .
                'generate-qr, get-qr-data, ' .
                'check-eligibility, issue-certificate, revoke-certificate, ' .
                'get-integrations, save-integration, disconnect-integration, ' .
                'get-training-analytics, get-learner-progress, get-upcoming-sessions, export-attendance, ' .
                'process-reminders');
    }

} catch (Exception $e) {
    error_log('Live Training API Error: ' . $e->getMessage());
    $db->send_response(500, 'error', null, $e->getMessage());
}