<?php

/**
 * LiveTraining Class
 *
 * Handles all Live Training operations including:
 * - Training CRUD operations
 * - Session management
 * - Enrollment management
 * - Attendance tracking (QR & Virtual)
 * - Certification eligibility
 * - Video conferencing integrations
 * - Reminders and notifications
 *
 * @package LMS
 * @version 1.0.0
 */

class LiveTraining
{
    private $conn;
    private int $tenant_id;
    private int $user_id;

    public function __construct($db_connection, int $tenant_id, int $user_id)
    {
        $this->conn = $db_connection;
        $this->tenant_id = $tenant_id;
        $this->user_id = $user_id;
    }

    /**
     * Helper method to bind parameters dynamically
     * Handles the reference issue with bind_param
     */
    private function bindParams($stmt, $types, $params)
    {
        $refs = [];
        $refs[] = $types;

        foreach ($params as $key => $value) {
            $refs[] = &$params[$key];
        }

        return call_user_func_array([$stmt, 'bind_param'], $refs);
    }

    // ==========================================
    // TRAINING CRUD OPERATIONS
    // ==========================================

    /**
     * Create a new live training
     */
    public function createTraining(array $data): array
    {
        $sql = "INSERT INTO live_trainings (
            tenant_id, title, description, short_description, thumbnail_url,
            category_id, tags, training_type, max_capacity, allow_waitlist,
            certification_enabled, certificate_template_id, min_attendance_percentage,
            mandatory_sessions_only, auto_issue_certificate,
            reminder_24h, reminder_1h, reminder_15m, reminder_channels,
            qr_grace_period_minutes, qr_valid_before_start_minutes,
            min_virtual_duration_minutes, status, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($sql);

        // Assign values to variables for bind_param (requires references)
        $title = $data['title'];
        $description = $data['description'] ?? null;
        $short_description = $data['short_description'] ?? null;
        $thumbnail_url = $data['thumbnail_url'] ?? null;
        $category_id = $data['category_id'] ?? null;
        $tags = isset($data['tags']) ? json_encode($data['tags']) : null;
        $training_type = $data['training_type'] ?? 'virtual';
        $max_capacity = $data['max_capacity'] ?? null;
        $allow_waitlist = $data['allow_waitlist'] ?? 0;
        $certification_enabled = $data['certification_enabled'] ?? 0;
        $certificate_template_id = $data['certificate_template_id'] ?? null;
        $min_attendance_percentage = $data['min_attendance_percentage'] ?? 100.00;
        $mandatory_sessions_only = $data['mandatory_sessions_only'] ?? 0;
        $auto_issue_certificate = $data['auto_issue_certificate'] ?? 1;
        $reminder_24h = $data['reminder_24h'] ?? 1;
        $reminder_1h = $data['reminder_1h'] ?? 1;
        $reminder_15m = $data['reminder_15m'] ?? 0;
        $reminder_channels = isset($data['reminder_channels']) ? json_encode($data['reminder_channels']) : json_encode(['email', 'in_app']);
        $qr_grace_period_minutes = $data['qr_grace_period_minutes'] ?? 15;
        $qr_valid_before_start_minutes = $data['qr_valid_before_start_minutes'] ?? 30;
        $min_virtual_duration_minutes = $data['min_virtual_duration_minutes'] ?? 30;
        $status = $data['status'] ?? 'draft';

        $stmt->bind_param(
            "issssissiiiidiiiiisiiisi",
            $this->tenant_id,
            $title,
            $description,
            $short_description,
            $thumbnail_url,
            $category_id,
            $tags,
            $training_type,
            $max_capacity,
            $allow_waitlist,
            $certification_enabled,
            $certificate_template_id,
            $min_attendance_percentage,
            $mandatory_sessions_only,
            $auto_issue_certificate,
            $reminder_24h,
            $reminder_1h,
            $reminder_15m,
            $reminder_channels,
            $qr_grace_period_minutes,
            $qr_valid_before_start_minutes,
            $min_virtual_duration_minutes,
            $status,
            $this->user_id
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to create training: " . $stmt->error);
        }

        $training_id = $this->conn->insert_id;
        $stmt->close();

        return $this->getTraining($training_id);
    }

