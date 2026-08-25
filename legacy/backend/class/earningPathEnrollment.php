<?php

/**
 * LearningPathEnrollment Class
 * Handles bulk enrollment operations for learning paths
 * Multi-tenant support with tenant_id isolation
 */

class LearningPathEnrollment
{
    private $conn;
    private $tenant_id;

    // Table names
    private $table_paths = 'learning_paths';
    private $table_enrollments = 'learning_path_enrollments';
    private $table_users = 'users';
    private $table_departments = 'departments';
    private $table_teams = 'teams';
    private $table_branches = 'branches';

    public function __construct($db)
    {
        $this->conn = $db;
    }

    public function setTenant($tenant_id)
    {
        $this->tenant_id = (int) $tenant_id;
    }

    // ==========================================
    // LOOKUP METHODS
    // ==========================================

    /**
     * Get all departments with user counts
     */
    public function getDepartments()
    {
        $sql = "SELECT 
                    d.department_id,
                    d.department_name,
                    d.department_code,
                    COUNT(DISTINCT u.user_id) as user_count
                FROM {$this->table_departments} d
                LEFT JOIN {$this->table_users} u ON u.department_id = d.department_id 
                    AND u.tenant_id = d.tenant_id 
                    AND u.is_active = 1
                WHERE d.tenant_id = ? AND d.is_active = 1
                GROUP BY d.department_id
                ORDER BY d.department_name";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $departments = [];
        while ($row = $result->fetch_assoc()) {
            $row['user_count'] = (int) $row['user_count'];
            $departments[] = $row;
        }

        return $departments;
    }

    /**
     * Get all teams with user counts
     */
    public function getTeams()
    {
        $sql = "SELECT 
                    t.team_id,
                    t.team_name,
                    t.team_code,
                    COUNT(DISTINCT u.user_id) as user_count
                FROM {$this->table_teams} t
                LEFT JOIN {$this->table_users} u ON u.team_id = t.team_id 
                    AND u.tenant_id = t.tenant_id 
                    AND u.is_active = 1
                WHERE t.tenant_id = ? AND t.is_active = 1
                GROUP BY t.team_id
                ORDER BY t.team_name";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $teams = [];
        while ($row = $result->fetch_assoc()) {
            $row['user_count'] = (int) $row['user_count'];
            $teams[] = $row;
        }

        return $teams;
    }

    /**
     * Get all branches with user counts
     */
    public function getBranches()
    {
        $sql = "SELECT 
                    b.branch_id,
                    b.branch_name,
                    b.branch_code,
                    b.city,
                    b.country,
                    COUNT(DISTINCT u.user_id) as user_count
                FROM {$this->table_branches} b
                LEFT JOIN {$this->table_users} u ON u.branch_id = b.branch_id 
                    AND u.tenant_id = b.tenant_id 
                    AND u.is_active = 1
                WHERE b.tenant_id = ? AND b.is_active = 1
                GROUP BY b.branch_id
                ORDER BY b.branch_name";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $branches = [];
        while ($row = $result->fetch_assoc()) {
            $row['user_count'] = (int) $row['user_count'];
            $branches[] = $row;
        }

        return $branches;
    }

    /**
     * Get users with optional filters
     */
    public function getUsers($search = null, $department_id = null, $team_id = null, $branch_id = null)
    {
        $sql = "SELECT 
                    u.user_id,
                    u.email,
                    u.first_name,
                    u.last_name,
                    u.profile_image,
                    u.job_title,
                    d.department_name,
                    t.team_name,
                    b.branch_name
                FROM {$this->table_users} u
                LEFT JOIN {$this->table_departments} d ON d.department_id = u.department_id AND d.tenant_id = u.tenant_id
                LEFT JOIN {$this->table_teams} t ON t.team_id = u.team_id AND t.tenant_id = u.tenant_id
                LEFT JOIN {$this->table_branches} b ON b.branch_id = u.branch_id AND b.tenant_id = u.tenant_id
                WHERE u.tenant_id = ? AND u.is_active = 1";

        $params = [$this->tenant_id];
        $types = "i";

        if ($department_id) {
            $sql .= " AND u.department_id = ?";
            $params[] = (int) $department_id;
            $types .= "i";
        }

        if ($team_id) {
            $sql .= " AND u.team_id = ?";
            $params[] = (int) $team_id;
            $types .= "i";
        }

        if ($branch_id) {
            $sql .= " AND u.branch_id = ?";
            $params[] = (int) $branch_id;
            $types .= "i";
        }

        if ($search) {
            $sql .= " AND (u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
            $searchTerm = "%" . $search . "%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $types .= "ssss";
        }

        $sql .= " ORDER BY u.first_name, u.last_name LIMIT 100";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }

