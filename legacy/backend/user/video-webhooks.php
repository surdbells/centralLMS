<?php

/**
 * Video Platform Webhook Handler
 * 
 * Receives webhooks from Zoom, Microsoft Teams, and Google
 * to automatically sync attendance data
 * 
 * @package LMS
 * @version 1.0.0
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../class/LiveTraining.php';

$db = new Database();
$conn = $db->getConnection();

try {
    // Get platform from URL path
    $path = $_SERVER['REQUEST_URI'];
    preg_match('/\/webhooks\/(\w+)/', $path, $matches);
    $platform = $matches[1] ?? '';
    
    $raw_body = file_get_contents('php://input');
    $payload = json_decode($raw_body, true);
    
    // Log webhook for debugging
    logWebhook($platform, $raw_body);
    
    switch ($platform) {
        case 'zoom':
            handleZoomWebhook($conn, $payload, $raw_body);
            break;
            
        case 'teams':
        case 'microsoft':
            handleTeamsWebhook($conn, $payload);
            break;
            
        case 'google':
            handleGoogleWebhook($conn, $payload);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown platform']);
            exit;
    }
    
    echo json_encode(['status' => 'ok']);
    
} catch (Exception $e) {
    error_log('Webhook Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Handle Zoom webhooks
 */
function handleZoomWebhook($conn, array $payload, string $raw_body): void
{
    // Verify webhook signature (Zoom v2)
    $secret_token = getenv('ZOOM_WEBHOOK_SECRET');
    if ($secret_token) {
        $signature = $_SERVER['HTTP_X_ZM_SIGNATURE'] ?? '';
        $timestamp = $_SERVER['HTTP_X_ZM_REQUEST_TIMESTAMP'] ?? '';
        
        $message = "v0:{$timestamp}:{$raw_body}";
        $hash = 'v0=' . hash_hmac('sha256', $message, $secret_token);
        
        if (!hash_equals($hash, $signature)) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid signature']);
            exit;
        }
    }
    
    $event = $payload['event'] ?? '';
    $event_payload = $payload['payload'] ?? [];
    
    switch ($event) {
        case 'endpoint.url_validation':
            // Zoom URL validation challenge
            $plain_token = $payload['payload']['plainToken'] ?? '';
            $encrypted_token = hash_hmac('sha256', $plain_token, $secret_token);
            echo json_encode([
                'plainToken' => $plain_token,
                'encryptedToken' => $encrypted_token
            ]);
            exit;
            
        case 'meeting.participant_joined':
            $meeting_id = $event_payload['object']['id'] ?? '';
            $participant = $event_payload['object']['participant'] ?? [];
            
            recordVirtualJoin($conn, 'zoom', $meeting_id, [
                'email' => $participant['email'] ?? null,
                'name' => $participant['user_name'] ?? '',
                'join_time' => $participant['join_time'] ?? date('Y-m-d H:i:s'),
                'user_id' => $participant['user_id'] ?? null
            ]);
            break;
            
        case 'meeting.participant_left':
            $meeting_id = $event_payload['object']['id'] ?? '';
            $participant = $event_payload['object']['participant'] ?? [];
            
            recordVirtualLeave($conn, 'zoom', $meeting_id, [
                'email' => $participant['email'] ?? null,
                'name' => $participant['user_name'] ?? '',
                'leave_time' => $participant['leave_time'] ?? date('Y-m-d H:i:s')
            ]);
            break;
            
        case 'meeting.ended':
            $meeting_id = $event_payload['object']['id'] ?? '';
            finalizeSessionAttendance($conn, 'zoom', $meeting_id);
            break;
    }
}

/**
 * Handle Microsoft Teams webhooks
 */
