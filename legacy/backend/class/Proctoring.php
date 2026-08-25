<?php

/**
 * Proctoring Manager
 * Handles proctoring settings, sessions, violations, and snapshots
 * For Multi-Tenant LMS - Admin & Learner Portal
 * 
 * Following Skills.php pattern with MySQLi prepared statements
 */

class Proctoring
{
    private mysqli $conn;
    private int $tenant_id;
    private int $user_id;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function setTenant(int $tenant_id): void
    {
        $this->tenant_id = $tenant_id;
    }

    public function setUser(int $user_id): void
    {
        $this->user_id = $user_id;
    }

    // ==========================================
    // PROCTORING SETTINGS (Admin)
    // ==========================================

    /**
     * Get proctoring settings for an assessment
     */
    public function getSettings(int $assessment_id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT * FROM proc_proctoring_settings
            WHERE assessment_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("ii", $assessment_id, $this->tenant_id);
        $stmt->execute();
        $settings = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($settings) {
            $settings['setting_id'] = (int) $settings['setting_id'];
            $settings['min_browser_version'] = $settings['min_browser_version'] 
                ? json_decode($settings['min_browser_version'], true) : null;
            $settings['allowed_browsers'] = $settings['allowed_browsers'] 
                ? json_decode($settings['allowed_browsers'], true) : null;
            
            // Cast booleans
            $boolFields = [
                'prevent_tab_switch', 'prevent_copy_paste', 'prevent_right_click',
                'prevent_print_screen', 'require_fullscreen', 'detect_dev_tools',
                'tab_switch_warning', 'window_blur_detection', 'require_webcam',
                'face_detection_enabled', 'multiple_face_detection', 'no_face_detection',
                'capture_snapshots', 'snapshot_on_violation', 'require_microphone',
                'audio_detection_enabled', 'record_screen', 'record_webcam', 'record_audio',
                'require_id_verification', 'require_room_scan', 'ai_behavior_analysis',
                'ai_anomaly_detection', 'ai_gaze_tracking', 'auto_submit_on_violations',
                'pause_on_violation', 'notify_admin_on_violation', 'send_violation_report',
                'require_desktop', 'require_stable_connection'
            ];
            foreach ($boolFields as $field) {
                if (isset($settings[$field])) {
                    $settings[$field] = (bool) $settings[$field];
                }
            }
        }

        return $settings;
    }