        return $users;
    }

    /**
     * Get learning paths with optional filters
     */
    public function getLearningPaths($search = null, $status = 'published')
    {
        $sql = "SELECT 
                    lp.path_id,
                    lp.title,
                    lp.short_description,
                    lp.thumbnail_url,
                    lp.skill_level,
                    lp.path_type,
                    lp.status,
                    lp.estimated_duration_hours,
                    lp.total_items,
                    lp.total_courses,
                    lp.total_assessments,
                    lp.total_enrollments,
                    lp.max_enrollments,
                    lp.is_mandatory,
                    lp.is_free,
                    lp.price,
                    c.category_name,
                    (SELECT COUNT(*) FROM {$this->table_enrollments} WHERE path_id = lp.path_id AND status NOT IN ('withdrawn', 'expired')) as current_enrollments
                FROM {$this->table_paths} lp
                LEFT JOIN categories c ON c.category_id = lp.category_id
                WHERE lp.tenant_id = ? AND lp.is_active = 1";

        $params = [$this->tenant_id];
        $types = "i";

        if ($status) {
            $sql .= " AND lp.status = ?";
            $params[] = $status;
            $types .= "s";
        }

        if ($search) {
            $sql .= " AND (lp.title LIKE ? OR lp.short_description LIKE ?)";
            $searchTerm = "%" . $search . "%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $types .= "ss";
        }

        $sql .= " ORDER BY lp.title";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $paths = [];
        while ($row = $result->fetch_assoc()) {
            $row['is_full'] = $row['max_enrollments'] > 0 && $row['current_enrollments'] >= $row['max_enrollments'];
            $row['total_items'] = (int) $row['total_items'];
            $row['total_enrollments'] = (int) $row['total_enrollments'];
            $paths[] = $row;
        }

        return $paths;
    }

    /**
     * Get learning path by ID
     */
    public function getLearningPathById($path_id)
    {
        $sql = "SELECT 
                    lp.path_id,
                    lp.title,
                    lp.short_description,
                    lp.description,
                    lp.thumbnail_url,
                    lp.skill_level,
                    lp.path_type,
                    lp.status,
                    lp.estimated_duration_hours,
                    lp.total_items,
                    lp.total_courses,
                    lp.total_assessments,
                    lp.total_enrollments,
                    lp.max_enrollments,
                    lp.is_mandatory,
                    c.category_name
                FROM {$this->table_paths} lp
                LEFT JOIN categories c ON c.category_id = lp.category_id
                WHERE lp.path_id = ? AND lp.tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $path_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        return $result->fetch_assoc();
    }

    /**
     * Get users not enrolled in a learning path
     */
    public function getUnenrolledUsers($path_id, $search = null, $department_id = null, $team_id = null, $branch_id = null)
    {
        $sql = "SELECT 
                    u.user_id,
                    u.email,
                    u.first_name,
                    u.last_name,
                    u.profile_image,
                    d.department_name,
                    t.team_name,
                    b.branch_name
                FROM {$this->table_users} u
                LEFT JOIN {$this->table_departments} d ON d.department_id = u.department_id AND d.tenant_id = u.tenant_id
                LEFT JOIN {$this->table_teams} t ON t.team_id = u.team_id AND t.tenant_id = u.tenant_id
                LEFT JOIN {$this->table_branches} b ON b.branch_id = u.branch_id AND b.tenant_id = u.tenant_id
                WHERE u.tenant_id = ? 
                AND u.is_active = 1
                AND u.user_id NOT IN (
                    SELECT user_id FROM {$this->table_enrollments} 
                    WHERE path_id = ? AND status NOT IN ('withdrawn', 'expired')
                )";

        $params = [$this->tenant_id, $path_id];
        $types = "ii";

        if ($department_id) {
            $sql .= " AND u.department_id = ?";
            $params[] = (int) $department_id;
            $types .= "i";
        }

        if ($team_id) {
            $sql .= " AND u.team_id = ?";
            $params[] = (int) $team_id;
            $types .= "i";
        }

        if ($branch_id) {
            $sql .= " AND u.branch_id = ?";
            $params[] = (int) $branch_id;
            $types .= "i";
        }

        if ($search) {
            $sql .= " AND (u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
            $searchTerm = "%" . $search . "%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $types .= "ssss";
        }

        $sql .= " ORDER BY u.first_name, u.last_name LIMIT 100";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }

        return $users;
    }

    // ==========================================
    // STATISTICS METHODS
    // ==========================================

    /**
     * Get enrollment statistics for a learning path
     */
    public function getPathEnrollmentStats($path_id)
    {
        $sql = "SELECT 
                    COUNT(*) as total_enrolled,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'withdrawn' THEN 1 ELSE 0 END) as withdrawn,
                    SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended,
                    SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired,
                    SUM(CASE WHEN is_mandatory = 1 THEN 1 ELSE 0 END) as mandatory,
                    AVG(progress_percentage) as avg_progress,
                    SUM(CASE WHEN due_date IS NOT NULL AND due_date < NOW() AND status NOT IN ('completed', 'withdrawn') THEN 1 ELSE 0 END) as overdue
                FROM {$this->table_enrollments}
                WHERE path_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $path_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats = $result->fetch_assoc();

        // Ensure numeric values
        foreach ($stats as $key => $value) {
            if ($key === 'avg_progress') {
                $stats[$key] = round((float) ($value ?? 0), 1);
            } else {
                $stats[$key] = (int) ($value ?? 0);
            }
        }

        return $stats;
    }

    /**
     * Get enrollees for a learning path with pagination
     */
    public function getPathEnrollees($path_id, $status = null, $page = 1, $limit = 20)
    {
        $offset = ($page - 1) * $limit;

        // Count query
        $countSql = "SELECT COUNT(*) as total FROM {$this->table_enrollments} e
                     WHERE e.path_id = ? AND e.tenant_id = ?";

        $params = [$path_id, $this->tenant_id];
        $types = "ii";

        if ($status) {
            $countSql .= " AND e.status = ?";
            $params[] = $status;
            $types .= "s";
        }

        $stmt = $this->conn->prepare($countSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];

        // Main query
        $sql = "SELECT 
                    e.enrollment_id,
                    e.user_id,
                    e.status,
                    e.progress_percentage,
                    e.items_completed,
                    e.items_total,
                    e.enrolled_at,
                    e.started_at,
                    e.completed_at,
                    e.due_date,
                    e.is_mandatory,
                    e.total_time_spent_minutes,
                    e.last_accessed_at,
                    u.email,
                    u.first_name,
                    u.last_name,
                    u.profile_image,
                    d.department_name,
                    t.team_name,
                    b.branch_name,
                    CASE WHEN e.due_date IS NOT NULL AND e.due_date < NOW() AND e.status NOT IN ('completed', 'withdrawn') THEN 1 ELSE 0 END as is_overdue
                FROM {$this->table_enrollments} e
                JOIN {$this->table_users} u ON u.user_id = e.user_id
                LEFT JOIN {$this->table_departments} d ON d.department_id = u.department_id
                LEFT JOIN {$this->table_teams} t ON t.team_id = u.team_id
                LEFT JOIN {$this->table_branches} b ON b.branch_id = u.branch_id
                WHERE e.path_id = ? AND e.tenant_id = ?";

        $params = [$path_id, $this->tenant_id];
        $types = "ii";

        if ($status) {
            $sql .= " AND e.status = ?";
            $params[] = $status;
            $types .= "s";
        }

        $sql .= " ORDER BY e.enrolled_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= "ii";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $enrollees = [];
        while ($row = $result->fetch_assoc()) {
            $row['progress_percentage'] = (int) $row['progress_percentage'];
            $row['items_completed'] = (int) $row['items_completed'];
            $row['items_total'] = (int) $row['items_total'];
            $row['is_mandatory'] = (bool) $row['is_mandatory'];
            $row['is_overdue'] = (bool) $row['is_overdue'];
            $enrollees[] = $row;
        }

        return [
            'enrollees' => $enrollees,
            'pagination' => [
                'total' => (int) $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => ceil($total / $limit)
            ]
        ];
    }

    // ==========================================
    // ENROLLMENT ACTIONS
    // ==========================================

    /**
     * Preview enrollment before execution
     */
    public function previewEnrollment($input)
    {
        $path_id = (int) ($input['path_id'] ?? 0);
        $enrollment_type = $input['enrollment_type'] ?? '';

        if (!$path_id || !$enrollment_type) {
            return [
                'success' => false,
                'message' => 'path_id and enrollment_type are required'
            ];
        }

        // Get path info
        $path = $this->getLearningPathById($path_id);
        if (!$path) {
            return [
                'success' => false,
                'message' => 'Learning path not found'
            ];
        }

        // Get users based on enrollment type
        $users = $this->getUsersByEnrollmentType($input);

        // Check which users are already enrolled
        $userIds = array_column($users, 'user_id');
        $alreadyEnrolled = $this->getAlreadyEnrolledUsers($path_id, $userIds);
        $alreadyEnrolledIds = array_column($alreadyEnrolled, 'user_id');

        $usersToEnroll = [];
        $usersAlreadyEnrolled = [];

        foreach ($users as $user) {
            $userData = [
                'user_id' => $user['user_id'],
                'email' => $user['email'],
                'name' => $user['first_name'] . ' ' . $user['last_name'],
                'department_name' => $user['department_name'] ?? null
            ];

            if (in_array($user['user_id'], $alreadyEnrolledIds)) {
                $usersAlreadyEnrolled[] = $userData;
            } else {
                $usersToEnroll[] = $userData;
            }
        }

        return [
            'success' => true,
            'path' => [
                'path_id' => $path['path_id'],
                'title' => $path['title']
            ],
            'summary' => [
                'total_users' => count($users),
                'will_enroll' => count($usersToEnroll),
                'already_enrolled' => count($usersAlreadyEnrolled)
            ],
            'users_to_enroll' => $usersToEnroll,
            'users_already_enrolled' => $usersAlreadyEnrolled
        ];
    }

    /**
     * Enroll users in learning path
     */
    public function enrollUsers($input)
    {
        $path_id = (int) ($input['path_id'] ?? 0);
        $enrollment_type = $input['enrollment_type'] ?? '';
        $is_mandatory = (bool) ($input['is_mandatory'] ?? false);
        $due_date = $input['due_date'] ?? null;
        $notify_users = (bool) ($input['notify_users'] ?? true);
        $assigned_by = (int) ($input['assigned_by'] ?? 0);
        $notes = $input['notes'] ?? null;

        if (!$path_id || !$enrollment_type) {
            return [
                'success' => false,
                'message' => 'path_id and enrollment_type are required'
            ];
        }

        // Get path info
        $path = $this->getLearningPathById($path_id);
        if (!$path) {
            return [
                'success' => false,
                'message' => 'Learning path not found'
            ];
        }

        // Get users based on enrollment type
        $users = $this->getUsersByEnrollmentType($input);

        // Check which users are already enrolled
        $userIds = array_column($users, 'user_id');
        $alreadyEnrolled = $this->getAlreadyEnrolledUsers($path_id, $userIds);
        $alreadyEnrolledIds = array_column($alreadyEnrolled, 'user_id');

        $enrolled = [];
        $skipped = [];
        $failed = [];

        // Get path items count
        $itemsTotal = (int) ($path['total_items'] ?? 0);

        foreach ($users as $user) {
            $userData = [
                'user_id' => $user['user_id'],
                'email' => $user['email'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'name' => $user['first_name'] . ' ' . $user['last_name']
            ];

            // Skip if already enrolled
            if (in_array($user['user_id'], $alreadyEnrolledIds)) {
                $userData['reason'] = 'Already enrolled';
                $skipped[] = $userData;
                continue;
            }

            // Try to enroll
            try {
                $enrollmentId = $this->createEnrollment(
                    $path_id,
                    $user['user_id'],
                    $is_mandatory,
                    $due_date,
                    $assigned_by,
                    $notes,
                    $itemsTotal
                );

                $userData['enrollment_id'] = $enrollmentId;
                $enrolled[] = $userData;

                // Send notification if enabled
                if ($notify_users) {
                    $this->sendEnrollmentNotification($user, $path);
                }
            } catch (Exception $e) {
                $userData['reason'] = $e->getMessage();
                $failed[] = $userData;
            }
        }

        // Update path enrollment count
        $this->updatePathEnrollmentCount($path_id);

        return [
            'success' => true,
            'message' => sprintf(
                'Successfully enrolled %d user(s) in "%s"',
                count($enrolled),
                $path['title']
            ),
            'summary' => [
                'total_users' => count($users),
                'enrolled_count' => count($enrolled),
                'skipped_count' => count($skipped),
                'failed_count' => count($failed)
            ],
            'enrolled' => $enrolled,
            'skipped' => $skipped,
            'failed' => $failed,
            'path' => [
                'path_id' => $path['path_id'],
                'title' => $path['title']
            ]
        ];
    }

    /**
     * Unenroll (withdraw) users from learning path
     */
    public function unenrollUsers($input)
    {
        $path_id = (int) ($input['path_id'] ?? 0);
        $user_ids = $input['user_ids'] ?? [];
        $reason = $input['reason'] ?? 'Admin removed';
        $removed_by = (int) ($input['removed_by'] ?? 0);

        if (!$path_id || empty($user_ids)) {
            return [
                'success' => false,
                'message' => 'path_id and user_ids are required'
            ];
        }

        $placeholders = str_repeat('?,', count($user_ids) - 1) . '?';
        $sql = "UPDATE {$this->table_enrollments} 
                SET status = 'withdrawn', 
                    withdrawn_at = NOW(), 
                    withdrawn_by = ?,
                    admin_notes = CONCAT(IFNULL(admin_notes, ''), '\nWithdrawn: ', ?)
                WHERE path_id = ? AND user_id IN ($placeholders) AND tenant_id = ?";

        $types = "isi" . str_repeat("i", count($user_ids)) . "i";
        $params = array_merge([$removed_by, $reason, $path_id], $user_ids, [$this->tenant_id]);

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        $affected = $stmt->affected_rows;
        $this->updatePathEnrollmentCount($path_id);

        return [
            'success' => true,
            'message' => "$affected user(s) withdrawn from learning path",
            'affected' => $affected
        ];
    }

    /**
     * Update due date for enrollments
     */
    public function updateDueDate($path_id, $user_ids, $due_date)
    {
        if (empty($user_ids)) {
            return [
                'success' => false,
                'message' => 'user_ids is required'
            ];
        }

        $placeholders = str_repeat('?,', count($user_ids) - 1) . '?';
        $sql = "UPDATE {$this->table_enrollments} 
                SET due_date = ?
                WHERE path_id = ? AND user_id IN ($placeholders) AND tenant_id = ?";

        $types = "si" . str_repeat("i", count($user_ids)) . "i";
        $params = array_merge([$due_date, $path_id], $user_ids, [$this->tenant_id]);

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        return [
            'success' => true,
            'message' => 'Due date updated for ' . $stmt->affected_rows . ' enrollment(s)',
            'affected' => $stmt->affected_rows
        ];
    }

    /**
     * Set mandatory status for enrollments
     */
    public function setMandatory($path_id, $user_ids, $is_mandatory)
    {
        if (empty($user_ids)) {
            return [
                'success' => false,
                'message' => 'user_ids is required'
            ];
        }

        $placeholders = str_repeat('?,', count($user_ids) - 1) . '?';
        $sql = "UPDATE {$this->table_enrollments} 
                SET is_mandatory = ?
                WHERE path_id = ? AND user_id IN ($placeholders) AND tenant_id = ?";

        $mandatory = $is_mandatory ? 1 : 0;
        $types = "ii" . str_repeat("i", count($user_ids)) . "i";
        $params = array_merge([$mandatory, $path_id], $user_ids, [$this->tenant_id]);

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        return [
            'success' => true,
            'message' => 'Mandatory status updated for ' . $stmt->affected_rows . ' enrollment(s)',
            'affected' => $stmt->affected_rows
        ];
    }

    /**
     * Suspend enrollments
     */
    public function suspendEnrollments($path_id, $user_ids, $reason = null)
    {
        if (empty($user_ids)) {
            return [
                'success' => false,
                'message' => 'user_ids is required'
            ];
        }

        $placeholders = str_repeat('?,', count($user_ids) - 1) . '?';
        $sql = "UPDATE {$this->table_enrollments} 
                SET status = 'suspended',
                    suspended_at = NOW(),
                    admin_notes = CONCAT(IFNULL(admin_notes, ''), '\nSuspended: ', IFNULL(?, 'No reason provided'))
                WHERE path_id = ? AND user_id IN ($placeholders) AND tenant_id = ? AND status = 'active'";

        $types = "si" . str_repeat("i", count($user_ids)) . "i";
        $params = array_merge([$reason, $path_id], $user_ids, [$this->tenant_id]);

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        return [
            'success' => true,
            'message' => $stmt->affected_rows . ' enrollment(s) suspended',
            'affected' => $stmt->affected_rows
        ];
    }

    /**
     * Resume suspended enrollments
     */
    public function resumeEnrollments($path_id, $user_ids)
    {
        if (empty($user_ids)) {
            return [
                'success' => false,
                'message' => 'user_ids is required'
            ];
        }

        $placeholders = str_repeat('?,', count($user_ids) - 1) . '?';
        $sql = "UPDATE {$this->table_enrollments} 
                SET status = 'active',
                    suspended_at = NULL,
                    admin_notes = CONCAT(IFNULL(admin_notes, ''), '\nResumed: ', NOW())
                WHERE path_id = ? AND user_id IN ($placeholders) AND tenant_id = ? AND status = 'suspended'";

        $types = "i" . str_repeat("i", count($user_ids)) . "i";
        $params = array_merge([$path_id], $user_ids, [$this->tenant_id]);

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        return [
            'success' => true,
            'message' => $stmt->affected_rows . ' enrollment(s) resumed',
            'affected' => $stmt->affected_rows
        ];
    }

    /**
     * Send reminder to enrollees
     */
    public function sendReminder($path_id, $user_ids, $message = null, $sent_by = null)
    {
        if (empty($user_ids)) {
            return [
                'success' => false,
                'message' => 'user_ids is required'
            ];
        }

        $path = $this->getLearningPathById($path_id);
        if (!$path) {
            return [
                'success' => false,
                'message' => 'Learning path not found'
            ];
        }

        $sent = 0;
        $defaultMessage = "This is a reminder to continue your progress in the learning path: " . $path['title'];

        foreach ($user_ids as $user_id) {
            // Create notification record
            $sql = "INSERT INTO notifications (
                        user_id, tenant_id, type, title, message, 
                        entity_type, entity_id, created_at
                    ) VALUES (?, ?, 'reminder', ?, ?, 'learning_path', ?, NOW())";

            $title = "Learning Path Reminder";
            $body = $message ?: $defaultMessage;

            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param(
                "iissi",
                $user_id,
                $this->tenant_id,
                $title,
                $body,
                $path_id
            );

            if ($stmt->execute()) {
                $sent++;
            }
        }

        return [
            'success' => true,
            'message' => "Reminder sent to $sent user(s)",
            'sent' => $sent
        ];
    }

    // ==========================================
    // HELPER METHODS
    // ==========================================

    private function getUsersByEnrollmentType($input)
    {
        $type = $input['enrollment_type'];

        switch ($type) {
            case 'department':
                return $this->getUsersByDepartment((int) $input['department_id']);
            case 'team':
                return $this->getUsersByTeam((int) $input['team_id']);
            case 'branch':
                return $this->getUsersByBranch((int) $input['branch_id']);
            case 'users':
                return $this->getUsersByIds($input['user_ids']);
            default:
                throw new Exception("Invalid enrollment type: $type");
        }
    }

    private function getUsersByDepartment($department_id)
    {
        $sql = "SELECT u.user_id, u.email, u.first_name, u.last_name, d.department_name
                FROM {$this->table_users} u
                LEFT JOIN {$this->table_departments} d ON d.department_id = u.department_id
                WHERE u.tenant_id = ? AND u.department_id = ? AND u.is_active = 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->tenant_id, $department_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function getUsersByTeam($team_id)
    {
        $sql = "SELECT u.user_id, u.email, u.first_name, u.last_name, t.team_name
                FROM {$this->table_users} u
                LEFT JOIN {$this->table_teams} t ON t.team_id = u.team_id
                WHERE u.tenant_id = ? AND u.team_id = ? AND u.is_active = 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->tenant_id, $team_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function getUsersByBranch($branch_id)
    {
        $sql = "SELECT u.user_id, u.email, u.first_name, u.last_name, b.branch_name
                FROM {$this->table_users} u
                LEFT JOIN {$this->table_branches} b ON b.branch_id = u.branch_id
                WHERE u.tenant_id = ? AND u.branch_id = ? AND u.is_active = 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->tenant_id, $branch_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function getUsersByIds($user_ids)
    {
        if (empty($user_ids)) {
            return [];
        }

        $placeholders = str_repeat('?,', count($user_ids) - 1) . '?';
        $sql = "SELECT u.user_id, u.email, u.first_name, u.last_name, d.department_name
                FROM {$this->table_users} u
                LEFT JOIN {$this->table_departments} d ON d.department_id = u.department_id
                WHERE u.tenant_id = ? AND u.user_id IN ($placeholders) AND u.is_active = 1";

        $types = "i" . str_repeat("i", count($user_ids));
        $params = array_merge([$this->tenant_id], $user_ids);

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function getAlreadyEnrolledUsers($path_id, $user_ids)
    {
        if (empty($user_ids)) {
            return [];
        }

        $placeholders = str_repeat('?,', count($user_ids) - 1) . '?';
        $sql = "SELECT user_id FROM {$this->table_enrollments} 
                WHERE path_id = ? AND user_id IN ($placeholders) 
                AND status NOT IN ('withdrawn', 'expired')";

        $types = "i" . str_repeat("i", count($user_ids));
        $params = array_merge([$path_id], $user_ids);

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function createEnrollment($path_id, $user_id, $is_mandatory, $due_date, $assigned_by, $notes, $items_total)
    {
        $sql = "INSERT INTO {$this->table_enrollments} (
                    path_id, user_id, tenant_id, status, enrollment_type,
                    enrolled_by, enrolled_at, is_mandatory, due_date,
                    items_total, progress_percentage, admin_notes, is_active, created_at
                ) VALUES (?, ?, ?, 'active', 'admin', ?, NOW(), ?, ?, ?, 0, ?, 1, NOW())";

        $stmt = $this->conn->prepare($sql);
        $mandatory = $is_mandatory ? 1 : 0;
        $stmt->bind_param(
            "iiiissis",
            $path_id,
            $user_id,
            $this->tenant_id,
            $assigned_by,
            $mandatory,
            $due_date,
            $items_total,
            $notes
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to create enrollment: " . $stmt->error);
        }

        return $this->conn->insert_id;
    }

    private function updatePathEnrollmentCount($path_id)
    {
        $sql = "UPDATE {$this->table_paths} SET 
                    total_enrollments = (
                        SELECT COUNT(*) FROM {$this->table_enrollments} 
                        WHERE path_id = ? AND status NOT IN ('withdrawn', 'expired')
                    )
                WHERE path_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $path_id, $path_id);
        $stmt->execute();
    }

    private function sendEnrollmentNotification($user, $path)
    {
        $sql = "INSERT INTO notifications (
                    user_id, tenant_id, type, title, message, 
                    entity_type, entity_id, created_at
                ) VALUES (?, ?, 'enrollment', ?, ?, 'learning_path', ?, NOW())";

        $title = "You've been enrolled in a learning path";
        $message = "You have been enrolled in the learning path: " . $path['title'];

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param(
            "iissi",
            $user['user_id'],
            $this->tenant_id,
            $title,
            $message,
            $path['path_id']
        );
        $stmt->execute();
    }
}