function handleTeamsWebhook($conn, array $payload): void
{
    // Microsoft Teams uses subscriptions with validation
    if (isset($payload['validationToken'])) {
        header('Content-Type: text/plain');
        echo $payload['validationToken'];
        exit;
    }
    
    $notifications = $payload['value'] ?? [];
    
    foreach ($notifications as $notification) {
        $change_type = $notification['changeType'] ?? '';
        $resource = $notification['resource'] ?? '';
        $data = $notification['resourceData'] ?? [];
        
        // Parse meeting ID from resource path
        preg_match('/onlineMeetings\/([^\/]+)/', $resource, $matches);
        $meeting_id = $matches[1] ?? '';
        
        if (!$meeting_id) continue;
        
        switch ($change_type) {
            case 'created':
                if (strpos($resource, 'attendanceRecords') !== false) {
                    recordVirtualJoin($conn, 'microsoft_teams', $meeting_id, [
                        'email' => $data['emailAddress'] ?? null,
                        'name' => $data['identity']['displayName'] ?? '',
                        'join_time' => date('Y-m-d H:i:s')
                    ]);
                }
                break;
                
            case 'deleted':
                if (strpos($resource, 'attendanceRecords') !== false) {
                    recordVirtualLeave($conn, 'microsoft_teams', $meeting_id, [
                        'email' => $data['emailAddress'] ?? null,
                        'leave_time' => date('Y-m-d H:i:s')
                    ]);
                }
                break;
        }
    }
}

/**
 * Handle Google webhooks
 */
function handleGoogleWebhook($conn, array $payload): void
{
    // Google uses push notifications via Pub/Sub or Calendar push
    $resource_id = $_SERVER['HTTP_X_GOOG_RESOURCE_ID'] ?? '';
    $resource_state = $_SERVER['HTTP_X_GOOG_RESOURCE_STATE'] ?? '';
    
    if ($resource_state === 'sync') {
        // Initial sync request, just acknowledge
        return;
    }
    
    // For Google Meet, we typically need to poll the attendance report
    // as real-time webhooks are limited
    // This is a placeholder for when the meeting event changes
}

/**
 * Record participant join
 */
function recordVirtualJoin($conn, string $platform, string $meeting_id, array $participant): void
{
    // Find session by external meeting ID
    $stmt = $conn->prepare("
        SELECT s.session_id, s.training_id, s.tenant_id, s.start_time, s.late_threshold_minutes
        FROM live_training_sessions s
        WHERE s.external_meeting_id = ? AND s.video_platform = ?
    ");
    $stmt->bind_param("ss", $meeting_id, $platform);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$session) {
        error_log("Session not found for meeting: {$meeting_id}");
        return;
    }
    
    // Find user by email
    $user_id = findUserByEmail($conn, $participant['email'], $session['tenant_id']);
    if (!$user_id) {
        error_log("User not found for email: {$participant['email']}");
        return;
    }
    
    // Check if enrolled
    $stmt = $conn->prepare("
        SELECT enrollment_id FROM live_training_enrollments 
        WHERE training_id = ? AND user_id = ? AND status = 'enrolled'
    ");
    $stmt->bind_param("ii", $session['training_id'], $user_id);
    $stmt->execute();
    $enrollment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$enrollment) {
        error_log("User {$user_id} not enrolled in training {$session['training_id']}");
        return;
    }
    
    // Determine if late
    $join_time = new DateTime($participant['join_time']);
    $session_start = new DateTime(date('Y-m-d') . ' ' . $session['start_time']);
    $minutes_diff = ($join_time->getTimestamp() - $session_start->getTimestamp()) / 60;
    $status = $minutes_diff > $session['late_threshold_minutes'] ? 'late' : 'present';
    
    // Update attendance
    $sql = "UPDATE live_training_attendance SET 
                status = ?,
                attendance_mode = 'virtual',
                virtual_join_time = ?,
                marked_at = NOW()
            WHERE session_id = ? AND user_id = ? AND status = 'absent'";
    
    $join_time_str = $participant['join_time'];
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssii", $status, $join_time_str, $session['session_id'], $user_id);
    $stmt->execute();
    $stmt->close();
    
    // Log
    logAttendanceAction($conn, $session['session_id'], $user_id, 'virtual_join', [
        'platform' => $platform,
        'join_time' => $participant['join_time'],
        'status' => $status
    ]);
}

/**
 * Record participant leave
 */