    /**
     * Save proctoring settings
     */
    public function saveSettings(int $assessment_id, array $data): array
    {
        // Check if settings exist
        $existing = $this->getSettings($assessment_id);

        $proctoring_level = $data['proctoring_level'] ?? 'none';
        
        // Boolean fields
        $prevent_tab_switch = isset($data['prevent_tab_switch']) ? (int) $data['prevent_tab_switch'] : 1;
        $prevent_copy_paste = isset($data['prevent_copy_paste']) ? (int) $data['prevent_copy_paste'] : 1;
        $prevent_right_click = isset($data['prevent_right_click']) ? (int) $data['prevent_right_click'] : 1;
        $prevent_print_screen = isset($data['prevent_print_screen']) ? (int) $data['prevent_print_screen'] : 1;
        $require_fullscreen = isset($data['require_fullscreen']) ? (int) $data['require_fullscreen'] : 1;
        $detect_dev_tools = isset($data['detect_dev_tools']) ? (int) $data['detect_dev_tools'] : 1;
        
        $max_tab_switches = (int) ($data['max_tab_switches'] ?? 3);
        $tab_switch_warning = isset($data['tab_switch_warning']) ? (int) $data['tab_switch_warning'] : 1;
        $window_blur_detection = isset($data['window_blur_detection']) ? (int) $data['window_blur_detection'] : 1;
        
        $require_webcam = isset($data['require_webcam']) ? (int) $data['require_webcam'] : 0;
        $webcam_check_interval_seconds = (int) ($data['webcam_check_interval_seconds'] ?? 30);
        $face_detection_enabled = isset($data['face_detection_enabled']) ? (int) $data['face_detection_enabled'] : 0;
        $multiple_face_detection = isset($data['multiple_face_detection']) ? (int) $data['multiple_face_detection'] : 0;
        $no_face_detection = isset($data['no_face_detection']) ? (int) $data['no_face_detection'] : 0;
        $face_movement_threshold = (int) ($data['face_movement_threshold'] ?? 50);
        
        $capture_snapshots = isset($data['capture_snapshots']) ? (int) $data['capture_snapshots'] : 0;
        $snapshot_interval_seconds = (int) ($data['snapshot_interval_seconds'] ?? 60);
        $snapshot_on_violation = isset($data['snapshot_on_violation']) ? (int) $data['snapshot_on_violation'] : 1;
        $snapshot_quality = (int) ($data['snapshot_quality'] ?? 80);
        
        $require_microphone = isset($data['require_microphone']) ? (int) $data['require_microphone'] : 0;
        $audio_detection_enabled = isset($data['audio_detection_enabled']) ? (int) $data['audio_detection_enabled'] : 0;
        $audio_threshold_db = (int) ($data['audio_threshold_db'] ?? -30);
        
        $record_screen = isset($data['record_screen']) ? (int) $data['record_screen'] : 0;
        $record_webcam = isset($data['record_webcam']) ? (int) $data['record_webcam'] : 0;
        $record_audio = isset($data['record_audio']) ? (int) $data['record_audio'] : 0;
        $max_recording_size_mb = (int) ($data['max_recording_size_mb'] ?? 500);
        
        $require_id_verification = isset($data['require_id_verification']) ? (int) $data['require_id_verification'] : 0;
        $id_verification_type = $data['id_verification_type'] ?? 'photo';
        $face_match_threshold = (float) ($data['face_match_threshold'] ?? 0.80);
        
        $require_room_scan = isset($data['require_room_scan']) ? (int) $data['require_room_scan'] : 0;
        $room_scan_duration_seconds = (int) ($data['room_scan_duration_seconds'] ?? 30);
        
        $ai_behavior_analysis = isset($data['ai_behavior_analysis']) ? (int) $data['ai_behavior_analysis'] : 0;
        $ai_anomaly_detection = isset($data['ai_anomaly_detection']) ? (int) $data['ai_anomaly_detection'] : 0;
        $ai_gaze_tracking = isset($data['ai_gaze_tracking']) ? (int) $data['ai_gaze_tracking'] : 0;
        
        $violation_threshold = (int) ($data['violation_threshold'] ?? 5);
        $auto_submit_on_violations = isset($data['auto_submit_on_violations']) ? (int) $data['auto_submit_on_violations'] : 0;
        $auto_submit_violation_count = (int) ($data['auto_submit_violation_count'] ?? 10);
        $pause_on_violation = isset($data['pause_on_violation']) ? (int) $data['pause_on_violation'] : 0;
        
        $notify_admin_on_violation = isset($data['notify_admin_on_violation']) ? (int) $data['notify_admin_on_violation'] : 0;
        $notify_threshold = (int) ($data['notify_threshold'] ?? 3);
        $send_violation_report = isset($data['send_violation_report']) ? (int) $data['send_violation_report'] : 1;
        
        $min_browser_version = isset($data['min_browser_version']) ? json_encode($data['min_browser_version']) : null;
        $allowed_browsers = isset($data['allowed_browsers']) ? json_encode($data['allowed_browsers']) : null;
        $require_desktop = isset($data['require_desktop']) ? (int) $data['require_desktop'] : 0;
        $min_screen_width = (int) ($data['min_screen_width'] ?? 1024);
        $min_screen_height = (int) ($data['min_screen_height'] ?? 768);
        $require_stable_connection = isset($data['require_stable_connection']) ? (int) $data['require_stable_connection'] : 0;
        $min_bandwidth_mbps = (float) ($data['min_bandwidth_mbps'] ?? 1.00);

        if ($existing) {
            // Update
            $stmt = $this->conn->prepare("
                UPDATE proc_proctoring_settings SET
                    proctoring_level = ?, prevent_tab_switch = ?, prevent_copy_paste = ?,
                    prevent_right_click = ?, prevent_print_screen = ?, require_fullscreen = ?,
                    detect_dev_tools = ?, max_tab_switches = ?, tab_switch_warning = ?,
                    window_blur_detection = ?, require_webcam = ?, webcam_check_interval_seconds = ?,
                    face_detection_enabled = ?, multiple_face_detection = ?, no_face_detection = ?,
                    face_movement_threshold = ?, capture_snapshots = ?, snapshot_interval_seconds = ?,
                    snapshot_on_violation = ?, snapshot_quality = ?, require_microphone = ?,
                    audio_detection_enabled = ?, audio_threshold_db = ?, record_screen = ?,
                    record_webcam = ?, record_audio = ?, max_recording_size_mb = ?,
                    require_id_verification = ?, id_verification_type = ?, face_match_threshold = ?,
                    require_room_scan = ?, room_scan_duration_seconds = ?, ai_behavior_analysis = ?,
                    ai_anomaly_detection = ?, ai_gaze_tracking = ?, violation_threshold = ?,
                    auto_submit_on_violations = ?, auto_submit_violation_count = ?, pause_on_violation = ?,
                    notify_admin_on_violation = ?, notify_threshold = ?, send_violation_report = ?,
                    min_browser_version = ?, allowed_browsers = ?, require_desktop = ?,
                    min_screen_width = ?, min_screen_height = ?, require_stable_connection = ?,
                    min_bandwidth_mbps = ?
                WHERE assessment_id = ? AND tenant_id = ?
            ");
            $stmt->bind_param(
                "siiiiiiiiiiiiiiiiiiiiiiiiiiisdiiiiiiiiiiiissiiiidii",
                $proctoring_level, $prevent_tab_switch, $prevent_copy_paste,
                $prevent_right_click, $prevent_print_screen, $require_fullscreen,
                $detect_dev_tools, $max_tab_switches, $tab_switch_warning,
                $window_blur_detection, $require_webcam, $webcam_check_interval_seconds,
                $face_detection_enabled, $multiple_face_detection, $no_face_detection,
                $face_movement_threshold, $capture_snapshots, $snapshot_interval_seconds,
                $snapshot_on_violation, $snapshot_quality, $require_microphone,
                $audio_detection_enabled, $audio_threshold_db, $record_screen,
                $record_webcam, $record_audio, $max_recording_size_mb,
                $require_id_verification, $id_verification_type, $face_match_threshold,
                $require_room_scan, $room_scan_duration_seconds, $ai_behavior_analysis,
                $ai_anomaly_detection, $ai_gaze_tracking, $violation_threshold,
                $auto_submit_on_violations, $auto_submit_violation_count, $pause_on_violation,
                $notify_admin_on_violation, $notify_threshold, $send_violation_report,
                $min_browser_version, $allowed_browsers, $require_desktop,
                $min_screen_width, $min_screen_height, $require_stable_connection,
                $min_bandwidth_mbps, $assessment_id, $this->tenant_id
            );
        } else {
            // Insert
            $stmt = $this->conn->prepare("
                INSERT INTO proc_proctoring_settings (
                    assessment_id, tenant_id, proctoring_level, prevent_tab_switch, prevent_copy_paste,
                    prevent_right_click, prevent_print_screen, require_fullscreen, detect_dev_tools,
                    max_tab_switches, tab_switch_warning, window_blur_detection, require_webcam,
                    webcam_check_interval_seconds, face_detection_enabled, multiple_face_detection,
                    no_face_detection, face_movement_threshold, capture_snapshots, snapshot_interval_seconds,
                    snapshot_on_violation, snapshot_quality, require_microphone, audio_detection_enabled,
                    audio_threshold_db, record_screen, record_webcam, record_audio, max_recording_size_mb,
                    require_id_verification, id_verification_type, face_match_threshold, require_room_scan,
                    room_scan_duration_seconds, ai_behavior_analysis, ai_anomaly_detection, ai_gaze_tracking,
                    violation_threshold, auto_submit_on_violations, auto_submit_violation_count,
                    pause_on_violation, notify_admin_on_violation, notify_threshold, send_violation_report,
                    min_browser_version, allowed_browsers, require_desktop, min_screen_width,
                    min_screen_height, require_stable_connection, min_bandwidth_mbps, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                )
            ");
            $stmt->bind_param(
                "iisiiiiiiiiiiiiiiiiiiiiiiiiiiisdiiiiiiiiiiiissiiiid",
                $assessment_id, $this->tenant_id, $proctoring_level, $prevent_tab_switch, $prevent_copy_paste,
                $prevent_right_click, $prevent_print_screen, $require_fullscreen, $detect_dev_tools,
                $max_tab_switches, $tab_switch_warning, $window_blur_detection, $require_webcam,
                $webcam_check_interval_seconds, $face_detection_enabled, $multiple_face_detection,
                $no_face_detection, $face_movement_threshold, $capture_snapshots, $snapshot_interval_seconds,
                $snapshot_on_violation, $snapshot_quality, $require_microphone, $audio_detection_enabled,
                $audio_threshold_db, $record_screen, $record_webcam, $record_audio, $max_recording_size_mb,
                $require_id_verification, $id_verification_type, $face_match_threshold, $require_room_scan,
                $room_scan_duration_seconds, $ai_behavior_analysis, $ai_anomaly_detection, $ai_gaze_tracking,
                $violation_threshold, $auto_submit_on_violations, $auto_submit_violation_count,
                $pause_on_violation, $notify_admin_on_violation, $notify_threshold, $send_violation_report,
                $min_browser_version, $allowed_browsers, $require_desktop, $min_screen_width,
                $min_screen_height, $require_stable_connection, $min_bandwidth_mbps
            );
        }

        if ($stmt->execute()) {
            $stmt->close();
            
            // Update assessment proctoring level
            $updateStmt = $this->conn->prepare("
                UPDATE proc_assessments SET proctoring_level = ? WHERE assessment_id = ?
            ");
            $updateStmt->bind_param("si", $proctoring_level, $assessment_id);
            $updateStmt->execute();
            $updateStmt->close();
            
            return ['success' => true, 'message' => 'Proctoring settings saved'];
        }

        $error = $stmt->error;
        $stmt->close();
        return ['success' => false, 'message' => 'Failed to save settings: ' . $error];
    }

    /**
     * Apply preset to assessment
     */
    public function applyPreset(int $assessment_id, int $preset_id): array
    {
        $stmt = $this->conn->prepare("
            SELECT * FROM proc_proctoring_presets 
            WHERE preset_id = ? AND (tenant_id = ? OR tenant_id = 0)
        ");
        $stmt->bind_param("ii", $preset_id, $this->tenant_id);
        $stmt->execute();
        $preset = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$preset) {
            return ['success' => false, 'message' => 'Preset not found'];
        }

        $settings = json_decode($preset['settings'], true);
        $settings['proctoring_level'] = $preset['proctoring_level'];

        return $this->saveSettings($assessment_id, $settings);
    }

    /**
     * Get available presets
     */
    public function getPresets(): array
    {
        $stmt = $this->conn->prepare("
            SELECT * FROM proc_proctoring_presets
            WHERE tenant_id = ? OR tenant_id = 0
            ORDER BY proctoring_level, name
        ");
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $presets = [];
        while ($row = $result->fetch_assoc()) {
            $row['preset_id'] = (int) $row['preset_id'];
            $row['settings'] = json_decode($row['settings'], true);
            $row['is_default'] = (bool) $row['is_default'];
            $presets[] = $row;
        }
        $stmt->close();

        return $presets;
    }

    // ==========================================
    // PROCTORING SESSIONS
    // ==========================================

    /**
     * Create or get proctoring session for an attempt
     */
    public function getOrCreateSession(int $attempt_id): array
    {
        // Check existing
        $stmt = $this->conn->prepare("
            SELECT * FROM proc_proctoring_sessions WHERE attempt_id = ?
        ");
        $stmt->bind_param("i", $attempt_id);
        $stmt->execute();
        $session = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($session) {
            return ['success' => true, 'session' => $this->formatSession($session), 'is_new' => false];
        }

        // Create new session
        $stmt = $this->conn->prepare("
            INSERT INTO proc_proctoring_sessions (attempt_id, tenant_id, user_id, status, created_at)
            VALUES (?, ?, ?, 'pending_check', NOW())
        ");
        $stmt->bind_param("iii", $attempt_id, $this->tenant_id, $this->user_id);

        if ($stmt->execute()) {
            $session_id = $stmt->insert_id;
            $stmt->close();

            $newSession = [
                'session_id' => $session_id,
                'attempt_id' => $attempt_id,
                'status' => 'pending_check',
                'trust_score' => 100
            ];

            return ['success' => true, 'session' => $newSession, 'is_new' => true];
        }

        $stmt->close();
        return ['success' => false, 'message' => 'Failed to create session'];
    }

    /**
     * Format session data
     */
    private function formatSession(array $session): array
    {
        $session['session_id'] = (int) $session['session_id'];
        $session['trust_score'] = (float) $session['trust_score'];
        $session['total_violations'] = (int) $session['total_violations'];
        $session['total_snapshots'] = (int) $session['total_snapshots'];
        $session['system_check_passed'] = $session['system_check_passed'] !== null ? (bool) $session['system_check_passed'] : null;
        $session['id_verified'] = $session['id_verified'] !== null ? (bool) $session['id_verified'] : null;
        $session['flagged_for_review'] = (bool) $session['flagged_for_review'];
        $session['system_check_data'] = $session['system_check_data'] ? json_decode($session['system_check_data'], true) : null;
        return $session;
    }

    /**
     * Update session status
     */
    public function updateSessionStatus(int $session_id, string $status): array
    {
        $validStatuses = ['pending_check', 'system_check', 'active', 'paused', 'completed', 'terminated'];
        if (!in_array($status, $validStatuses)) {
            return ['success' => false, 'message' => 'Invalid status'];
        }

        $stmt = $this->conn->prepare("
            UPDATE proc_proctoring_sessions SET status = ?
            WHERE session_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("sii", $status, $session_id, $this->tenant_id);

        if ($stmt->execute() && $stmt->affected_rows >= 0) {
            $stmt->close();
            
            if ($status === 'active') {
                $updateStmt = $this->conn->prepare("
                    UPDATE proc_proctoring_sessions SET started_at = COALESCE(started_at, NOW())
                    WHERE session_id = ?
                ");
                $updateStmt->bind_param("i", $session_id);
                $updateStmt->execute();
                $updateStmt->close();
            } elseif ($status === 'completed' || $status === 'terminated') {
                $updateStmt = $this->conn->prepare("
                    UPDATE proc_proctoring_sessions SET ended_at = NOW() WHERE session_id = ?
                ");
                $updateStmt->bind_param("i", $session_id);
                $updateStmt->execute();
                $updateStmt->close();
            }
            
            return ['success' => true, 'message' => 'Session status updated'];
        }

        $stmt->close();
        return ['success' => false, 'message' => 'Session not found'];
    }

    /**
     * Get session for an attempt
     */
    public function getSession(int $attempt_id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT ps.*, u.first_name, u.last_name, u.email
            FROM proc_proctoring_sessions ps
            JOIN users u ON ps.user_id = u.id
            WHERE ps.attempt_id = ? AND ps.tenant_id = ?
        ");
        $stmt->bind_param("ii", $attempt_id, $this->tenant_id);
        $stmt->execute();
        $session = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $session ? $this->formatSession($session) : null;
    }

    // ==========================================
    // SYSTEM CHECK
    // ==========================================

    /**
     * Save system check results
     */
    public function saveSystemCheck(int $session_id, array $checkData): array
    {
        $browser_name = $checkData['browser_name'] ?? null;
        $browser_version = $checkData['browser_version'] ?? null;
        $browser_supported = isset($checkData['browser_supported']) ? (int) $checkData['browser_supported'] : 1;
        $os_name = $checkData['os_name'] ?? null;
        $os_version = $checkData['os_version'] ?? null;
        $device_type = $checkData['device_type'] ?? 'desktop';
        $screen_width = !empty($checkData['screen_width']) ? (int) $checkData['screen_width'] : null;
        $screen_height = !empty($checkData['screen_height']) ? (int) $checkData['screen_height'] : null;
        $screen_sufficient = isset($checkData['screen_sufficient']) ? (int) $checkData['screen_sufficient'] : 1;
        $camera_permission = $checkData['camera_permission'] ?? 'not_required';
        $camera_working = isset($checkData['camera_working']) ? (int) $checkData['camera_working'] : null;
        $microphone_permission = $checkData['microphone_permission'] ?? 'not_required';
        $microphone_working = isset($checkData['microphone_working']) ? (int) $checkData['microphone_working'] : null;
        $screen_share_permission = $checkData['screen_share_permission'] ?? 'not_required';
        $connection_type = $checkData['connection_type'] ?? null;
        $downlink_mbps = isset($checkData['downlink_mbps']) ? (float) $checkData['downlink_mbps'] : null;
        $latency_ms = !empty($checkData['latency_ms']) ? (int) $checkData['latency_ms'] : null;
        $network_sufficient = isset($checkData['network_sufficient']) ? (int) $checkData['network_sufficient'] : 1;
        $webcam_available = isset($checkData['webcam_available']) ? (int) $checkData['webcam_available'] : null;
        $webcam_resolution = $checkData['webcam_resolution'] ?? null;
        $microphone_available = isset($checkData['microphone_available']) ? (int) $checkData['microphone_available'] : null;
        $all_checks_passed = isset($checkData['all_checks_passed']) ? (int) $checkData['all_checks_passed'] : 0;
        $failed_checks = isset($checkData['failed_checks']) ? json_encode($checkData['failed_checks']) : null;
        $warnings = isset($checkData['warnings']) ? json_encode($checkData['warnings']) : null;

        $stmt = $this->conn->prepare("
            INSERT INTO proc_system_check_log (
                session_id, tenant_id, user_id, browser_name, browser_version, browser_supported,
                os_name, os_version, device_type, screen_width, screen_height, screen_sufficient,
                camera_permission, camera_working, microphone_permission, microphone_working,
                screen_share_permission, connection_type, downlink_mbps, latency_ms, network_sufficient,
                webcam_available, webcam_resolution, microphone_available, all_checks_passed,
                failed_checks, warnings, checked_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param(
            "iiissississisissssdiiisisiss",
            $session_id, $this->tenant_id, $this->user_id, $browser_name, $browser_version, $browser_supported,
            $os_name, $os_version, $device_type, $screen_width, $screen_height, $screen_sufficient,
            $camera_permission, $camera_working, $microphone_permission, $microphone_working,
            $screen_share_permission, $connection_type, $downlink_mbps, $latency_ms, $network_sufficient,
            $webcam_available, $webcam_resolution, $microphone_available, $all_checks_passed,
            $failed_checks, $warnings
        );

        if ($stmt->execute()) {
            $stmt->close();

            // Update session
            $systemCheckData = json_encode($checkData);
            $updateStmt = $this->conn->prepare("
                UPDATE proc_proctoring_sessions SET
                    system_check_passed = ?, system_check_at = NOW(), system_check_data = ?,
                    status = IF(? = 1, 'active', 'system_check')
                WHERE session_id = ?
            ");
            $updateStmt->bind_param("isii", $all_checks_passed, $systemCheckData, $all_checks_passed, $session_id);
            $updateStmt->execute();
            $updateStmt->close();

            return [
                'success' => true,
                'passed' => (bool) $all_checks_passed,
                'message' => $all_checks_passed ? 'System check passed' : 'System check failed'
            ];
        }

        $stmt->close();
        return ['success' => false, 'message' => 'Failed to save system check'];
    }

    // ==========================================
    // VIOLATIONS
    // ==========================================

    /**
     * Log a violation
     */
    public function logViolation(int $session_id, int $attempt_id, array $data): array
    {
        $violation_type = $data['violation_type'] ?? 'other';
        $severity = $data['severity'] ?? 'medium';
        $description = $data['description'] ?? null;
        $question_id = !empty($data['question_id']) ? (int) $data['question_id'] : null;
        $question_index = isset($data['question_index']) ? (int) $data['question_index'] : null;
        $snapshot_path = $data['snapshot_path'] ?? null;
        $event_data = isset($data['event_data']) ? json_encode($data['event_data']) : null;
        $action_taken = $data['action_taken'] ?? 'none';

        // Calculate violation score based on severity
        $scoreMap = ['low' => 0.5, 'medium' => 1.0, 'high' => 2.0, 'critical' => 5.0];
        $violation_score = $scoreMap[$severity] ?? 1.0;

        $stmt = $this->conn->prepare("
            INSERT INTO proc_proctoring_violations (
                session_id, attempt_id, tenant_id, violation_type, severity, violation_score,
                description, question_id, question_index, snapshot_path, event_data, action_taken, timestamp
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(3))
        ");
        $stmt->bind_param(
            "iiissdssisss",
            $session_id, $attempt_id, $this->tenant_id, $violation_type, $severity, $violation_score,
            $description, $question_id, $question_index, $snapshot_path, $event_data, $action_taken
        );

        if ($stmt->execute()) {
            $violation_id = $stmt->insert_id;
            $stmt->close();

            // Get updated session stats
            $session = $this->getSession($attempt_id);
            
            return [
                'success' => true,
                'violation_id' => $violation_id,
                'trust_score' => $session['trust_score'] ?? 100,
                'total_violations' => $session['total_violations'] ?? 1
            ];
        }

        $stmt->close();
        return ['success' => false, 'message' => 'Failed to log violation'];
    }

    /**
     * Get violations for an attempt
     */
    public function getViolations(int $attempt_id): array
    {
        $stmt = $this->conn->prepare("
            SELECT * FROM proc_proctoring_violations
            WHERE attempt_id = ? AND tenant_id = ?
            ORDER BY timestamp ASC
        ");
        $stmt->bind_param("ii", $attempt_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $violations = [];
        while ($row = $result->fetch_assoc()) {
            $row['violation_id'] = (int) $row['violation_id'];
            $row['violation_score'] = (float) $row['violation_score'];
            $row['user_acknowledged'] = (bool) $row['user_acknowledged'];
            $row['reviewed'] = (bool) $row['reviewed'];
            $row['event_data'] = $row['event_data'] ? json_decode($row['event_data'], true) : null;
            $violations[] = $row;
        }
        $stmt->close();

        return $violations;
    }

    /**
     * Acknowledge violation
     */
    public function acknowledgeViolation(int $violation_id): array
    {
        $stmt = $this->conn->prepare("
            UPDATE proc_proctoring_violations 
            SET user_acknowledged = 1, acknowledged_at = NOW()
            WHERE violation_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("ii", $violation_id, $this->tenant_id);

        if ($stmt->execute()) {
            $stmt->close();
            return ['success' => true, 'message' => 'Violation acknowledged'];
        }

        $stmt->close();
        return ['success' => false, 'message' => 'Failed to acknowledge violation'];
    }

    // ==========================================
    // SNAPSHOTS
    // ==========================================

    /**
     * Save a snapshot
     */
    public function saveSnapshot(int $session_id, int $attempt_id, array $data): array
    {
        $snapshot_type = $data['snapshot_type'] ?? 'periodic';
        $image_path = $data['image_path'] ?? '';
        $thumbnail_path = $data['thumbnail_path'] ?? null;
        $file_size_bytes = !empty($data['file_size_bytes']) ? (int) $data['file_size_bytes'] : null;
        $question_id = !empty($data['question_id']) ? (int) $data['question_id'] : null;
        $question_index = isset($data['question_index']) ? (int) $data['question_index'] : null;
        $time_elapsed_seconds = (int) ($data['time_elapsed_seconds'] ?? 0);
        $face_detected = isset($data['face_detected']) ? (int) $data['face_detected'] : null;
        $face_count = isset($data['face_count']) ? (int) $data['face_count'] : null;
        $face_confidence = isset($data['face_confidence']) ? (float) $data['face_confidence'] : null;
        $face_position = isset($data['face_position']) ? json_encode($data['face_position']) : null;
        $objects_detected = isset($data['objects_detected']) ? json_encode($data['objects_detected']) : null;
        $flagged = isset($data['flagged']) ? (int) $data['flagged'] : 0;
        $flag_reason = $data['flag_reason'] ?? null;

        $stmt = $this->conn->prepare("
            INSERT INTO proc_proctoring_snapshots (
                session_id, attempt_id, tenant_id, snapshot_type, image_path, thumbnail_path,
                file_size_bytes, question_id, question_index, time_elapsed_seconds, face_detected,
                face_count, face_confidence, face_position, objects_detected, flagged, flag_reason, captured_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(3))
        ");
        $stmt->bind_param(
            "iiisssiiiiidsssis",
            $session_id, $attempt_id, $this->tenant_id, $snapshot_type, $image_path, $thumbnail_path,
            $file_size_bytes, $question_id, $question_index, $time_elapsed_seconds, $face_detected,
            $face_count, $face_confidence, $face_position, $objects_detected, $flagged, $flag_reason
        );

        if ($stmt->execute()) {
            $snapshot_id = $stmt->insert_id;
            $stmt->close();
            return ['success' => true, 'snapshot_id' => $snapshot_id];
        }

        $stmt->close();
        return ['success' => false, 'message' => 'Failed to save snapshot'];
    }

    /**
     * Get snapshots for an attempt
     */
    public function getSnapshots(int $attempt_id, ?string $type = null): array
    {
        $sql = "SELECT * FROM proc_proctoring_snapshots WHERE attempt_id = ? AND tenant_id = ?";
        $params = [$attempt_id, $this->tenant_id];
        $types = "ii";

        if ($type) {
            $sql .= " AND snapshot_type = ?";
            $params[] = $type;
            $types .= "s";
        }

        $sql .= " ORDER BY captured_at ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $snapshots = [];
        while ($row = $result->fetch_assoc()) {
            $row['snapshot_id'] = (int) $row['snapshot_id'];
            $row['face_detected'] = $row['face_detected'] !== null ? (bool) $row['face_detected'] : null;
            $row['flagged'] = (bool) $row['flagged'];
            $row['face_position'] = $row['face_position'] ? json_decode($row['face_position'], true) : null;
            $row['objects_detected'] = $row['objects_detected'] ? json_decode($row['objects_detected'], true) : null;
            $snapshots[] = $row;
        }
        $stmt->close();

        return $snapshots;
    }

    // ==========================================
    // REVIEW (Admin)
    // ==========================================

    /**
     * Get sessions for review
     */
    public function getSessionsForReview(array $filters = []): array
    {
        $flagged_only = isset($filters['flagged_only']) ? (bool) $filters['flagged_only'] : true;
        $reviewed = isset($filters['reviewed']) ? (bool) $filters['reviewed'] : false;
        $assessment_id = !empty($filters['assessment_id']) ? (int) $filters['assessment_id'] : null;
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $sql = "
            SELECT 
                ps.*,
                u.first_name, u.last_name, u.email,
                a.title as assessment_title,
                att.percentage_score, att.passed
            FROM proc_proctoring_sessions ps
            JOIN users u ON ps.user_id = u.id
            JOIN proc_attempts att ON ps.attempt_id = att.attempt_id
            JOIN proc_assessments a ON att.assessment_id = a.assessment_id
            WHERE ps.tenant_id = ?
        ";
        $params = [$this->tenant_id];
        $types = "i";

        if ($flagged_only) {
            $sql .= " AND ps.flagged_for_review = 1";
        }

        if (!$reviewed) {
            $sql .= " AND ps.reviewed = 0";
        }

        if ($assessment_id) {
            $sql .= " AND att.assessment_id = ?";
            $params[] = $assessment_id;
            $types .= "i";
        }

        $sql .= " ORDER BY ps.trust_score ASC, ps.total_violations DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= "ii";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $sessions = [];
        while ($row = $result->fetch_assoc()) {
            $sessions[] = $this->formatSession($row);
        }
        $stmt->close();

        return $sessions;
    }

    /**
     * Review a session
     */
    public function reviewSession(int $session_id, string $decision, string $notes = ''): array
    {
        $validDecisions = ['approved', 'suspicious', 'rejected'];
        if (!in_array($decision, $validDecisions)) {
            return ['success' => false, 'message' => 'Invalid decision'];
        }

        $stmt = $this->conn->prepare("
            UPDATE proc_proctoring_sessions SET
                reviewed = 1, reviewed_by = ?, reviewed_at = NOW(),
                review_notes = ?, review_decision = ?
            WHERE session_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("issii", $this->user_id, $notes, $decision, $session_id, $this->tenant_id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $stmt->close();
            return ['success' => true, 'message' => 'Session reviewed'];
        }

        $stmt->close();
        return ['success' => false, 'message' => 'Session not found'];
    }

    /**
     * Review a violation
     */
    public function reviewViolation(int $violation_id, string $status, string $notes = ''): array
    {
        $validStatuses = ['pending', 'valid', 'false_positive', 'inconclusive'];
        if (!in_array($status, $validStatuses)) {
            return ['success' => false, 'message' => 'Invalid status'];
        }

        $stmt = $this->conn->prepare("
            UPDATE proc_proctoring_violations SET
                reviewed = 1, reviewed_by = ?, reviewed_at = NOW(),
                review_status = ?, review_notes = ?
            WHERE violation_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("issii", $this->user_id, $status, $notes, $violation_id, $this->tenant_id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $stmt->close();
            return ['success' => true, 'message' => 'Violation reviewed'];
        }

        $stmt->close();
        return ['success' => false, 'message' => 'Violation not found'];
    }

    // ==========================================
    // STATISTICS
    // ==========================================

    /**
     * Get proctoring statistics for an assessment
     */
    public function getAssessmentProctoringStats(int $assessment_id): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                COUNT(*) as total_sessions,
                SUM(CASE WHEN ps.status = 'completed' THEN 1 ELSE 0 END) as completed_sessions,
                SUM(CASE WHEN ps.flagged_for_review = 1 THEN 1 ELSE 0 END) as flagged_sessions,
                SUM(CASE WHEN ps.reviewed = 1 THEN 1 ELSE 0 END) as reviewed_sessions,
                AVG(trust_score) as avg_trust_score,
                AVG(total_violations) as avg_violations,
                SUM(total_violations) as total_violations,
                SUM(total_snapshots) as total_snapshots
            FROM proc_proctoring_sessions ps
            JOIN proc_attempts a ON ps.attempt_id = a.attempt_id
            WHERE a.assessment_id = ? AND ps.tenant_id = ?
        ");
        $stmt->bind_param("ii", $assessment_id, $this->tenant_id);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Get violation breakdown
        $stmt = $this->conn->prepare("
            SELECT violation_type, COUNT(*) as count
            FROM proc_proctoring_violations v
            JOIN proc_attempts a ON v.attempt_id = a.attempt_id
            WHERE a.assessment_id = ? AND v.tenant_id = ?
            GROUP BY violation_type
            ORDER BY count DESC
        ");
        $stmt->bind_param("ii", $assessment_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $violationBreakdown = [];
        while ($row = $result->fetch_assoc()) {
            $violationBreakdown[$row['violation_type']] = (int) $row['count'];
        }
        $stmt->close();

        return [
            'total_sessions' => (int) $stats['total_sessions'],
            'completed_sessions' => (int) $stats['completed_sessions'],
            'flagged_sessions' => (int) $stats['flagged_sessions'],
            'reviewed_sessions' => (int) $stats['reviewed_sessions'],
            'pending_review' => (int) $stats['flagged_sessions'] - (int) $stats['reviewed_sessions'],
            'avg_trust_score' => $stats['avg_trust_score'] ? round((float) $stats['avg_trust_score'], 1) : null,
            'avg_violations' => $stats['avg_violations'] ? round((float) $stats['avg_violations'], 1) : 0,
            'total_violations' => (int) $stats['total_violations'],
            'total_snapshots' => (int) $stats['total_snapshots'],
            'violation_breakdown' => $violationBreakdown
        ];
    }
}
