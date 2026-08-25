<?php

/**
 * Training Enrollment Management Class
 * Handles bulk enrollment operations for Live Training
 * Mirrors CourseEnrollment functionality
 */

class TrainingEnrollment
{
    private $conn;
    private $tenant_id;

    public function __construct($db_connection)
    {
        $this->conn = $db_connection;
    }

    public function setTenant($tenant_id): void{
        $this->tenant_id = (int) $tenant_id;
    }

    // ==========================================
    // LOOKUP METHODS
    // ==========================================

    /**
     * Get departments with user count
     */
    public function getDepartments()
    {
        $sql = "SELECT 
                    d.department_id,
                    d.department_name as department_name,
                    d.department_code as department_code,
                    COUNT(DISTINCT u.id) as user_count
                FROM departments d
                LEFT JOIN users u ON d.department_id = u.department
                    AND u.tenant_id = ? AND u.is_active = 1
                WHERE d.tenant_id = ? AND d.is_active = 1
                GROUP BY d.department_id";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->tenant_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $departments = [];
        while ($row = $result->fetch_assoc()) {
            $departments[] = $row;
        }
        $stmt->close();

        return $departments;
    }

    /**
     * Get teams with user count
     */
    public function getTeams(): array
    {
        $sql = "SELECT 
                    t.team_id,
                    t.team_name as team_name,
                    t.team_code as team_code,
                    COUNT(DISTINCT u.id) as user_count
                FROM teams t
                LEFT JOIN users u ON t.team_id = u.team 
                    AND u.tenant_id = ? AND u.is_active = 1
                WHERE t.tenant_id = ? AND t.is_active = 1
                GROUP BY t.team_id";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->tenant_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $teams = [];
        while ($row = $result->fetch_assoc()) {
            $teams[] = $row;
        }
        $stmt->close();

        return $teams;
    }

    /**
     * Get branches with user count
     */
    public function getBranches(): array
    {
        $sql = "SELECT 
                    b.branch_id,
                    b.branch_name as branch_name,
                    b.branch_code as branch_code,
                    b.city,
                    b.country,
                    COUNT(DISTINCT u.id) as user_count
                FROM branches b
                LEFT JOIN users u ON b.branch_id = u.branch 
                    AND u.tenant_id = ? AND u.is_active = 1
                WHERE b.tenant_id = ? AND b.is_active = 1
                GROUP BY b.branch_id";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->tenant_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $branches = [];
        while ($row = $result->fetch_assoc()) {
            $branches[] = $row;
        }
        $stmt->close();

        return $branches;
    }