    /**
     * Update an existing training
     */
    public function updateTraining(int $training_id, array $data): array
    {
        $fields = [];
        $params = [];
        $types = "";

        $allowedFields = [
            'title' => 's', 'description' => 's', 'short_description' => 's',
            'thumbnail_url' => 's', 'category_id' => 'i', 'training_type' => 's',
            'max_capacity' => 'i', 'allow_waitlist' => 'i',
            'certification_enabled' => 'i', 'certificate_template_id' => 'i',
            'min_attendance_percentage' => 'd', 'mandatory_sessions_only' => 'i',
            'auto_issue_certificate' => 'i', 'reminder_24h' => 'i',
            'reminder_1h' => 'i', 'reminder_15m' => 'i',
            'qr_grace_period_minutes' => 'i', 'qr_valid_before_start_minutes' => 'i',
            'min_virtual_duration_minutes' => 'i', 'status' => 's', 'is_active' => 'i'
        ];

        foreach ($allowedFields as $field => $type) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
                $types .= $type;
            }
        }

        if (isset($data['tags'])) {
            $fields[] = "tags = ?";
            $params[] = json_encode($data['tags']);
            $types .= "s";
        }

        if (isset($data['reminder_channels'])) {
            $fields[] = "reminder_channels = ?";
            $params[] = json_encode($data['reminder_channels']);
            $types .= "s";
        }

        $fields[] = "updated_by = ?";
        $params[] = $this->user_id;
        $types .= "i";

        $params[] = $training_id;
        $params[] = $this->tenant_id;
        $types .= "ii";

        $sql = "UPDATE live_trainings SET " . implode(", ", $fields) .
            " WHERE training_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);

        if (!$stmt->execute()) {
            throw new Exception("Failed to update training: " . $stmt->error);
        }
        $stmt->close();

        // Update published_at if status changed to published
        if (isset($data['status']) && $data['status'] === 'published') {
            $this->conn->query("UPDATE live_trainings SET published_at = NOW() 
                               WHERE training_id = $training_id AND published_at IS NULL");
        }

        return $this->getTraining($training_id);
    }

    /**
     * Get single training with details
     */
    public function getTraining(int $training_id): ?array
    {
        $sql = "
            SELECT 
                lt.*,
                u.first_name as creator_first_name,
                u.last_name as creator_last_name,
                (SELECT COUNT(*) FROM live_training_sessions WHERE training_id = lt.training_id) as session_count,
                (SELECT COUNT(*) FROM live_training_enrollments WHERE training_id = lt.training_id AND status = 'enrolled') as enrollment_count,
                (SELECT MIN(session_date) FROM live_training_sessions WHERE training_id = lt.training_id AND status != 'cancelled') as first_session_date,
                (SELECT MAX(session_date) FROM live_training_sessions WHERE training_id = lt.training_id AND status != 'cancelled') as last_session_date
            FROM live_trainings lt
            LEFT JOIN users u ON lt.created_by = u.id
            WHERE lt.training_id = ? AND lt.tenant_id = ?
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $training_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $training = $result->fetch_assoc();
        $stmt->close();

        if ($training) {
            $training['tags'] = $training['tags'] ? json_decode($training['tags'], true) : [];
            $training['reminder_channels'] = $training['reminder_channels'] ? json_decode($training['reminder_channels'], true) : [];
            $training['session_count'] = (int) $training['session_count'];
            $training['enrollment_count'] = (int) $training['enrollment_count'];
        }

        return $training;
    }

    /**
     * Get all trainings with filters
     */
    public function getTrainings(array $filters = []): array
    {
        $sql = "
            SELECT 
                lt.*,
                u.first_name as creator_first_name,
                u.last_name as creator_last_name,
                (SELECT COUNT(*) FROM live_training_sessions WHERE training_id = lt.training_id) as session_count,
                (SELECT COUNT(*) FROM live_training_enrollments WHERE training_id = lt.training_id AND status = 'enrolled') as enrollment_count,
                (SELECT MIN(session_date) FROM live_training_sessions WHERE training_id = lt.training_id AND status != 'cancelled') as next_session_date
            FROM live_trainings lt
            LEFT JOIN users u ON lt.created_by = u.id
            WHERE lt.tenant_id = ?
        ";

        $params = [$this->tenant_id];
        $types = "i";

        if (!empty($filters['status'])) {
            $sql .= " AND lt.status = ?";
            $params[] = $filters['status'];
            $types .= "s";
        }

        if (isset($filters['is_active'])) {
            $sql .= " AND lt.is_active = ?";
            $params[] = $filters['is_active'];
            $types .= "i";
        }

        if (!empty($filters['training_type'])) {
            $sql .= " AND lt.training_type = ?";
            $params[] = $filters['training_type'];
            $types .= "s";
        }

        if (!empty($filters['category_id'])) {
            $sql .= " AND lt.category_id = ?";
            $params[] = $filters['category_id'];
            $types .= "i";
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (lt.title LIKE ? OR lt.description LIKE ?)";
            $search = "%{$filters['search']}%";
            $params[] = $search;
            $params[] = $search;
            $types .= "ss";
        }

        $sql .= " ORDER BY lt.created_at DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int) $filters['limit'];
            $types .= "i";

            if (!empty($filters['offset'])) {
                $sql .= " OFFSET ?";
                $params[] = (int) $filters['offset'];
                $types .= "i";
            }
        }

        $stmt = $this->conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();

        $trainings = [];
        while ($row = $result->fetch_assoc()) {
            $row['tags'] = $row['tags'] ? json_decode($row['tags'], true) : [];
            $row['session_count'] = (int) $row['session_count'];
            $row['enrollment_count'] = (int) $row['enrollment_count'];
            $trainings[] = $row;
        }
        $stmt->close();

        return $trainings;
    }

    /**
     * Delete a training (soft delete)
     */
    public function deleteTraining(int $training_id): bool
    {
        $sql = "UPDATE live_trainings SET is_active = 0, status = 'cancelled', updated_by = ? 
                WHERE training_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iii", $this->user_id, $training_id, $this->tenant_id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    // ==========================================
    // SESSION MANAGEMENT
    // ==========================================

    /**
     * Create a new session
     */
    public function createSession(int $training_id, array $data): array
    {
        // Get next session number
        $stmt = $this->conn->prepare(
            "SELECT COALESCE(MAX(session_number), 0) + 1 as next_num 
             FROM live_training_sessions WHERE training_id = ?"
        );
        $stmt->bind_param("i", $training_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $next_num = $result->fetch_assoc()['next_num'];
        $stmt->close();

        $sql = "INSERT INTO live_training_sessions (
            training_id, tenant_id, title, description, session_number,
            session_type, session_date, start_time, end_time, timezone,
            venue_name, venue_address, venue_room, venue_capacity, venue_map_url,
            video_platform, integration_id, is_mandatory, allow_late_join,
            late_threshold_minutes, hybrid_physical_allowed, hybrid_virtual_allowed
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($sql);

        // Assign values to variables for bind_param (requires references)
        $title = $data['title'];
        $description = $data['description'] ?? null;
        $session_type = $data['session_type'] ?? 'virtual';
        $session_date = $data['session_date'];
        $start_time = $data['start_time'];
        $end_time = $data['end_time'];
        $timezone = $data['timezone'] ?? 'UTC';
        $venue_name = $data['venue_name'] ?? null;
        $venue_address = $data['venue_address'] ?? null;
        $venue_room = $data['venue_room'] ?? null;
        $venue_capacity = $data['venue_capacity'] ?? null;
        $venue_map_url = $data['venue_map_url'] ?? null;
        $video_platform = $data['video_platform'] ?? null;
        $integration_id = $data['integration_id'] ?? null;
        $is_mandatory = $data['is_mandatory'] ?? 0;
        $allow_late_join = $data['allow_late_join'] ?? 1;
        $late_threshold_minutes = $data['late_threshold_minutes'] ?? 15;
        $hybrid_physical_allowed = $data['hybrid_physical_allowed'] ?? 1;
        $hybrid_virtual_allowed = $data['hybrid_virtual_allowed'] ?? 1;

        $stmt->bind_param(
            "iississssssssissiiiiii",
            $training_id,
            $this->tenant_id,
            $title,
            $description,
            $next_num,
            $session_type,
            $session_date,
            $start_time,
            $end_time,
            $timezone,
            $venue_name,
            $venue_address,
            $venue_room,
            $venue_capacity,
            $venue_map_url,
            $video_platform,
            $integration_id,
            $is_mandatory,
            $allow_late_join,
            $late_threshold_minutes,
            $hybrid_physical_allowed,
            $hybrid_virtual_allowed
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to create session: " . $stmt->error);
        }

        $session_id = $this->conn->insert_id;
        $stmt->close();

        // Generate QR code token
        $this->generateSessionQRCode($session_id);

        // Create meeting if virtual/hybrid
        if (in_array($data['session_type'], ['virtual', 'hybrid']) && !empty($data['video_platform'])) {
            $this->createVirtualMeeting($session_id, $data);
        }

        // Update enrollment session counts
        $this->updateEnrollmentSessionCounts($training_id);

        // Create reminders
        $this->createSessionReminders($session_id);

        return $this->getSession($session_id);
    }

    /**
     * Update a session
     */
    public function updateSession(int $session_id, array $data): array
    {
        $fields = [];
        $params = [];
        $types = "";

        $allowedFields = [
            'title' => 's', 'description' => 's', 'session_type' => 's',
            'session_date' => 's', 'start_time' => 's', 'end_time' => 's',
            'timezone' => 's', 'venue_name' => 's', 'venue_address' => 's',
            'venue_room' => 's', 'venue_capacity' => 'i', 'venue_map_url' => 's',
            'video_platform' => 's', 'is_mandatory' => 'i', 'allow_late_join' => 'i',
            'late_threshold_minutes' => 'i', 'status' => 's',
            'hybrid_physical_allowed' => 'i', 'hybrid_virtual_allowed' => 'i'
        ];

        foreach ($allowedFields as $field => $type) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
                $types .= $type;
            }
        }

        if (empty($fields)) {
            return $this->getSession($session_id);
        }

        $params[] = $session_id;
        $params[] = $this->tenant_id;
        $types .= "ii";

        $sql = "UPDATE live_training_sessions SET " . implode(", ", $fields) .
            " WHERE session_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);

        if (!$stmt->execute()) {
            throw new Exception("Failed to update session: " . $stmt->error);
        }
        $stmt->close();

        // Regenerate reminders if date/time changed
        if (isset($data['session_date']) || isset($data['start_time'])) {
            $this->createSessionReminders($session_id);
        }

        return $this->getSession($session_id);
    }

    /**
     * Get single session
     */
    public function getSession(int $session_id): ?array
    {
        $sql = "
            SELECT 
                lts.*,
                lt.title as training_title,
                lt.training_type as training_default_type,
                (SELECT COUNT(*) FROM live_training_attendance WHERE session_id = lts.session_id AND status = 'present') as present_count,
                (SELECT COUNT(*) FROM live_training_attendance WHERE session_id = lts.session_id AND status = 'late') as late_count,
                (SELECT COUNT(*) FROM live_training_attendance WHERE session_id = lts.session_id AND status = 'absent') as absent_count,
                (SELECT COUNT(*) FROM live_training_enrollments WHERE training_id = lts.training_id AND status = 'enrolled') as expected_attendees
            FROM live_training_sessions lts
            JOIN live_trainings lt ON lts.training_id = lt.training_id
            WHERE lts.session_id = ? AND lts.tenant_id = ?
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $session_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $session = $result->fetch_assoc();
        $stmt->close();

        if ($session) {
            $session['instructors'] = $this->getSessionInstructors($session_id);
            $session['present_count'] = (int) $session['present_count'];
            $session['late_count'] = (int) $session['late_count'];
            $session['absent_count'] = (int) $session['absent_count'];
            $session['expected_attendees'] = (int) $session['expected_attendees'];
        }

        return $session;
    }

    /**
     * Get sessions for a training
     */
    public function getTrainingSessions(int $training_id): array
    {
        $sql = "
            SELECT 
                lts.*,
                (SELECT COUNT(*) FROM live_training_attendance WHERE session_id = lts.session_id AND status IN ('present', 'late')) as attended_count,
                (SELECT COUNT(*) FROM live_training_enrollments WHERE training_id = lts.training_id AND status = 'enrolled') as expected_attendees
            FROM live_training_sessions lts
            WHERE lts.training_id = ? AND lts.tenant_id = ?
            ORDER BY lts.session_date ASC, lts.start_time ASC
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $training_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $sessions = [];
        while ($row = $result->fetch_assoc()) {
            $row['attended_count'] = (int) $row['attended_count'];
            $row['expected_attendees'] = (int) $row['expected_attendees'];
            $row['instructors'] = $this->getSessionInstructors($row['session_id']);
            $sessions[] = $row;
        }
        $stmt->close();

        return $sessions;
    }

    /**
     * Delete a session
     */
    public function deleteSession(int $session_id): bool
    {
        // Get training_id first
        $stmt = $this->conn->prepare("SELECT training_id FROM live_training_sessions WHERE session_id = ? AND tenant_id = ?");
        $stmt->bind_param("ii", $session_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $session = $result->fetch_assoc();
        $stmt->close();

        if (!$session) {
            return false;
        }

        $sql = "UPDATE live_training_sessions SET status = 'cancelled' WHERE session_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $session_id, $this->tenant_id);
        $result = $stmt->execute();
        $stmt->close();

        // Update enrollment session counts
        $this->updateEnrollmentSessionCounts($session['training_id']);

        return $result;
    }

    // ==========================================
    // INSTRUCTOR MANAGEMENT
    // ==========================================

    /**
     * Assign instructor to session
     */
    public function assignInstructor(int $session_id, int $user_id, string $role = 'primary', bool $is_host = false): bool
    {
        $sql = "INSERT INTO live_training_instructors (session_id, user_id, tenant_id, role, is_host)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE role = VALUES(role), is_host = VALUES(is_host)";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiisi", $session_id, $user_id, $this->tenant_id, $role, $is_host);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Remove instructor from session
     */
    public function removeInstructor(int $session_id, int $user_id): bool
    {
        $sql = "DELETE FROM live_training_instructors WHERE session_id = ? AND user_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iii", $session_id, $user_id, $this->tenant_id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Get session instructors
     */
    public function getSessionInstructors(int $session_id): array
    {
        $sql = "
            SELECT 
                lti.*,
                u.first_name,
                u.last_name,
                u.email,
                u.avatar
            FROM live_training_instructors lti
            JOIN users u ON lti.user_id = u.id
            WHERE lti.session_id = ? AND lti.tenant_id = ?
            ORDER BY lti.role ASC
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $session_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $instructors = [];
        while ($row = $result->fetch_assoc()) {
            $instructors[] = $row;
        }
        $stmt->close();

        return $instructors;
    }

    // ==========================================
    // ENROLLMENT MANAGEMENT
    // ==========================================

    /**
     * Enroll learner in training
     */
    public function enrollLearner(int $training_id, int $user_id, ?int $enrolled_by = null): array
    {
        // Check if already enrolled
        $stmt = $this->conn->prepare(
            "SELECT enrollment_id, status FROM live_training_enrollments 
             WHERE training_id = ? AND user_id = ? AND tenant_id = ?"
        );
        $stmt->bind_param("iii", $training_id, $user_id, $this->tenant_id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            if ($existing['status'] === 'enrolled') {
                throw new Exception("User is already enrolled");
            }
            // Re-enroll cancelled user
            $sql = "UPDATE live_training_enrollments SET status = 'enrolled', cancelled_at = NULL, 
                    cancelled_by = NULL, cancellation_reason = NULL WHERE enrollment_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("i", $existing['enrollment_id']);
            $stmt->execute();
            $stmt->close();

            return $this->getEnrollment($existing['enrollment_id']);
        }

        // Check capacity
        $training = $this->getTraining($training_id);
        if ($training['max_capacity']) {
            if ($training['enrollment_count'] >= $training['max_capacity']) {
                if ($training['allow_waitlist']) {
                    return $this->addToWaitlist($training_id, $user_id, $enrolled_by);
                }
                throw new Exception("Training is at full capacity");
            }
        }

        // Get session count
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) as cnt FROM live_training_sessions 
             WHERE training_id = ? AND status != 'cancelled'"
        );
        $stmt->bind_param("i", $training_id);
        $stmt->execute();
        $session_count = $stmt->get_result()->fetch_assoc()['cnt'];
        $stmt->close();

        // Create enrollment
        $sql = "INSERT INTO live_training_enrollments (
            training_id, user_id, tenant_id, status, sessions_total, enrolled_by
        ) VALUES (?, ?, ?, 'enrolled', ?, ?)";

        $stmt = $this->conn->prepare($sql);
        $enrolled_by = $enrolled_by ?? $this->user_id;
        $stmt->bind_param("iiiii", $training_id, $user_id, $this->tenant_id, $session_count, $enrolled_by);

        if (!$stmt->execute()) {
            throw new Exception("Failed to enroll: " . $stmt->error);
        }

        $enrollment_id = $this->conn->insert_id;
        $stmt->close();

        // Create attendance records for all sessions
        $this->createAttendanceRecords($training_id, $enrollment_id, $user_id);

        // Queue welcome notification
        $this->queueNotification('enrollment', $user_id, $training_id, null, 'Welcome to the training');

        return $this->getEnrollment($enrollment_id);
    }

    /**
     * Add to waitlist
     */
    private function addToWaitlist(int $training_id, int $user_id, ?int $enrolled_by): array
    {
        $stmt = $this->conn->prepare(
            "SELECT COALESCE(MAX(waitlist_position), 0) + 1 as next_pos 
             FROM live_training_enrollments WHERE training_id = ? AND status = 'waitlisted'"
        );
        $stmt->bind_param("i", $training_id);
        $stmt->execute();
        $next_pos = $stmt->get_result()->fetch_assoc()['next_pos'];
        $stmt->close();

        $sql = "INSERT INTO live_training_enrollments (
            training_id, user_id, tenant_id, status, waitlist_position, enrolled_by
        ) VALUES (?, ?, ?, 'waitlisted', ?, ?)";

        $stmt = $this->conn->prepare($sql);
        $enrolled_by = $enrolled_by ?? $this->user_id;
        $stmt->bind_param("iiiii", $training_id, $user_id, $this->tenant_id, $next_pos, $enrolled_by);

        if (!$stmt->execute()) {
            throw new Exception("Failed to add to waitlist: " . $stmt->error);
        }

        $enrollment_id = $this->conn->insert_id;
        $stmt->close();

        return $this->getEnrollment($enrollment_id);
    }

    /**
     * Cancel enrollment
     */
    public function cancelEnrollment(int $enrollment_id, ?string $reason = null): bool
    {
        $sql = "UPDATE live_training_enrollments SET 
                status = 'cancelled', 
                cancelled_at = NOW(), 
                cancelled_by = ?,
                cancellation_reason = ?
                WHERE enrollment_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("isii", $this->user_id, $reason, $enrollment_id, $this->tenant_id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Get enrollment
     */
    public function getEnrollment(int $enrollment_id): ?array
    {
        $sql = "
            SELECT 
                lte.*,
                u.first_name, u.last_name, u.email, u.avatar,
                lt.title as training_title
            FROM live_training_enrollments lte
            JOIN users u ON lte.user_id = u.id
            JOIN live_trainings lt ON lte.training_id = lt.training_id
            WHERE lte.enrollment_id = ? AND lte.tenant_id = ?
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $enrollment_id, $this->tenant_id);
        $stmt->execute();
        $enrollment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $enrollment;
    }

    /**
     * Get training enrollments
     */
    public function getTrainingEnrollments(int $training_id, ?string $status = null): array
    {
        $sql = "
            SELECT 
                lte.*,
                u.first_name, u.last_name, u.email, u.avatar
            FROM live_training_enrollments lte
            JOIN users u ON lte.user_id = u.id
            WHERE lte.training_id = ? AND lte.tenant_id = ?
        ";

        $params = [$training_id, $this->tenant_id];
        $types = "ii";

        if ($status) {
            $sql .= " AND lte.status = ?";
            $params[] = $status;
            $types .= "s";
        }

        $sql .= " ORDER BY lte.enrolled_at ASC";

        $stmt = $this->conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();

        $enrollments = [];
        while ($row = $result->fetch_assoc()) {
            $enrollments[] = $row;
        }
        $stmt->close();

        return $enrollments;
    }

    /**
     * Get current user's enrollments
     */
    public function getMyEnrollments(?string $status = null, ?int $limit = null, ?int $offset = null): array
    {
        $sql = "
            SELECT 
                lte.*,
                lt.title as training_title,
                lt.description as training_description,
                lt.training_type,
                lt.thumbnail_url,
                lt.status as training_status,
                (SELECT COUNT(*) FROM live_training_sessions WHERE training_id = lt.training_id AND status != 'cancelled') as total_sessions,
                (SELECT COUNT(*) FROM live_training_attendance WHERE enrollment_id = lte.enrollment_id AND status = 'present') as attended_sessions
            FROM live_training_enrollments lte
            JOIN live_trainings lt ON lte.training_id = lt.training_id
            WHERE lte.user_id = ? AND lte.tenant_id = ?
        ";

        $params = [$this->user_id, $this->tenant_id];
        $types = "ii";

        if ($status) {
            $sql .= " AND lte.status = ?";
            $params[] = $status;
            $types .= "s";
        }

        $sql .= " ORDER BY lte.enrolled_at DESC";

        if ($limit) {
            $sql .= " LIMIT ?";
            $params[] = $limit;
            $types .= "i";

            if ($offset) {
                $sql .= " OFFSET ?";
                $params[] = $offset;
                $types .= "i";
            }
        }

        $stmt = $this->conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();

        $enrollments = [];
        while ($row = $result->fetch_assoc()) {
            // Calculate progress percentage
            $total = (int)$row['total_sessions'];
            $attended = (int)$row['attended_sessions'];
            $row['progress_percentage'] = $total > 0 ? round(($attended / $total) * 100, 2) : 0;

            $enrollments[] = $row;
        }
        $stmt->close();

        return $enrollments;
    }

    /**
     * Create attendance records for enrolled learner
     */
    private function createAttendanceRecords(int $training_id, int $enrollment_id, int $user_id): void
    {
        $sql = "INSERT INTO live_training_attendance (session_id, user_id, enrollment_id, tenant_id, status)
                SELECT session_id, ?, ?, ?, 'absent'
                FROM live_training_sessions 
                WHERE training_id = ? AND status != 'cancelled'";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiii", $user_id, $enrollment_id, $this->tenant_id, $training_id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Update enrollment session counts
     */
    private function updateEnrollmentSessionCounts(int $training_id): void
    {
        $sql = "UPDATE live_training_enrollments lte
                SET sessions_total = (
                    SELECT COUNT(*) FROM live_training_sessions 
                    WHERE training_id = ? AND status != 'cancelled'
                )
                WHERE lte.training_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $training_id, $training_id);
        $stmt->execute();
        $stmt->close();
    }

    // ==========================================
    // ATTENDANCE TRACKING
    // ==========================================

    /**
     * Mark attendance via QR scan
     */
    public function markAttendanceQR(string $qr_token, int $user_id): array
    {
        // Validate QR token
        $stmt = $this->conn->prepare("
            SELECT lts.*, lt.qr_grace_period_minutes, lt.qr_valid_before_start_minutes
            FROM live_training_sessions lts
            JOIN live_trainings lt ON lts.training_id = lt.training_id
            WHERE lts.qr_code_token = ? AND lts.tenant_id = ?
        ");
        $stmt->bind_param("si", $qr_token, $this->tenant_id);
        $stmt->execute();
        $session = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$session) {
            throw new Exception("Invalid QR code");
        }

        // Check if session allows physical attendance
        if ($session['session_type'] === 'virtual') {
            throw new Exception("This session is virtual only");
        }

        // Check if QR code is valid (time-bound)
        $session_datetime = new DateTime($session['session_date'] . ' ' . $session['start_time']);
        $now = new DateTime();

        $valid_from = clone $session_datetime;
        $valid_from->modify("-{$session['qr_valid_before_start_minutes']} minutes");

        $valid_until = clone $session_datetime;
        $valid_until->modify("+{$session['qr_grace_period_minutes']} minutes");

        if ($now < $valid_from) {
            throw new Exception("QR code is not yet valid");
        }

        if ($now > $valid_until && !$session['allow_late_join']) {
            throw new Exception("QR code has expired");
        }

        // Check enrollment
        $enrollment = $this->checkEnrollment($session['training_id'], $user_id);
        if (!$enrollment) {
            throw new Exception("User is not enrolled in this training");
        }

        // Determine status (present or late)
        $status = 'present';
        if ($now > $session_datetime) {
            $minutes_late = ($now->getTimestamp() - $session_datetime->getTimestamp()) / 60;
            if ($minutes_late > $session['late_threshold_minutes']) {
                $status = 'late';
            }
        }

        // Update attendance
        return $this->updateAttendance($session['session_id'], $user_id, [
            'status' => $status,
            'attendance_mode' => 'physical',
            'qr_scanned_at' => $now->format('Y-m-d H:i:s'),
            'marked_at' => $now->format('Y-m-d H:i:s')
        ], 'qr_scan');
    }

    /**
     * Record virtual attendance (from platform webhook/API)
     */
    public function markAttendanceVirtual(int $session_id, int $user_id, array $virtual_data): array
    {
        $session = $this->getSession($session_id);
        if (!$session) {
            throw new Exception("Session not found");
        }

        if ($session['session_type'] === 'physical') {
            throw new Exception("This session is physical only");
        }

        // Check enrollment
        $enrollment = $this->checkEnrollment($session['training_id'], $user_id);
        if (!$enrollment) {
            throw new Exception("User is not enrolled in this training");
        }

        // Get training settings
        $training = $this->getTraining($session['training_id']);

        // Calculate duration
        $duration = $virtual_data['duration_minutes'] ?? 0;
        $min_duration = $training['min_virtual_duration_minutes'];

        // Determine status
        $status = 'absent';
        if ($duration >= $min_duration) {
            $status = 'present';

            // Check if late
            if (!empty($virtual_data['join_time'])) {
                $session_start = new DateTime($session['session_date'] . ' ' . $session['start_time']);
                $join_time = new DateTime($virtual_data['join_time']);
                $minutes_late = ($join_time->getTimestamp() - $session_start->getTimestamp()) / 60;

                if ($minutes_late > $session['late_threshold_minutes']) {
                    $status = 'late';
                }
            }
        }

        return $this->updateAttendance($session_id, $user_id, [
            'status' => $status,
            'attendance_mode' => 'virtual',
            'virtual_join_time' => $virtual_data['join_time'] ?? null,
            'virtual_leave_time' => $virtual_data['leave_time'] ?? null,
            'virtual_duration_minutes' => $duration,
            'virtual_attendance_data' => json_encode($virtual_data),
            'marked_at' => date('Y-m-d H:i:s')
        ], 'virtual_join');
    }

    /**
     * Manual attendance override
     */
    public function overrideAttendance(int $session_id, int $user_id, string $status, string $reason): array
    {
        return $this->updateAttendance($session_id, $user_id, [
            'status' => $status,
            'is_manual_override' => 1,
            'override_by' => $this->user_id,
            'override_at' => date('Y-m-d H:i:s'),
            'override_reason' => $reason,
            'marked_at' => date('Y-m-d H:i:s')
        ], 'manual_override');
    }

    /**
     * Update attendance record
     */
    private function updateAttendance(int $session_id, int $user_id, array $data, string $action): array
    {
        // Get current attendance
        $stmt = $this->conn->prepare("
            SELECT * FROM live_training_attendance 
            WHERE session_id = ? AND user_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("iii", $session_id, $user_id, $this->tenant_id);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$current) {
            throw new Exception("Attendance record not found");
        }

        // Check for duplicate attendance (hybrid sessions)
        if ($current['attendance_mode'] && $current['attendance_mode'] !== ($data['attendance_mode'] ?? null)) {
            if ($current['status'] !== 'absent') {
                throw new Exception("Already marked attendance via " . $current['attendance_mode']);
            }
        }

        // Build update query
        $fields = [];
        $params = [];
        $types = "";

        foreach ($data as $field => $value) {
            $fields[] = "$field = ?";
            $params[] = $value;
            $types .= is_int($value) ? "i" : "s";
        }

        $params[] = $current['attendance_id'];
        $types .= "i";

        $sql = "UPDATE live_training_attendance SET " . implode(", ", $fields) . " WHERE attendance_id = ?";
        $stmt = $this->conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);
        $stmt->execute();
        $stmt->close();

        // Log the action
        $this->logAttendance($current['attendance_id'], $session_id, $user_id, $action, $current['status'], $data['status'] ?? null);

        // Update enrollment stats
        $this->updateEnrollmentAttendance($current['enrollment_id']);

        // Check certification eligibility
        $this->checkCertificationEligibility($current['enrollment_id']);

        return $this->getAttendance($current['attendance_id']);
    }

    /**
     * Get attendance record
     */
    public function getAttendance(int $attendance_id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM live_training_attendance WHERE attendance_id = ?");
        $stmt->bind_param("i", $attendance_id);
        $stmt->execute();
        $attendance = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $attendance;
    }

    /**
     * Get session attendance
     */
    public function getSessionAttendance(int $session_id): array
    {
        $sql = "
            SELECT 
                lta.*,
                u.first_name, u.last_name, u.email, u.avatar
            FROM live_training_attendance lta
            JOIN users u ON lta.user_id = u.id
            WHERE lta.session_id = ? AND lta.tenant_id = ?
            ORDER BY u.last_name, u.first_name
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $session_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $attendance = [];
        while ($row = $result->fetch_assoc()) {
            $attendance[] = $row;
        }
        $stmt->close();

        return $attendance;
    }

    /**
     * Lock session attendance
     */
    public function lockSessionAttendance(int $session_id): bool
    {
        $sql = "UPDATE live_training_sessions SET 
                attendance_locked = 1, 
                attendance_locked_at = NOW(),
                attendance_locked_by = ?
                WHERE session_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iii", $this->user_id, $session_id, $this->tenant_id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Log attendance action
     */
    private function logAttendance(int $attendance_id, int $session_id, int $user_id, string $action, ?string $old_status, ?string $new_status): void
    {
        $sql = "INSERT INTO live_training_attendance_log (
            attendance_id, session_id, user_id, tenant_id, action, 
            old_status, new_status, performed_by, ip_address
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param(
            "iiiisssss",
            $attendance_id, $session_id, $user_id, $this->tenant_id,
            $action, $old_status, $new_status, $this->user_id, $ip
        );
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Update enrollment attendance stats
     */
    private function updateEnrollmentAttendance(int $enrollment_id): void
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
                WHERE lte.enrollment_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $enrollment_id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Check user enrollment
     */
    private function checkEnrollment(int $training_id, int $user_id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT * FROM live_training_enrollments 
            WHERE training_id = ? AND user_id = ? AND tenant_id = ? AND status = 'enrolled'
        ");
        $stmt->bind_param("iii", $training_id, $user_id, $this->tenant_id);
        $stmt->execute();
        $enrollment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $enrollment;
    }

    // ==========================================
    // QR CODE MANAGEMENT
    // ==========================================

    /**
     * Generate QR code token for session
     */
    public function generateSessionQRCode(int $session_id): string
    {
        $token = bin2hex(random_bytes(32));

        // Get session timing for expiry
        $session = $this->getSession($session_id);
        $training = $this->getTraining($session['training_id']);

        $session_datetime = new DateTime($session['session_date'] . ' ' . $session['start_time']);
        $session_datetime->modify("+{$training['qr_grace_period_minutes']} minutes");

        $sql = "UPDATE live_training_sessions SET 
                qr_code_token = ?,
                qr_code_generated_at = NOW(),
                qr_code_expires_at = ?
                WHERE session_id = ? AND tenant_id = ?";

        $expires_at = $session_datetime->format('Y-m-d H:i:s');

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ssii", $token, $expires_at, $session_id, $this->tenant_id);
        $stmt->execute();
        $stmt->close();

        return $token;
    }

    /**
     * Get QR code data for session
     */
    public function getSessionQRCode(int $session_id): array
    {
        $stmt = $this->conn->prepare("
            SELECT qr_code_token, qr_code_generated_at, qr_code_expires_at 
            FROM live_training_sessions 
            WHERE session_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("ii", $session_id, $this->tenant_id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $data;
    }

    // ==========================================
    // CERTIFICATION
    // ==========================================

    /**
     * Check and update certification eligibility
     */
    public function checkCertificationEligibility(int $enrollment_id): void
    {
        $enrollment = $this->getEnrollment($enrollment_id);
        if (!$enrollment) return;

        $training = $this->getTraining($enrollment['training_id']);
        if (!$training['certification_enabled']) return;

        $status = 'pending';
        $reason = null;

        // Check if training is complete
        $completed_sessions = $this->getCompletedSessionCount($enrollment['training_id']);
        $total_sessions = $enrollment['sessions_total'];

        if ($completed_sessions < $total_sessions) {
            // Training not complete yet
            return;
        }

        // Calculate eligibility based on settings
        if ($training['mandatory_sessions_only']) {
            // Check only mandatory sessions
            $mandatory_attended = $this->getMandatorySessionsAttended($enrollment_id);
            $mandatory_total = $this->getMandatorySessionCount($enrollment['training_id']);

            if ($mandatory_attended >= $mandatory_total) {
                $status = 'eligible';
            } else {
                $status = 'not_eligible';
                $reason = "Missed mandatory sessions: attended $mandatory_attended of $mandatory_total";
            }
        } else {
            // Check attendance percentage
            if ($enrollment['attendance_percentage'] >= $training['min_attendance_percentage']) {
                $status = 'eligible';
            } else {
                $status = 'not_eligible';
                $reason = "Attendance {$enrollment['attendance_percentage']}% below required {$training['min_attendance_percentage']}%";
            }
        }

        // Update enrollment
        $sql = "UPDATE live_training_enrollments SET 
                certification_status = ?,
                certification_reason = ?
                WHERE enrollment_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ssi", $status, $reason, $enrollment_id);
        $stmt->execute();
        $stmt->close();

        // Auto-issue certificate if eligible and enabled
        if ($status === 'eligible' && $training['auto_issue_certificate']) {
            $this->issueCertificate($enrollment_id);
        }
    }

    /**
     * Issue certificate
     */
    public function issueCertificate(int $enrollment_id): bool
    {
        // This would integrate with your certificate system
        // For now, just mark as issued
        $sql = "UPDATE live_training_enrollments SET 
                certification_status = 'issued',
                certificate_issued_at = NOW(),
                completed_at = NOW(),
                status = 'completed'
                WHERE enrollment_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $enrollment_id, $this->tenant_id);
        $result = $stmt->execute();
        $stmt->close();

        // Queue certificate notification
        $enrollment = $this->getEnrollment($enrollment_id);
        $this->queueNotification('certificate', $enrollment['user_id'], $enrollment['training_id'], null, 'Certificate issued');

        return $result;
    }

    /**
     * Revoke certificate
     */
    public function revokeCertificate(int $enrollment_id, string $reason): bool
    {
        $sql = "UPDATE live_training_enrollments SET 
                certification_status = 'revoked',
                certification_reason = ?
                WHERE enrollment_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("sii", $reason, $enrollment_id, $this->tenant_id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Get completed session count
     */
    private function getCompletedSessionCount(int $training_id): int
    {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as cnt FROM live_training_sessions 
            WHERE training_id = ? AND status = 'completed'
        ");
        $stmt->bind_param("i", $training_id);
        $stmt->execute();
        $count = $stmt->get_result()->fetch_assoc()['cnt'];
        $stmt->close();

        return (int) $count;
    }

    /**
     * Get mandatory sessions attended
     */
    private function getMandatorySessionsAttended(int $enrollment_id): int
    {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as cnt 
            FROM live_training_attendance lta
            JOIN live_training_sessions lts ON lta.session_id = lts.session_id
            WHERE lta.enrollment_id = ? AND lts.is_mandatory = 1 AND lta.status IN ('present', 'late')
        ");
        $stmt->bind_param("i", $enrollment_id);
        $stmt->execute();
        $count = $stmt->get_result()->fetch_assoc()['cnt'];
        $stmt->close();

        return (int) $count;
    }

    /**
     * Get mandatory session count
     */
    private function getMandatorySessionCount(int $training_id): int
    {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as cnt FROM live_training_sessions 
            WHERE training_id = ? AND is_mandatory = 1 AND status != 'cancelled'
        ");
        $stmt->bind_param("i", $training_id);
        $stmt->execute();
        $count = $stmt->get_result()->fetch_assoc()['cnt'];
        $stmt->close();

        return (int) $count;
    }

    // ==========================================
    // VIDEO CONFERENCING INTEGRATIONS
    // ==========================================

    /**
     * Get tenant integrations
     */
    public function getTenantIntegrations(): array
    {
        $sql = "SELECT * FROM tenant_video_integrations WHERE tenant_id = ? ORDER BY is_default DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $integrations = [];
        while ($row = $result->fetch_assoc()) {
            // Don't expose sensitive data
            unset($row['client_secret'], $row['access_token'], $row['refresh_token']);
            $row['settings'] = $row['settings'] ? json_decode($row['settings'], true) : [];
            $integrations[] = $row;
        }
        $stmt->close();

        return $integrations;
    }

    /**
     * Save tenant integration
     */
    public function saveTenantIntegration(array $data): array
    {
        $sql = "INSERT INTO tenant_video_integrations (
        tenant_id, platform, is_enabled, is_default,
        client_id, client_secret, access_token, refresh_token, token_expires_at,
        settings, connected_by, connected_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
        is_enabled = VALUES(is_enabled),
        is_default = VALUES(is_default),
        client_id = VALUES(client_id),
        client_secret = VALUES(client_secret),
        access_token = VALUES(access_token),
        refresh_token = VALUES(refresh_token),
        token_expires_at = VALUES(token_expires_at),
        settings = VALUES(settings)";

        // ✅ Assign to variables FIRST
        $tenant_id        = $this->tenant_id;
        $platform         = $data['platform'];
        $is_enabled       = $data['is_enabled'] ?? 1;
        $is_default       = $data['is_default'] ?? 0;
        $client_id        = $data['client_id'] ?? null;
        $client_secret    = $data['client_secret'] ?? null;
        $access_token     = $data['access_token'] ?? null;
        $refresh_token    = $data['refresh_token'] ?? null;
        $token_expires_at = $data['token_expires_at'] ?? null;
        $settings         = isset($data['settings']) ? json_encode($data['settings']) : null;
        $connected_by     = $this->user_id;

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param(
            "isiissssssi",
            $tenant_id,
            $platform,
            $is_enabled,
            $is_default,
            $client_id,
            $client_secret,
            $access_token,
            $refresh_token,
            $token_expires_at,
            $settings,
            $connected_by
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to save integration: " . $stmt->error);
        }

        $stmt->close();

        // If set as default, unset others
        if ($is_default) {
            $platformEscaped = $this->conn->real_escape_string($platform);
            $this->conn->query("
            UPDATE tenant_video_integrations
            SET is_default = 0
            WHERE tenant_id = {$tenant_id}
              AND platform != '{$platformEscaped}'
        ");
        }

        return $this->getIntegration($platform);
    }

    /**
     * Get single integration
     */
    public function getIntegration(string $platform): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM tenant_video_integrations WHERE tenant_id = ? AND platform = ?");
        $stmt->bind_param("is", $this->tenant_id, $platform);
        $stmt->execute();
        $integration = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($integration) {
            $integration['settings'] = $integration['settings'] ? json_decode($integration['settings'], true) : [];
        }

        return $integration;
    }

    /**
     * Disconnect integration
     */
    public function disconnectIntegration(string $platform): bool
    {
        $sql = "UPDATE tenant_video_integrations SET 
                is_enabled = 0,
                access_token = NULL,
                refresh_token = NULL,
                token_expires_at = NULL
                WHERE tenant_id = ? AND platform = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("is", $this->tenant_id, $platform);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Create virtual meeting (placeholder - implement per platform)
     */
    private function createVirtualMeeting(int $session_id, array $data): void
    {
        // This would call the appropriate platform API
        // For now, just store placeholder data
        $meeting_url = "https://meet.example.com/" . bin2hex(random_bytes(8));

        $sql = "UPDATE live_training_sessions SET 
                meeting_url = ?,
                join_url = ?
                WHERE session_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ssi", $meeting_url, $meeting_url, $session_id);
        $stmt->execute();
        $stmt->close();
    }

    // ==========================================
    // REMINDERS & NOTIFICATIONS
    // ==========================================

    /**
     * Create session reminders
     */
    private function createSessionReminders(int $session_id): void
    {
        $session = $this->getSession($session_id);
        $training = $this->getTraining($session['training_id']);

        $session_datetime = new DateTime($session['session_date'] . ' ' . $session['start_time']);

        // Delete existing reminders
        $this->conn->query("DELETE FROM live_training_reminders WHERE session_id = $session_id");

        // Create reminders based on training settings
        $reminders = [];

        if ($training['reminder_24h']) {
            $time = clone $session_datetime;
            $time->modify('-24 hours');
            $reminders[] = ['type' => '24h', 'time' => $time];
        }

        if ($training['reminder_1h']) {
            $time = clone $session_datetime;
            $time->modify('-1 hour');
            $reminders[] = ['type' => '1h', 'time' => $time];
        }

        if ($training['reminder_15m']) {
            $time = clone $session_datetime;
            $time->modify('-15 minutes');
            $reminders[] = ['type' => '15m', 'time' => $time];
        }

        foreach ($reminders as $reminder) {
            if ($reminder['time'] > new DateTime()) {
                $sql = "INSERT INTO live_training_reminders (session_id, tenant_id, reminder_type, scheduled_at)
                        VALUES (?, ?, ?, ?)";
                $scheduled = $reminder['time']->format('Y-m-d H:i:s');
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("iiss", $session_id, $this->tenant_id, $reminder['type'], $scheduled);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    /**
     * Queue notification
     */
    private function queueNotification(string $type, int $user_id, ?int $training_id, ?int $session_id, string $message): void
    {
        $sql = "INSERT INTO live_training_notification_queue (
            tenant_id, notification_type, recipient_user_id, channel, message,
            training_id, session_id, scheduled_at
        ) VALUES (?, ?, ?, 'in_app', ?, ?, ?, NOW())";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("isisii", $this->tenant_id, $type, $user_id, $message, $training_id, $session_id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Process pending reminders (called by cron)
     */
    public function processPendingReminders(): array
    {
        $processed = ['success' => 0, 'failed' => 0];

        $sql = "SELECT * FROM live_training_reminders 
                WHERE status = 'pending' AND scheduled_at <= NOW() AND tenant_id = ?
                LIMIT 100";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($reminder = $result->fetch_assoc()) {
            try {
                $this->sendReminder($reminder);
                $processed['success']++;
            } catch (Exception $e) {
                $processed['failed']++;
                $this->conn->query("UPDATE live_training_reminders SET status = 'failed', 
                                   error_message = '{$e->getMessage()}' WHERE reminder_id = {$reminder['reminder_id']}");
            }
        }
        $stmt->close();

        return $processed;
    }

    /**
     * Send reminder
     */
    private function sendReminder(array $reminder): void
    {
        $session = $this->getSession($reminder['session_id']);
        $enrollments = $this->getTrainingEnrollments($session['training_id'], 'enrolled');
        $instructors = $this->getSessionInstructors($reminder['session_id']);

        $recipients_count = 0;
        $successful = 0;

        // Send to learners
        foreach ($enrollments as $enrollment) {
            $this->queueNotification(
                'reminder',
                $enrollment['user_id'],
                $session['training_id'],
                $session['session_id'],
                "Reminder: {$session['title']} starts in " . $this->getReminderText($reminder['reminder_type'])
            );
            $recipients_count++;
            $successful++;
        }

        // Send to instructors
        foreach ($instructors as $instructor) {
            $this->queueNotification(
                'reminder',
                $instructor['user_id'],
                $session['training_id'],
                $session['session_id'],
                "Instructor Reminder: {$session['title']} starts in " . $this->getReminderText($reminder['reminder_type'])
            );
            $recipients_count++;
            $successful++;
        }

        // Update reminder status
        $sql = "UPDATE live_training_reminders SET 
                status = 'sent', 
                sent_at = NOW(),
                recipients_count = ?,
                successful_count = ?
                WHERE reminder_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iii", $recipients_count, $successful, $reminder['reminder_id']);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Get reminder text
     */
    private function getReminderText(string $type): string
    {
        switch ($type) {
            case '24h': return '24 hours';
            case '1h': return '1 hour';
            case '15m': return '15 minutes';
            default: return $type;
        }
    }

    // ==========================================
    // REPORTING & ANALYTICS
    // ==========================================

    /**
     * Get training analytics
     */
    public function getTrainingAnalytics(int $training_id): array
    {
        $training = $this->getTraining($training_id);
        $sessions = $this->getTrainingSessions($training_id);
        $enrollments = $this->getTrainingEnrollments($training_id);

        // Session stats
        $total_sessions = count($sessions);
        $completed_sessions = count(array_filter($sessions, fn($s) => $s['status'] === 'completed'));

        // Attendance stats
        $total_present = 0;
        $total_late = 0;
        $total_absent = 0;
        $physical_count = 0;
        $virtual_count = 0;

        foreach ($sessions as $session) {
            $attendance = $this->getSessionAttendance($session['session_id']);
            foreach ($attendance as $a) {
                if ($a['status'] === 'present') $total_present++;
                if ($a['status'] === 'late') $total_late++;
                if ($a['status'] === 'absent') $total_absent++;
                if ($a['attendance_mode'] === 'physical') $physical_count++;
                if ($a['attendance_mode'] === 'virtual') $virtual_count++;
            }
        }

        // Certification stats
        $eligible = count(array_filter($enrollments, fn($e) => $e['certification_status'] === 'eligible'));
        $issued = count(array_filter($enrollments, fn($e) => $e['certification_status'] === 'issued'));
        $not_eligible = count(array_filter($enrollments, fn($e) => $e['certification_status'] === 'not_eligible'));

        // Average attendance
        $avg_attendance = count($enrollments) > 0
            ? array_sum(array_column($enrollments, 'attendance_percentage')) / count($enrollments)
            : 0;

        return [
            'training' => $training,
            'sessions' => [
                'total' => $total_sessions,
                'completed' => $completed_sessions,
                'remaining' => $total_sessions - $completed_sessions
            ],
            'enrollments' => [
                'total' => count($enrollments),
                'active' => count(array_filter($enrollments, fn($e) => $e['status'] === 'enrolled')),
                'completed' => count(array_filter($enrollments, fn($e) => $e['status'] === 'completed'))
            ],
            'attendance' => [
                'present' => $total_present,
                'late' => $total_late,
                'absent' => $total_absent,
                'physical' => $physical_count,
                'virtual' => $virtual_count,
                'average_percentage' => round($avg_attendance, 2)
            ],
            'certification' => [
                'eligible' => $eligible,
                'issued' => $issued,
                'not_eligible' => $not_eligible
            ]
        ];
    }

    /**
     * Get learner training progress
     */
    public function getLearnerProgress(int $training_id, int $user_id): array
    {
        $enrollment = $this->checkEnrollment($training_id, $user_id);
        if (!$enrollment) {
            throw new Exception("User is not enrolled");
        }

        $sql = "
            SELECT 
                lts.session_id,
                lts.title,
                lts.session_date,
                lts.start_time,
                lts.end_time,
                lts.session_type,
                lts.status as session_status,
                lts.is_mandatory,
                lta.status as attendance_status,
                lta.attendance_mode,
                lta.marked_at
            FROM live_training_sessions lts
            LEFT JOIN live_training_attendance lta ON lts.session_id = lta.session_id AND lta.user_id = ?
            WHERE lts.training_id = ? AND lts.status != 'cancelled'
            ORDER BY lts.session_date, lts.start_time
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $user_id, $training_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $sessions = [];
        while ($row = $result->fetch_assoc()) {
            $sessions[] = $row;
        }
        $stmt->close();

        return [
            'enrollment' => $enrollment,
            'sessions' => $sessions
        ];
    }

    /**
     * Get upcoming sessions for learner
     */
    public function getLearnerUpcomingSessions(int $user_id, int $limit = 10): array
    {
        $sql = "
            SELECT 
                lts.*,
                lt.title as training_title,
                lt.thumbnail_url,
                lta.status as attendance_status
            FROM live_training_sessions lts
            JOIN live_trainings lt ON lts.training_id = lt.training_id
            JOIN live_training_enrollments lte ON lt.training_id = lte.training_id AND lte.user_id = ?
            LEFT JOIN live_training_attendance lta ON lts.session_id = lta.session_id AND lta.user_id = ?
            WHERE lte.tenant_id = ? 
                AND lte.status = 'enrolled'
                AND lts.status = 'scheduled'
                AND CONCAT(lts.session_date, ' ', lts.start_time) >= NOW()
            ORDER BY lts.session_date, lts.start_time
            LIMIT ?
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiii", $user_id, $user_id, $this->tenant_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $sessions = [];
        while ($row = $result->fetch_assoc()) {
            $row['instructors'] = $this->getSessionInstructors($row['session_id']);
            $sessions[] = $row;
        }
        $stmt->close();

        return $sessions;
    }

    /**
     * Export attendance data
     */
    public function exportAttendanceData(int $training_id): array
    {
        $sql = "
            SELECT 
                u.first_name,
                u.last_name,
                u.email,
                lts.title as session_title,
                lts.session_date,
                lta.status,
                lta.attendance_mode,
                lta.marked_at,
                lte.attendance_percentage as overall_attendance
            FROM live_training_attendance lta
            JOIN live_training_sessions lts ON lta.session_id = lts.session_id
            JOIN live_training_enrollments lte ON lta.enrollment_id = lte.enrollment_id
            JOIN users u ON lta.user_id = u.id
            WHERE lts.training_id = ? AND lts.tenant_id = ?
            ORDER BY u.last_name, u.first_name, lts.session_date
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $training_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        $stmt->close();

        return $data;
    }

    // ==========================================
// ENHANCED ENROLLMENT METHODS
// ==========================================

    /**
     * Bulk enroll users based on enrollment rules
     *
     * @param int $training_id Training ID
     * @param array $rule Enrollment rule configuration
     * @return array Enrollment results with statistics
     */
    public function bulkEnrollByRule(int $training_id, array $rule): array
    {
        $enrollment_type = $rule['enrollment_type'];
        $target_criteria = $rule['target_criteria'] ?? [];

        // Validate enrollment type
        $valid_types = ['direct', 'department', 'branch', 'team'];
        if (!in_array($enrollment_type, $valid_types)) {
            throw new Exception("Invalid enrollment type: {$enrollment_type}");
        }

        // Get users based on enrollment rule
        $users = $this->getUsersByEnrollmentRule($enrollment_type, $target_criteria);

        if (empty($users)) {
            return [
                'total' => 0,
                'enrolled' => 0,
                'already_enrolled' => 0,
                'waitlisted' => 0,
                'failed' => 0,
                'errors' => [],
                'message' => 'No users match the enrollment criteria'
            ];
        }

        // Save enrollment rule
        $rule_id = $this->saveEnrollmentRule($training_id, $rule);

        // Enroll each user
        $results = [
            'total' => count($users),
            'enrolled' => 0,
            'already_enrolled' => 0,
            'waitlisted' => 0,
            'failed' => 0,
            'errors' => []
        ];

        foreach ($users as $user) {
            try {
                $enrollment = $this->enrollLearner(
                    $training_id,
                    $user['user_id'],
                    $this->user_id,
                    $rule_id
                );

                if ($enrollment['status'] === 'enrolled') {
                    $results['enrolled']++;
                } elseif ($enrollment['status'] === 'waitlisted') {
                    $results['waitlisted']++;
                }

            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'already enrolled') !== false) {
                    $results['already_enrolled']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = [
                        'user_id' => $user['user_id'],
                        'name' => $user['first_name'] . ' ' . $user['last_name'],
                        'email' => $user['email'],
                        'error' => $e->getMessage()
                    ];
                }
            }
        }

        // Add summary message
        $results['message'] = sprintf(
            'Enrolled %d users successfully. %d already enrolled, %d waitlisted, %d failed.',
            $results['enrolled'],
            $results['already_enrolled'],
            $results['waitlisted'],
            $results['failed']
        );

        return $results;
    }

    /**
     * Get users based on enrollment rule criteria
     *
     * @param string $enrollment_type Type of enrollment (direct, department, branch, team)
     * @param array $criteria Target criteria with IDs
     * @return array List of users matching criteria
     */
    private function getUsersByEnrollmentRule(string $enrollment_type, array $criteria): array
    {
        $sql = "SELECT DISTINCT u.id as user_id, u.first_name, u.last_name, u.email 
            FROM users u 
            WHERE u.tenant_id = ? AND u.is_active = 1";

        $params = [$this->tenant_id];
        $types = "i";

        switch ($enrollment_type) {
            case 'department':
                $dept_ids = $criteria['department_ids'] ?? [];
                if (empty($dept_ids)) {
                    return [];
                }
                $placeholders = implode(',', array_fill(0, count($dept_ids), '?'));
                $sql .= " AND u.department_id IN ($placeholders)";
                foreach ($dept_ids as $dept_id) {
                    $params[] = (int)$dept_id;
                    $types .= "i";
                }
                break;

            case 'branch':
                $branch_ids = $criteria['branch_ids'] ?? [];
                if (empty($branch_ids)) {
                    return [];
                }
                $placeholders = implode(',', array_fill(0, count($branch_ids), '?'));
                $sql .= " AND u.branch_id IN ($placeholders)";
                foreach ($branch_ids as $branch_id) {
                    $params[] = (int)$branch_id;
                    $types .= "i";
                }
                break;

            case 'team':
                $team_ids = $criteria['team_ids'] ?? [];
                if (empty($team_ids)) {
                    return [];
                }
                $placeholders = implode(',', array_fill(0, count($team_ids), '?'));
                $sql .= " AND EXISTS (
                SELECT 1 FROM team_members tm
                WHERE tm.user_id = u.id 
                AND tm.team_id IN ($placeholders)
                AND tm.tenant_id = ?
            )";
                foreach ($team_ids as $team_id) {
                    $params[] = (int)$team_id;
                    $types .= "i";
                }
                $params[] = $this->tenant_id;
                $types .= "i";
                break;

            case 'direct':
                $user_ids = $criteria['user_ids'] ?? [];
                if (empty($user_ids)) {
                    return [];
                }
                $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
                $sql .= " AND u.id IN ($placeholders)";
                foreach ($user_ids as $user_id) {
                    $params[] = (int)$user_id;
                    $types .= "i";
                }
                break;

            default:
                return [];
        }

        $sql .= " ORDER BY u.first_name, u.last_name";

        $stmt = $this->conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();

        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        $stmt->close();

        return $users;
    }

    /**
     * Save enrollment rule to database
     *
     * @param int $training_id Training ID
     * @param array $rule Rule configuration
     * @return int Rule ID
     */
    private function saveEnrollmentRule(int $training_id, array $rule): int
    {
        $sql = "INSERT INTO live_training_enrollment_rules (
        training_id, tenant_id, enrollment_type, target_criteria, 
        auto_enroll_new_users, auto_enroll_on_hire, is_active, created_by
    ) VALUES (?, ?, ?, ?, ?, ?, 1, ?)";

        $enrollment_type = $rule['enrollment_type'];
        $target_criteria = json_encode($rule['target_criteria']);
        $auto_enroll = isset($rule['auto_enroll_new_users']) ? (int)$rule['auto_enroll_new_users'] : 0;
        $auto_enroll_hire = isset($rule['auto_enroll_on_hire']) ? (int)$rule['auto_enroll_on_hire'] : 0;

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iissiii",
            $training_id,
            $this->tenant_id,
            $enrollment_type,
            $target_criteria,
            $auto_enroll,
            $auto_enroll_hire,
            $this->user_id
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to save enrollment rule: " . $stmt->error);
        }

        $rule_id = $this->conn->insert_id;
        $stmt->close();

        return $rule_id;
    }

    /**
     * Get enrollment rules for a training
     *
     * @param int $training_id Training ID
     * @return array List of enrollment rules
     */
    public function getEnrollmentRules(int $training_id): array
    {
        $sql = "SELECT 
                lter.*,
                u.first_name as created_by_first_name,
                u.last_name as created_by_last_name
            FROM live_training_enrollment_rules lter
            LEFT JOIN users u ON lter.created_by = u.id
            WHERE lter.training_id = ? AND lter.tenant_id = ?
            ORDER BY lter.created_at DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $training_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $rules = [];
        while ($row = $result->fetch_assoc()) {
            // Decode JSON target_criteria
            $row['target_criteria'] = json_decode($row['target_criteria'], true);
            $rules[] = $row;
        }
        $stmt->close();

        return $rules;
    }

    /**
     * Delete enrollment rule
     *
     * @param int $rule_id Rule ID
     * @return bool Success status
     */
    public function deleteEnrollmentRule(int $rule_id): bool
    {
        // Don't delete if there are enrollments linked to this rule
        $sql = "SELECT COUNT(*) as cnt FROM live_training_enrollments 
            WHERE enrollment_rule_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $rule_id, $this->tenant_id);
        $stmt->execute();
        $count = $stmt->get_result()->fetch_assoc()['cnt'];
        $stmt->close();

        if ($count > 0) {
            // Just deactivate instead of deleting
            $sql = "UPDATE live_training_enrollment_rules 
                SET is_active = 0, updated_by = ?, updated_at = NOW() 
                WHERE rule_id = ? AND tenant_id = ?";

            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("iii", $this->user_id, $rule_id, $this->tenant_id);
            $result = $stmt->execute();
            $stmt->close();

            return $result;
        }

        // No enrollments, safe to delete
        $sql = "DELETE FROM live_training_enrollment_rules 
            WHERE rule_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $rule_id, $this->tenant_id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Update existing enrollLearner to support rule_id
     * Modify the existing enrollLearner method signature to:
     *
     * public function enrollLearner(int $training_id, int $user_id, ?int $enrolled_by = null, ?int $rule_id = null): array
     *
     * And update the INSERT to:
     */
// Example modification for existing enrollLearner method:
    /*
    $sql = "INSERT INTO live_training_enrollments (
        training_id, user_id, tenant_id, status, sessions_total,
        enrolled_by, enrollment_rule_id, enrollment_method
    ) VALUES (?, ?, ?, 'enrolled', ?, ?, ?, ?)";

    $enrolled_by = $enrolled_by ?? $this->user_id;
    $enrollment_method = $rule_id ? 'rule_based' : 'manual';

    $stmt = $this->conn->prepare($sql);
    $stmt->bind_param("iiiiisi",
        $training_id, $user_id, $this->tenant_id, $session_count,
        $enrolled_by, $rule_id, $enrollment_method
    );
    */

    /**
     * Search/filter users for enrollment
     * Helper method for frontend user selection with filters
     *
     * @param array $filters Filter criteria
     * @return array List of users
     */
    public function searchUsersForEnrollment(array $filters = []): array
    {
        $sql = "SELECT DISTINCT 
                u.id as user_id, 
                u.first_name, 
                u.last_name, 
                u.email,
                u.department,
                d.department_name as department_name,
                u.branch,
                b.branch_name as branch_name
            FROM users u
            LEFT JOIN departments d ON u.department = d.department_id
            LEFT JOIN branches b ON u.branch = b.branch_id
            WHERE u.tenant_id = ? AND u.is_active = 1";

        $params = [$this->tenant_id];
        $types = "i";

        // Apply filters
        if (!empty($filters['search'])) {
            $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $types .= "sss";
        }

        if (!empty($filters['department_id'])) {
            $sql .= " AND u.department_id = ?";
            $params[] = (int)$filters['department_id'];
            $types .= "i";
        }

        if (!empty($filters['branch_id'])) {
            $sql .= " AND u.branch_id = ?";
            $params[] = (int)$filters['branch_id'];
            $types .= "i";
        }

        if (!empty($filters['team_id'])) {
            $sql .= " AND EXISTS (
            SELECT 1 FROM team_members tm
            WHERE tm.user_id = u.id AND tm.team_id = ?
        )";
            $params[] = (int)$filters['team_id'];
            $types .= "i";
        }

        // Exclude already enrolled users for specific training
        if (!empty($filters['exclude_training_id'])) {
            $sql .= " AND NOT EXISTS (
            SELECT 1 FROM live_training_enrollments lte
            WHERE lte.user_id = u.id 
            AND lte.training_id = ? 
            AND lte.status IN ('enrolled', 'waitlisted')
        )";
            $params[] = (int)$filters['exclude_training_id'];
            $types .= "i";
        }

        $sql .= " ORDER BY u.first_name, u.last_name";

        // Apply limit
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int)$filters['limit'];
            $types .= "i";
        }

        $stmt = $this->conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();

        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        $stmt->close();

        return $users;
    }

    // ==========================================
    // SKILLS MANAGEMENT METHODS
    // ==========================================

    /**
     * Assign skills to a training
     *
     * @param int $training_id Training ID
     * @param array $skills Array of skills to assign
     * @return array Assignment results
     */
    public function assignSkillsToTraining(int $training_id, array $skills): array
    {
        $results = [
            'added' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => []
        ];

        foreach ($skills as $skill) {
            try {
                $skill_id = (int)$skill['skill_id'];
                $proficiency = $skill['proficiency_level'] ?? 'beginner';
                $is_required = isset($skill['is_required']) ? (int)$skill['is_required'] : 1;

                // Validate proficiency level
                $valid_levels = ['beginner', 'intermediate', 'advanced', 'expert'];
                if (!in_array($proficiency, $valid_levels)) {
                    $proficiency = 'beginner';
                }

                $sql = "INSERT INTO live_training_skills (
                training_id, skill_id, tenant_id, proficiency_level, 
                is_required, created_by
            ) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                proficiency_level = VALUES(proficiency_level),
                is_required = VALUES(is_required),
                updated_at = NOW()";

                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("iiisii",
                    $training_id,
                    $skill_id,
                    $this->tenant_id,
                    $proficiency,
                    $is_required,
                    $this->user_id
                );

                if ($stmt->execute()) {
                    if ($stmt->affected_rows > 0) {
                        // Check if it was an insert or update
                        if ($stmt->insert_id > 0) {
                            $results['added']++;
                        } else {
                            $results['updated']++;
                        }
                    } else {
                        $results['skipped']++;
                    }
                }
                $stmt->close();

            } catch (Exception $e) {
                $results['errors'][] = [
                    'skill_id' => $skill['skill_id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Remove skill from training
     *
     * @param int $training_id Training ID
     * @param int $skill_id Skill ID
     * @return bool Success status
     */
    public function removeSkillFromTraining(int $training_id, int $skill_id): bool
    {
        $sql = "DELETE FROM live_training_skills 
            WHERE training_id = ? AND skill_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iii", $training_id, $skill_id, $this->tenant_id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Get skills assigned to a training
     *
     * @param int $training_id Training ID
     * @return array List of skills
     */
    public function getTrainingSkills(int $training_id): array
    {
        $sql = "SELECT 
                lts.*,
                s.name as skill_name,
                s.category_id,
                sc.name as skill_category,
                s.description as skill_description
            FROM live_training_skills lts
            JOIN skills s ON lts.skill_id = s.skill_id
            LEFT JOIN skill_categories sc ON s.category_id = sc.category_id
            WHERE lts.training_id = ? AND lts.tenant_id = ?
            ORDER BY sc.name, s.name ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $training_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $skills = [];
        while ($row = $result->fetch_assoc()) {
            $skills[] = $row;
        }
        $stmt->close();

        return $skills;
    }

    /**
     * Award skills to user when training is completed
     * Should be called automatically when marking training as completed
     *
     * @param int $enrollment_id Enrollment ID
     * @return array Award results
     */
    public function awardSkillsOnCompletion(int $enrollment_id): array
    {
        // Get enrollment details
        $enrollment = $this->getEnrollment($enrollment_id);

        if (!$enrollment) {
            throw new Exception("Enrollment not found");
        }

        if ($enrollment['status'] !== 'completed') {
            throw new Exception("Training must be completed to award skills");
        }

        $training_id = $enrollment['training_id'];
        $user_id = $enrollment['user_id'];

        // Get skills assigned to this training
        $training_skills = $this->getTrainingSkills($training_id);

        if (empty($training_skills)) {
            return [
                'awarded' => 0,
                'already_acquired' => 0,
                'updated' => 0,
                'errors' => [],
                'message' => 'No skills assigned to this training'
            ];
        }

        $results = [
            'awarded' => 0,
            'already_acquired' => 0,
            'updated' => 0,
            'errors' => []
        ];

        foreach ($training_skills as $skill) {
            try {
                $sql = "INSERT INTO user_skills_acquired (
                user_id, skill_id, tenant_id, source_type, source_id, 
                proficiency_level, acquired_at
            ) VALUES (?, ?, ?, 'live_training', ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                proficiency_level = CASE 
                    WHEN FIELD(VALUES(proficiency_level), 'beginner', 'intermediate', 'advanced', 'expert') > 
                         FIELD(proficiency_level, 'beginner', 'intermediate', 'advanced', 'expert')
                    THEN VALUES(proficiency_level) 
                    ELSE proficiency_level 
                END,
                updated_at = NOW()";

                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("iiiis",
                    $user_id,
                    $skill['skill_id'],
                    $this->tenant_id,
                    $training_id,
                    $skill['proficiency_level']
                );

                if ($stmt->execute()) {
                    if ($stmt->insert_id > 0) {
                        // New skill acquired
                        $results['awarded']++;
                    } elseif ($stmt->affected_rows > 0) {
                        // Existing skill updated (higher proficiency)
                        $results['updated']++;
                    } else {
                        // Already acquired at same or higher level
                        $results['already_acquired']++;
                    }
                }
                $stmt->close();

            } catch (Exception $e) {
                $results['errors'][] = [
                    'skill_id' => $skill['skill_id'],
                    'skill_name' => $skill['skill_name'],
                    'error' => $e->getMessage()
                ];
            }
        }

        // Log skill acquisition
        if ($results['awarded'] > 0 || $results['updated'] > 0) {
            $this->logSkillAcquisition($user_id, $training_id, $results['awarded'] + $results['updated']);
        }

        $results['message'] = sprintf(
            'Awarded %d new skills, updated %d existing skills, %d already acquired.',
            $results['awarded'],
            $results['updated'],
            $results['already_acquired']
        );

        return $results;
    }

    /**
     * Get user's acquired skills
     *
     * @param int $user_id User ID
     * @param string|null $source_type Filter by source type (optional)
     * @return array List of acquired skills
     */
    public function getUserAcquiredSkills(int $user_id, ?string $source_type = null): array
    {
        $sql = "SELECT 
                usa.*,
                s.name as skill_name,
                s.category_id,
                sc.name as skill_category,
                s.description as skill_description
            FROM user_skills_acquired usa
            JOIN skills s ON usa.skill_id = s.skill_id
            LEFT JOIN skill_categories sc ON s.category_id = sc.category_id
            WHERE usa.user_id = ? AND usa.tenant_id = ? AND usa.is_active = 1";

        $params = [$user_id, $this->tenant_id];
        $types = "ii";

        if ($source_type) {
            $sql .= " AND usa.source_type = ?";
            $params[] = $source_type;
            $types .= "s";
        }

        $sql .= " ORDER BY usa.acquired_at DESC";

        $stmt = $this->conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();

        $skills = [];
        while ($row = $result->fetch_assoc()) {
            // Add source details if it's a live training
            if ($row['source_type'] === 'live_training' && $row['source_id']) {
                $training = $this->getTraining($row['source_id']);
                if ($training) {
                    $row['source_title'] = $training['title'];
                }
            }

            $skills[] = $row;
        }
        $stmt->close();

        return $skills;
    }

    /**
     * Get user's skills summary/statistics
     *
     * @param int $user_id User ID
     * @return array Skills statistics
     */
    public function getUserSkillsSummary(int $user_id): array
    {
        $sql = "SELECT 
                COUNT(DISTINCT usa.skill_id) as total_skills,
                SUM(CASE WHEN usa.proficiency_level = 'beginner' THEN 1 ELSE 0 END) as beginner_count,
                SUM(CASE WHEN usa.proficiency_level = 'intermediate' THEN 1 ELSE 0 END) as intermediate_count,
                SUM(CASE WHEN usa.proficiency_level = 'advanced' THEN 1 ELSE 0 END) as advanced_count,
                SUM(CASE WHEN usa.proficiency_level = 'expert' THEN 1 ELSE 0 END) as expert_count,
                SUM(CASE WHEN usa.source_type = 'live_training' THEN 1 ELSE 0 END) as from_trainings,
                SUM(CASE WHEN usa.source_type = 'course' THEN 1 ELSE 0 END) as from_courses,
                SUM(CASE WHEN usa.verified_by IS NOT NULL THEN 1 ELSE 0 END) as verified_count
            FROM user_skills_acquired usa
            WHERE usa.user_id = ? AND usa.tenant_id = ? AND usa.is_active = 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $summary = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Convert to integers
        foreach ($summary as $key => $value) {
            $summary[$key] = (int)$value;
        }

        return $summary;
    }

    /**
     * Log skill acquisition in audit log
     *
     * @param int $user_id User ID
     * @param int $training_id Training ID
     * @param int $skills_count Number of skills awarded
     */
    private function logSkillAcquisition(int $user_id, int $training_id, int $skills_count): void
    {
        try {
            $sql = "INSERT INTO user_skills_log (
            user_id, skill_id, tenant_id, action, source_type, source_id, 
            performed_by, notes
        ) VALUES (?, 0, ?, 'acquired', 'live_training', ?, ?, ?)";

            $notes = "Acquired {$skills_count} skills from live training completion";

            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("iiiis",
                $user_id,
                $this->tenant_id,
                $training_id,
                $this->user_id,
                $notes
            );
            $stmt->execute();
            $stmt->close();
        } catch (Exception $e) {
            // Don't fail on logging errors
            error_log("Failed to log skill acquisition: " . $e->getMessage());
        }
    }

    /**
     * Update existing checkCertificationEligibility method
     * Add this code to automatically award skills when training is completed
     *
     * Add this after marking enrollment as completed:
     */
    /*
    // In your existing checkCertificationEligibility method, add:

    if ($is_eligible) {
        // Mark as completed
        $sql = "UPDATE live_training_enrollments
                SET status = 'completed', completion_date = NOW()
                WHERE enrollment_id = ?";
        // ... execute update ...

        // ✅ NEW: Award skills on completion
        try {
            $this->awardSkillsOnCompletion($enrollment_id);
        } catch (Exception $e) {
            error_log("Failed to award skills for enrollment {$enrollment_id}: " . $e->getMessage());
            // Don't fail the completion, just log the error
        }
    }
    */

    /**
     * Search available skills for assignment
     * Helper method for frontend skill selection
     *
     * @param array $filters Search and filter criteria
     * @return array List of skills
     */
    public function searchSkills(array $filters = []): array
    {
        $sql = "SELECT 
                s.skill_id,
                s.name as skill_name,
                s.category_id,
                sc.name as skill_category,
                s.description as skill_description,
                s.is_active
            FROM skills s
            LEFT JOIN skill_categories sc ON s.category_id = sc.category_id
            WHERE s.tenant_id = ? AND s.is_active = 1";

        $params = [$this->tenant_id];
        $types = "i";

        // Apply search filter
        if (!empty($filters['search'])) {
            $sql .= " AND (s.name LIKE ? OR s.description LIKE ? OR sc.name LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $types .= "sss";
        }

        // Filter by category_id
        if (!empty($filters['category_id'])) {
            $sql .= " AND s.category_id = ?";
            $params[] = (int)$filters['category_id'];
            $types .= "i";
        }

        // Exclude already assigned skills for specific training
        if (!empty($filters['exclude_training_id'])) {
            $sql .= " AND NOT EXISTS (
            SELECT 1 FROM live_training_skills lts
            WHERE lts.skill_id = s.skill_id 
            AND lts.training_id = ?
        )";
            $params[] = (int)$filters['exclude_training_id'];
            $types .= "i";
        }

        $sql .= " ORDER BY sc.name, s.name LIMIT 100";

        $stmt = $this->conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();

        $skills = [];
        while ($row = $result->fetch_assoc()) {
            $skills[] = $row;
        }
        $stmt->close();

        return $skills;
    }

    /**
     * Get skills categories for filtering
     *
     * @return array List of skill categories
     */
    public function getSkillsCategories(): array
    {
        $sql = "SELECT 
                category_id,
                name as category_name,
                description
            FROM skill_categories 
            WHERE tenant_id = ? AND is_active = 1
            ORDER BY name";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $categories = [];
        while ($row = $result->fetch_assoc()) {
            $categories[] = $row;
        }
        $stmt->close();

        return $categories;
    }

}