function recordVirtualLeave($conn, string $platform, string $meeting_id, array $participant): void
{
    // Find session
    $stmt = $conn->prepare("
        SELECT session_id, tenant_id FROM live_training_sessions 
        WHERE external_meeting_id = ? AND video_platform = ?
    ");
    $stmt->bind_param("ss", $meeting_id, $platform);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$session) return;
    
    $user_id = findUserByEmail($conn, $participant['email'], $session['tenant_id']);
    if (!$user_id) return;
    
    // Update leave time and calculate duration
    $sql = "UPDATE live_training_attendance SET 
                virtual_leave_time = ?,
                virtual_duration_minutes = TIMESTAMPDIFF(MINUTE, virtual_join_time, ?)
            WHERE session_id = ? AND user_id = ?";
    
    $leave_time = $participant['leave_time'];
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssii", $leave_time, $leave_time, $session['session_id'], $user_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Finalize session attendance when meeting ends
 */
function finalizeSessionAttendance($conn, string $platform, string $meeting_id): void
{
    // Find session
    $stmt = $conn->prepare("
        SELECT s.session_id, s.training_id, t.min_virtual_duration_minutes
        FROM live_training_sessions s
        JOIN live_trainings t ON s.training_id = t.training_id
        WHERE s.external_meeting_id = ? AND s.video_platform = ?
    ");
    $stmt->bind_param("ss", $meeting_id, $platform);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$session) return;
    
    $min_duration = $session['min_virtual_duration_minutes'];
    
    // Update status based on duration
    $sql = "UPDATE live_training_attendance SET 
                status = CASE 
                    WHEN virtual_duration_minutes >= ? THEN status
                    ELSE 'absent'
                END
            WHERE session_id = ? AND attendance_mode = 'virtual'";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $min_duration, $session['session_id']);
    $stmt->execute();
    $stmt->close();
    
    // Update enrollment attendance percentages
    updateEnrollmentStats($conn, $session['training_id']);
}

/**
 * Find user by email
 */
function findUserByEmail($conn, ?string $email, int $tenant_id): ?int
{
    if (!$email) return null;
    
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND tenant_id = ?");
    $stmt->bind_param("si", $email, $tenant_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    return $user ? $user['user_id'] : null;
}

/**
 * Log attendance action
 */
function logAttendanceAction($conn, int $session_id, int $user_id, string $action, array $details): void
{
    $stmt = $conn->prepare("
        SELECT attendance_id, tenant_id FROM live_training_attendance 
        WHERE session_id = ? AND user_id = ?
    ");
    $stmt->bind_param("ii", $session_id, $user_id);
    $stmt->execute();
    $attendance = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$attendance) return;
    
    $details_json = json_encode($details);
    $sql = "INSERT INTO live_training_attendance_log 
            (attendance_id, session_id, user_id, tenant_id, action, details, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiiiss", 
        $attendance['attendance_id'], 
        $session_id, 
        $user_id, 
        $attendance['tenant_id'],
        $action, 
        $details_json
    );
    $stmt->execute();
    $stmt->close();
}

/**
 * Update enrollment stats
 */
function updateEnrollmentStats($conn, int $training_id): void
{
    $sql = "UPDATE live_training_enrollments lte
            SET 
                sessions_attended = (
                    SELECT COUNT(*) FROM live_training_attendance lta
                    WHERE lta.enrollment_id = lte.enrollment_id AND lta.status IN ('present', 'late')
                ),
                attendance_percentage = (
                    SELECT ROUND(
                        COUNT(CASE WHEN lta.status IN ('present', 'late') THEN 1 END) * 100.0 / 
                        NULLIF(COUNT(*), 0), 2
                    )
                    FROM live_training_attendance lta
                    WHERE lta.enrollment_id = lte.enrollment_id
                )
            WHERE lte.training_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $training_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Log webhook for debugging
 */
function logWebhook(string $platform, string $body): void
{
    $log_dir = __DIR__ . '/../logs/webhooks';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $filename = $log_dir . '/' . $platform . '_' . date('Y-m-d') . '.log';
    $entry = '[' . date('Y-m-d H:i:s') . '] ' . $body . "\n";
    file_put_contents($filename, $entry, FILE_APPEND);
}