    /**
     * Get users with optional filters
     * Uses direct foreign keys: users.department, users.team, users.branch
     */
    public function getUsers($search = null, $department_id = null, $team_id = null, $branch_id = null)
    {
        $sql = "SELECT DISTINCT
                    u.id,
                    u.email,
                    u.first_name,
                    u.last_name,
                    d.department_name as department_name,
                    t.team_name as team_name,
                    b.branch_name as branch_name
                FROM users u
                LEFT JOIN departments d ON u.department = d.department_id
                LEFT JOIN teams t ON u.team = t.team_id
                LEFT JOIN branches b ON u.branch = b.branch_id
                WHERE u.tenant_id = ? AND u.is_active = 1";

        $params = [$this->tenant_id];
        $types = "i";

        if ($search) {
            $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
            $search_param = "%{$search}%";
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $types .= "sss";
        }

        if ($department_id) {
            $sql .= " AND u.department = ?";
            $params[] = (int) $department_id;
            $types .= "i";
        }

        if ($team_id) {
            $sql .= " AND u.team = ?";
            $params[] = (int) $team_id;
            $types .= "i";
        }

        if ($branch_id) {
            $sql .= " AND u.branch = ?";
            $params[] = (int) $branch_id;
            $types .= "i";
        }

        $sql .= " ORDER BY u.first_name, u.last_name LIMIT 100";

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
     * Get trainings with enrollment count
     */
    public function getTrainings($search = null, $status = 'published')
    {
        $sql = "SELECT 
                    lt.training_id,
                    lt.title,
                    lt.description,
                    lt.short_description,
                    lt.thumbnail_url,
                    lt.training_type,
                    lt.max_capacity,
                    lt.status,
                    c.name as category_name,
                    COUNT(DISTINCT lte.enrollment_id) as enrollment_count,
                    COUNT(DISTINCT lts.session_id) as session_count,
                    CASE 
                        WHEN lt.max_capacity IS NOT NULL AND COUNT(DISTINCT lte.enrollment_id) >= lt.max_capacity 
                        THEN 1 
                        ELSE 0 
                    END as is_full
                FROM live_trainings lt
                LEFT JOIN categories c ON lt.category_id = c.category_id
                LEFT JOIN live_training_enrollments lte ON lt.training_id = lte.training_id 
                    AND lte.status IN ('enrolled', 'completed')
                LEFT JOIN live_training_sessions lts ON lt.training_id = lts.training_id
                WHERE lt.tenant_id = ? AND lt.is_active = 1";

        $params = [$this->tenant_id];
        $types = "i";

        if ($status) {
            $sql .= " AND lt.status = ?";
            $params[] = $status;
            $types .= "s";
        }

        if ($search) {
            $sql .= " AND (lt.title LIKE ? OR lt.description LIKE ?)";
            $search_param = "%{$search}%";
            $params[] = $search_param;
            $params[] = $search_param;
            $types .= "ss";
        }

        $sql .= " GROUP BY lt.training_id ORDER BY lt.created_at DESC";

        $stmt = $this->conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();

        $trainings = [];
        while ($row = $result->fetch_assoc()) {
            $trainings[] = $row;
        }
        $stmt->close();

        return $trainings;
    }

    /**
     * Get users not enrolled in training
     */
    public function getUnenrolledUsers($training_id, $search = null)
    {
        $sql = "SELECT 
                    u.id,
                    u.email,
                    u.first_name,
                    u.last_name,
                    d.department_name as department_name,
                    t.team_name as team_name,
                    b.branch_name as branch_name
                FROM users u
                LEFT JOIN departments d ON u.department = d.department_id
                LEFT JOIN teams t ON u.team = t.team_id
                LEFT JOIN branches b ON u.branch = b.branch_id
                WHERE u.tenant_id = ? 
                AND u.is_active = 1
                AND NOT EXISTS (
                    SELECT 1 FROM live_training_enrollments lte
                    WHERE lte.user_id = u.id 
                    AND lte.training_id = ?
                    AND lte.status IN ('enrolled', 'completed', 'waitlisted')
                )";

        $params = [$this->tenant_id, (int) $training_id];
        $types = "ii";

        if ($search) {
            $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
            $search_param = "%{$search}%";
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $types .= "sss";
        }

        $sql .= " ORDER BY u.first_name, u.last_name LIMIT 100";

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
    // ENROLLMENT STATS
    // ==========================================

    /**
     * Get training enrollment statistics
     */
    public function getTrainingEnrollmentStats($training_id)
    {
        $sql = "SELECT 
                    COUNT(*) as total_enrolled,
                    SUM(CASE WHEN status = 'enrolled' THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                    SUM(CASE WHEN status = 'waitlisted' THEN 1 ELSE 0 END) as waitlisted,
                    AVG(CASE 
                        WHEN sessions_total > 0 
                        THEN (sessions_attended / sessions_total * 100) 
                        ELSE 0 
                    END) as avg_attendance
                FROM live_training_enrollments
                WHERE training_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $training_id, $this->tenant_id);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Convert to integers
        foreach ($stats as $key => $value) {
            $stats[$key] = ($key === 'avg_attendance') ? (float) $value : (int) $value;
        }

        return $stats;
    }

    /**
     * Get training enrollees with pagination
     */
    public function getTrainingEnrollees($training_id, $status = null, $page = 1, $limit = 20)
    {
        $offset = ($page - 1) * $limit;

        $sql = "SELECT 
                    lte.*,
                    u.email,
                    u.first_name,
                    u.last_name,
                    d.department_name as department_name,
                    t.team_name as team_name,
                    b.branch_name as branch_name,
                    CASE 
                        WHEN lte.sessions_total > 0 
                        THEN (lte.sessions_attended / lte.sessions_total * 100) 
                        ELSE 0 
                    END as attendance_percentage
                FROM live_training_enrollments lte
                JOIN users u ON lte.user_id = u.id
                LEFT JOIN departments d ON u.department = d.department_id
                LEFT JOIN teams t ON u.team = t.team_id
                LEFT JOIN branches b ON u.branch = b.branch_id
                WHERE lte.training_id = ? AND lte.tenant_id = ?";

        $params = [(int) $training_id, $this->tenant_id];
        $types = "ii";

        if ($status) {
            $sql .= " AND lte.status = ?";
            $params[] = $status;
            $types .= "s";
        }

        $sql .= " ORDER BY lte.enrolled_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= "ii";

        $stmt = $this->conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();

        $enrollees = [];
        while ($row = $result->fetch_assoc()) {
            $enrollees[] = $row;
        }
        $stmt->close();

        // Get total count
        $count_sql = "SELECT COUNT(*) as total 
                      FROM live_training_enrollments 
                      WHERE training_id = ? AND tenant_id = ?";

        if ($status) {
            $count_sql .= " AND status = ?";
        }

        $stmt = $this->conn->prepare($count_sql);
        if ($status) {
            $stmt->bind_param("iis", $training_id, $this->tenant_id, $status);
        } else {
            $stmt->bind_param("ii", $training_id, $this->tenant_id);
        }
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

        return [
            'enrollees' => $enrollees,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => (int) $total,
                'pages' => ceil($total / $limit)
            ]
        ];
    }

    // ==========================================
    // ENROLLMENT ACTIONS
    // ==========================================

    /**
     * Preview enrollment before execution
     */
    public function previewEnrollment($data)
    {
        $training_id = (int) $data['training_id'];
        $enrollment_type = $data['enrollment_type'];

        // Get training info
        $training = $this->getTrainingInfo($training_id);
        if (!$training) {
            return [
                'success' => false,
                'message' => 'Training not found'
            ];
        }

        // Get target users
        $target_users = $this->getTargetUsers($data);

        // Check existing enrollments
        $users_to_enroll = [];
        $users_already_enrolled = [];
        $users_to_waitlist = [];

        $enrolled_user_ids = $this->getEnrolledUserIds($training_id);

        foreach ($target_users as $user) {
            if (in_array($user['id'], $enrolled_user_ids)) {
                $users_already_enrolled[] = [
                    'user_id' => $user['id'],
                    'email' => $user['email'],
                    'name' => $user['first_name'] . ' ' . $user['last_name']
                ];
            } else {
                $users_to_enroll[] = [
                    'user_id' => $user['id'],
                    'email' => $user['email'],
                    'name' => $user['first_name'] . ' ' . $user['last_name']
                ];
            }
        }

        // Check capacity
        $current_enrollments = count($enrolled_user_ids);
        $will_enroll = count($users_to_enroll);
        $will_waitlist = 0;

        if ($training['max_capacity']) {
            $available_slots = $training['max_capacity'] - $current_enrollments;

            if ($will_enroll > $available_slots) {
                if ($training['allow_waitlist']) {
                    $will_waitlist = $will_enroll - $available_slots;
                    $users_to_waitlist = array_slice($users_to_enroll, $available_slots);
                    $users_to_enroll = array_slice($users_to_enroll, 0, $available_slots);
                    $will_enroll = $available_slots;
                } else {
                    return [
                        'success' => false,
                        'message' => 'Training is at capacity and waitlist is not enabled'
                    ];
                }
            }
        }

        return [
            'success' => true,
            'training' => [
                'training_id' => $training['training_id'],
                'title' => $training['title']
            ],
            'summary' => [
                'total_users' => count($target_users),
                'will_enroll' => $will_enroll,
                'already_enrolled' => count($users_already_enrolled),
                'will_waitlist' => $will_waitlist
            ],
            'users_to_enroll' => $users_to_enroll,
            'users_already_enrolled' => $users_already_enrolled,
            'users_to_waitlist' => $users_to_waitlist,
            'capacity_info' => $training['max_capacity'] ? [
                'max_capacity' => $training['max_capacity'],
                'current_enrollments' => $current_enrollments,
                'available_slots' => max(0, $training['max_capacity'] - $current_enrollments)
            ] : null
        ];
    }

    /**
     * Enroll users in training
     */
    public function enrollUsers($data)
    {
        $training_id = (int) $data['training_id'];
        $is_mandatory = isset($data['is_mandatory']) ? (bool) $data['is_mandatory'] : false;
        $notify_users = isset($data['notify_users']) ? (bool) $data['notify_users'] : true;
        $assigned_by = (int) ($data['user_id'] ?? 0);

        // Get training info
        $training = $this->getTrainingInfo($training_id);
        if (!$training) {
            return [
                'success' => false,
                'message' => 'Training not found'
            ];
        }

        // Get target users
        $target_users = $this->getTargetUsers($data);

        // Get session count
        $session_count = $this->getSessionCount($training_id);

        $enrolled = [];
        $waitlisted = [];
        $skipped = [];
        $failed = [];

        $enrolled_user_ids = $this->getEnrolledUserIds($training_id);
        $current_enrollments = count($enrolled_user_ids);

        foreach ($target_users as $user) {
            try {
                // Skip if already enrolled
                if (in_array($user['id'], $enrolled_user_ids)) {
                    $skipped[] = [
                        'user_id' => $user['id'],
                        'email' => $user['email'],
                        'name' => $user['first_name'] . ' ' . $user['last_name'],
                        'reason' => 'Already enrolled'
                    ];
                    continue;
                }

                // Check capacity
                $should_waitlist = false;
                if ($training['max_capacity']) {
                    if ($current_enrollments >= $training['max_capacity']) {
                        if ($training['allow_waitlist']) {
                            $should_waitlist = true;
                        } else {
                            $skipped[] = [
                                'user_id' => $user['id'],
                                'email' => $user['email'],
                                'name' => $user['first_name'] . ' ' . $user['last_name'],
                                'reason' => 'Training at capacity'
                            ];
                            continue;
                        }
                    }
                }

                // Enroll or waitlist user
                if ($should_waitlist) {
                    $enrollment_id = $this->addToWaitlist($training_id, $user['id'], $assigned_by);
                    $waitlist_position = $this->getWaitlistPosition($enrollment_id);

                    $waitlisted[] = [
                        'user_id' => $user['id'],
                        'email' => $user['email'],
                        'first_name' => $user['first_name'],
                        'last_name' => $user['last_name'],
                        'waitlist_position' => $waitlist_position
                    ];
                } else {
                    $enrollment_id = $this->createEnrollment($training_id, $user['id'], $session_count, $assigned_by);
                    $current_enrollments++;

                    $enrolled[] = [
                        'user_id' => $user['id'],
                        'email' => $user['email'],
                        'first_name' => $user['first_name'],
                        'last_name' => $user['last_name'],
                        'enrollment_id' => $enrollment_id
                    ];
                }

            } catch (Exception $e) {
                $failed[] = [
                    'user_id' => $user['id'],
                    'email' => $user['email'],
                    'name' => $user['first_name'] . ' ' . $user['last_name'],
                    'reason' => $e->getMessage()
                ];
            }
        }

        $enrolled_count = count($enrolled);
        $waitlisted_count = count($waitlisted);

        return [
            'success' => true,
            'message' => sprintf(
                'Successfully enrolled %d user(s)%s',
                $enrolled_count,
                $waitlisted_count > 0 ? " and waitlisted {$waitlisted_count} user(s)" : ''
            ),
            'summary' => [
                'total_users' => count($target_users),
                'enrolled_count' => $enrolled_count,
                'waitlisted_count' => $waitlisted_count,
                'skipped_count' => count($skipped),
                'failed_count' => count($failed)
            ],
            'enrolled' => $enrolled,
            'waitlisted' => $waitlisted,
            'skipped' => $skipped,
            'failed' => $failed,
            'training' => [
                'training_id' => $training['training_id'],
                'title' => $training['title']
            ]
        ];
    }

    /**
     * Unenroll users from training
     */
    public function unenrollUsers($data)
    {
        $training_id = (int) $data['training_id'];
        $user_ids = $data['user_ids'] ?? [];
        $reason = $data['reason'] ?? 'Admin removed';
        $removed_by = (int) ($data['user_id'] ?? 0);

        $success_count = 0;
        $failed_count = 0;

        foreach ($user_ids as $user_id) {
            $sql = "UPDATE live_training_enrollments 
                    SET status = 'cancelled', 
                        cancelled_at = NOW(),
                        cancelled_by = ?,
                        cancellation_reason = ?
                    WHERE training_id = ? 
                    AND user_id = ? 
                    AND tenant_id = ?
                    AND status IN ('enrolled', 'completed')";

            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("isiii", $removed_by, $reason, $training_id, $user_id, $this->tenant_id);

            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $success_count++;
            } else {
                $failed_count++;
            }
            $stmt->close();
        }

        return [
            'success' => true,
            'message' => sprintf('Unenrolled %d user(s), %d failed', $success_count, $failed_count),
            'summary' => [
                'success_count' => $success_count,
                'failed_count' => $failed_count
            ]
        ];
    }

    // ==========================================
    // HELPER METHODS
    // ==========================================

    private function bindParams($stmt, $types, $params)
    {
        $refs = [];
        $refs[] = $types;
        foreach ($params as $key => $value) {
            $refs[] = &$params[$key];
        }
        return call_user_func_array([$stmt, 'bind_param'], $refs);
    }

    private function getTrainingInfo($training_id)
    {
        $sql = "SELECT training_id, title, max_capacity, allow_waitlist 
                FROM live_trainings 
                WHERE training_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $training_id, $this->tenant_id);
        $stmt->execute();
        $training = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $training;
    }

    private function getTargetUsers($data)
    {
        $enrollment_type = $data['enrollment_type'];

        switch ($enrollment_type) {
            case 'department':
                return $this->getUsers(null, $data['department_id'], null, null);
            case 'team':
                return $this->getUsers(null, null, $data['team_id'], null);
            case 'branch':
                return $this->getUsers(null, null, null, $data['branch_id']);
            case 'users':
                return $this->getUsersByIds($data['user_ids'] ?? []);
            default:
                return [];
        }
    }

    private function getUsersByIds($user_ids)
    {
        if (empty($user_ids)) return [];

        $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
        $sql = "SELECT id, email, first_name, last_name 
                FROM users 
                WHERE id IN ($placeholders) AND tenant_id = ? AND is_active = 1";

        $stmt = $this->conn->prepare($sql);

        $params = $user_ids;
        $params[] = $this->tenant_id;
        $types = str_repeat('i', count($user_ids)) . 'i';

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

    private function getEnrolledUserIds($training_id)
    {
        $sql = "SELECT user_id FROM live_training_enrollments 
                WHERE training_id = ? AND tenant_id = ? 
                AND status IN ('enrolled', 'completed', 'waitlisted')";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $training_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $user_ids = [];
        while ($row = $result->fetch_assoc()) {
            $user_ids[] = (int) $row['user_id'];
        }
        $stmt->close();

        return $user_ids;
    }

    private function getSessionCount($training_id)
    {
        $sql = "SELECT COUNT(*) as cnt 
                FROM live_training_sessions 
                WHERE training_id = ? AND status != 'cancelled'";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $training_id);
        $stmt->execute();
        $count = $stmt->get_result()->fetch_assoc()['cnt'];
        $stmt->close();

        return (int) $count;
    }

    private function createEnrollment($training_id, $user_id, $session_count, $assigned_by)
    {
        $sql = "INSERT INTO live_training_enrollments (
                    training_id, user_id, tenant_id, status, 
                    sessions_total, enrolled_by
                ) VALUES (?, ?, ?, 'enrolled', ?, ?)";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiiii", $training_id, $user_id, $this->tenant_id, $session_count, $assigned_by);
        $stmt->execute();
        $enrollment_id = $this->conn->insert_id;
        $stmt->close();

        // Create attendance records for all sessions
        $this->createAttendanceRecords($training_id, $enrollment_id, $user_id);

        return $enrollment_id;
    }

    private function addToWaitlist($training_id, $user_id, $assigned_by)
    {
        // Get next waitlist position
        $sql = "SELECT COALESCE(MAX(waitlist_position), 0) + 1 as next_pos 
                FROM live_training_enrollments 
                WHERE training_id = ? AND status = 'waitlisted'";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $training_id);
        $stmt->execute();
        $next_pos = $stmt->get_result()->fetch_assoc()['next_pos'];
        $stmt->close();

        $sql = "INSERT INTO live_training_enrollments (
                    training_id, user_id, tenant_id, status, 
                    waitlist_position, enrolled_by
                ) VALUES (?, ?, ?, 'waitlisted', ?, ?)";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiiii", $training_id, $user_id, $this->tenant_id, $next_pos, $assigned_by);
        $stmt->execute();
        $enrollment_id = $this->conn->insert_id;
        $stmt->close();

        return $enrollment_id;
    }

    private function getWaitlistPosition($enrollment_id)
    {
        $sql = "SELECT waitlist_position FROM live_training_enrollments WHERE enrollment_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $enrollment_id);
        $stmt->execute();
        $position = $stmt->get_result()->fetch_assoc()['waitlist_position'];
        $stmt->close();
        return (int) $position;
    }

    private function createAttendanceRecords($training_id, $enrollment_id, $user_id)
    {
        $sql = "INSERT INTO live_training_attendance (session_id, user_id, enrollment_id, tenant_id, status)
                SELECT session_id, ?, ?, ?, 'pending'
                FROM live_training_sessions
                WHERE training_id = ? AND status != 'cancelled'";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiii", $user_id, $enrollment_id, $this->tenant_id, $training_id);
        $stmt->execute();
        $stmt->close();
    }
}