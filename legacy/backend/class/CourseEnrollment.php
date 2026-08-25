<?php

/**
 * Course Enrollment Manager
 * Handles bulk enrollment by department, team, branch, or individual users
 * For Multi-Tenant LMS Admin
 *
 * Adapted for schema:
 * - course_enrollments table (not enrollments)
 * - Status enum: not_started, in_progress, completed, dropped
 * - due_date column (not deadline)
 * - Teams not linked to departments
 */
require_once 'EmailHandler.php';
class CourseEnrollment{
    private EmailHandler $emailHandler;

    private mysqli $conn;
    private int $tenant_id;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
        $this->emailHandler = new EmailHandler();

    }




    public function setTenant(int $tenant_id): void
    {
        $this->tenant_id = $tenant_id;
    }

    // ==========================================
    // ENROLLMENT METHODS
    // ==========================================

    /**
     * Enroll users in a course
     * Supports: department, team, branch, single user, multiple users
     *
     * @param array $params {
     *   course_id: int (required)
     *   enrollment_type: string (department|team|branch|user|users)
     *   department_id: int (for department enrollment)
     *   team_id: int (for team enrollment)
     *   branch_id: int (for branch enrollment)
     *   user_id: int (for single user enrollment)
     *   user_ids: array (for multiple users enrollment)
     *   is_mandatory: bool (default false)
     *   due_date: string (Y-m-d format, optional)
     *   assigned_by: int (admin user_id)
     *   notify_users: bool (default true)
     * }
     */
    public function enrollUsers(array $params): array
    {
        $course_id = (int) ($params['course_id'] ?? 0);
        $enrollment_type = $params['enrollment_type'] ?? '';
        $is_mandatory = (bool) ($params['is_mandatory'] ?? false);
        $due_date = $params['due_date'] ?? null;
        $assigned_by = (int) ($params['assigned_by'] ?? 0);
        $notify_users = (bool) ($params['notify_users'] ?? true);

        if (!$course_id) {
            return ['success' => false, 'message' => 'course_id is required'];
        }

        // Validate course exists and belongs to tenant
        $course = $this->getCourseDetails($course_id);
        if (!$course) {
            return ['success' => false, 'message' => 'Course not found'];
        }

        // Get users to enroll based on enrollment type
        $users = [];
        switch ($enrollment_type) {
            case 'department':
                $department_id = (int) ($params['department_id'] ?? 0);
                if (!$department_id) {
                    return ['success' => false, 'message' => 'department_id is required'];
                }
                $users = $this->getUsersByDepartment($department_id);
                break;

            case 'team':
                $team_id = (int) ($params['team_id'] ?? 0);
                if (!$team_id) {
                    return ['success' => false, 'message' => 'team_id is required'];
                }
                $users = $this->getUsersByTeam($team_id);
                break;

            case 'branch':
                $branch_id = (int) ($params['branch_id'] ?? 0);
                if (!$branch_id) {
                    return ['success' => false, 'message' => 'branch_id is required'];
                }
                $users = $this->getUsersByBranch($branch_id);
                break;

            case 'user':
                $user_id = (int) ($params['user_id'] ?? 0);
                if (!$user_id) {
                    return ['success' => false, 'message' => 'user_id is required'];
                }
                $user = $this->getUserById($user_id);
                if ($user) {
                    $users = [$user];
                }
                break;

            case 'users':
                $user_ids = $params['user_ids'] ?? [];
                if (empty($user_ids) || !is_array($user_ids)) {
                    return ['success' => false, 'message' => 'user_ids array is required'];
                }
                $users = $this->getUsersByIds($user_ids);
                break;

            default:
                return ['success' => false, 'message' => 'Invalid enrollment_type. Use: department, team, branch, user, users'];
        }

        if (empty($users)) {
            return ['success' => false, 'message' => 'No eligible users found for enrollment'];
        }

        // Perform bulk enrollment
        return $this->bulkEnroll($users, $course, [
            'is_mandatory' => $is_mandatory,
            'due_date' => $due_date,
            'assigned_by' => $assigned_by,
            'notify_users' => $notify_users,
            'enrollment_type' => $enrollment_type
        ]);
    }

    /**
     * Perform bulk enrollment of users
     */
    private function bulkEnroll(array $users, array $course, array $options): array
    {
        $enrolled = [];
        $skipped = [];
        $failed = [];

        $course_id = $course['course_id'];
        $is_mandatory = $options['is_mandatory'] ?? false;
        $due_date = $options['due_date'] ?? null;
        $assigned_by = $options['assigned_by'] ?? null;
        $notify_users = $options['notify_users'] ?? true;
        $enrollment_type = $options['enrollment_type'] ?? 'Direct Assignment';

        // Map enrollment_type to enrollment_source
        $source_map = [
            'department' => 'Department Assignment',
            'team' => 'Team Assignment',
            'branch' => 'Branch Assignment',
            'user' => 'Direct Assignment',
            'users' => 'Bulk Assignment'
        ];
        $enrollment_source = $source_map[$enrollment_type] ?? 'Direct Assignment';

        // Prepare enrollment statement
        $stmt = $this->conn->prepare("
            INSERT INTO course_enrollments (
                user_id, course_id, tenant_id, status, progress_percentage,
                enrolled_at, is_mandatory, due_date, assigned_by, enrollment_source, is_active
            ) VALUES (?, ?, ?, 'not_started', 0.00, NOW(), ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE 
                is_mandatory = IF(VALUES(is_mandatory) = 1, 1, is_mandatory),
                due_date = COALESCE(VALUES(due_date), due_date),
                is_active = 1,
                status = IF(status = 'dropped', 'not_started', status),
                updated_at = NOW()
        ");

        foreach ($users as $user) {
            $user_id = $user['id'];

            // Check if already actively enrolled
            if ($this->isAlreadyEnrolled($user_id, $course_id)) {
                $skipped[] = [
                    'user_id' => $user_id,
                    'email' => $user['email'],
                    'name' => $user['first_name'] . ' ' . $user['last_name'],
                    'reason' => 'Already enrolled'
                ];
                continue;
            }

            try {
                $is_mandatory_int = $is_mandatory ? 1 : 0;
                $stmt->bind_param(
                    "iiiisis",
                    $user_id,
                    $course_id,
                    $this->tenant_id,
                    $is_mandatory_int,
                    $due_date,
                    $assigned_by,
                    $enrollment_source
                );

                if ($stmt->execute()) {
                    $enrollment_id = $stmt->insert_id ?: $this->getEnrollmentId($user_id, $course_id);

                    $enrolled[] = [
                        'user_id' => $user_id,
                        'email' => $user['email'],
                        'first_name' => $user['first_name'],
                        'last_name' => $user['last_name'],
                        'enrollment_id' => $enrollment_id
                    ];

                    // ============================================
                    // EMAIL NOTIFICATION HOOK
                    // Call your email notification function here
                    // ============================================
                    if ($notify_users) {

                        /*
                         * TODO: Call your email notification function here
                         *
                         * Available data for email:
                         * - $user['first_name']  : User's first name
                         * - $user['email']       : User's email address
                         * - $course['title']     : Course title
                         * - $due_date            : Enrollment due date (null if not set) - for courses with end_date
                         * - $is_mandatory        : Boolean - true for mandatory courses
                         *
                         * Example:*/
/*                          $this->sendEnrollmentNotification([
                              'first_name' => $user['first_name'],
                              'email' => $user['email'],
                              'course_title' => $course['title'],
                              'due_date' => $due_date,
                             'is_mandatory' => $is_mandatory
                         ]);*/

                        $this->emailHandler->course_enrollment(
                            $user['first_name'],
                            $course['title'],
                            $due_date,
                            $is_mandatory,
                            'support@centrallms.com',
                            $user['email']
                        );

                    }

                    // Log the enrollment
                    $this->logEnrollment($user_id, $course_id, $assigned_by, $enrollment_source);
                } else {
                    $failed[] = [
                        'user_id' => $user_id,
                        'email' => $user['email'],
                        'name' => $user['first_name'] . ' ' . $user['last_name'],
                        'reason' => $stmt->error
                    ];
                }
            } catch (Exception $e) {
                $failed[] = [
                    'user_id' => $user_id,
                    'email' => $user['email'],
                    'name' => $user['first_name'] . ' ' . $user['last_name'],
                    'reason' => $e->getMessage()
                ];
            }
        }

        $stmt->close();

        return [
            'success' => true,
            'message' => sprintf(
                'Enrollment complete: %d enrolled, %d skipped, %d failed',
                count($enrolled),
                count($skipped),
                count($failed)
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
            'course' => [
                'course_id' => $course['course_id'],
                'title' => $course['title']
            ]
        ];
    }

    /**
     * Unenroll users from a course (set status to dropped and is_active to 0)
     */
    public function unenrollUsers(array $params): array
    {
        $course_id = (int) ($params['course_id'] ?? 0);
        $user_ids = $params['user_ids'] ?? [];
        $removed_by = (int) ($params['removed_by'] ?? 0);
        $reason = $params['reason'] ?? 'Admin removed';

        if (!$course_id) {
            return ['success' => false, 'message' => 'course_id is required'];
        }

        if (empty($user_ids)) {
            return ['success' => false, 'message' => 'user_ids array is required'];
        }

        $unenrolled = [];
        $failed = [];

        $stmt = $this->conn->prepare("
            UPDATE course_enrollments 
            SET status = 'dropped', 
                is_active = 0,
                updated_at = NOW()
            WHERE user_id = ? AND course_id = ? AND tenant_id = ?
        ");

        foreach ($user_ids as $user_id) {
            $user_id = (int) $user_id;

            $stmt->bind_param("iii", $user_id, $course_id, $this->tenant_id);

            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $unenrolled[] = $user_id;
                $this->logUnenrollment($user_id, $course_id, $removed_by, $reason);
            } else {
                $failed[] = $user_id;
            }
        }

        $stmt->close();

        return [
            'success' => true,
            'message' => sprintf('%d users unenrolled, %d failed', count($unenrolled), count($failed)),
            'unenrolled' => $unenrolled,
            'failed' => $failed
        ];
    }

    // ==========================================
    // USER RETRIEVAL METHODS
    // ==========================================

    /**
     * Get users by department
     */
    private function getUsersByDepartment(int $department_id): array
    {
        $stmt = $this->conn->prepare("
            SELECT u.id, u.email, u.first_name, u.last_name, u.department, u.team, u.branch
            FROM users u
            WHERE u.tenant_id = ? 
              AND u.department = ? 
              AND u.is_active = 1
            ORDER BY u.last_name, u.first_name
        ");
        $stmt->bind_param("ii", $this->tenant_id, $department_id);
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
     * Get users by team
     */
    private function getUsersByTeam(int $team_id): array
    {
        $stmt = $this->conn->prepare("
            SELECT u.id, u.email, u.first_name, u.last_name, u.department, u.team, u.branch
            FROM users u
            WHERE u.tenant_id = ? 
              AND u.team = ? 
              AND u.is_active = 1
            ORDER BY u.last_name, u.first_name
        ");
        $stmt->bind_param("ii", $this->tenant_id, $team_id);
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
     * Get users by branch
     */
    private function getUsersByBranch(int $branch_id): array
    {
        $stmt = $this->conn->prepare("
            SELECT u.id, u.email, u.first_name, u.last_name, u.department, u.team, u.branch
            FROM users u
            WHERE u.tenant_id = ? 
              AND u.branch = ? 
              AND u.is_active = 1
            ORDER BY u.last_name, u.first_name
        ");
        $stmt->bind_param("ii", $this->tenant_id, $branch_id);
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
     * Get single user by ID
     */
    private function getUserById(int $user_id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT id, email, first_name, last_name, department, team, branch
            FROM users
            WHERE id = ? AND tenant_id = ? AND is_active = 1
        ");
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        return $user;
    }

    /**
     * Get multiple users by IDs
     */
    private function getUsersByIds(array $user_ids): array
    {
        if (empty($user_ids)) {
            return [];
        }

        // Sanitize IDs
        $user_ids = array_map('intval', $user_ids);
        $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
        $types = str_repeat('i', count($user_ids)) . 'i';

        $query = "
            SELECT id, email, first_name, last_name, department, team, branch
            FROM users
            WHERE id IN ($placeholders) AND tenant_id = ? AND is_active = 1
            ORDER BY last_name, first_name
        ";

        $stmt = $this->conn->prepare($query);
        $params = array_merge($user_ids, [$this->tenant_id]);
        $stmt->bind_param($types, ...$params);
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
    // LOOKUP METHODS (For UI dropdowns)
    // ==========================================

    /**
     * Get all departments for tenant
     */
    public function getDepartments(): array
    {
        $stmt = $this->conn->prepare("
            SELECT d.department_id, d.department_name, d.department_code,
                   (SELECT COUNT(*) FROM users u WHERE u.department = d.department_id AND u.is_active = 1 AND u.tenant_id = ?) as user_count
            FROM departments d
            WHERE d.tenant_id = ? AND d.is_active = 1
            ORDER BY d.department_name
        ");
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
     * Get all teams for tenant
     * Note: Teams are not linked to departments in this schema
     */
    public function getTeams(): array
    {
        $stmt = $this->conn->prepare("
            SELECT t.team_id, t.team_name, t.team_code,
                   (SELECT COUNT(*) FROM users u WHERE u.team = t.team_id AND u.is_active = 1 AND u.tenant_id = ?) as user_count
            FROM teams t
            WHERE t.tenant_id = ? AND t.is_active = 1
            ORDER BY t.team_name
        ");
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
     * Get all branches for tenant
     */
    public function getBranches(): array
    {
        $stmt = $this->conn->prepare("
            SELECT b.branch_id, b.branch_name, b.branch_code, b.city, b.country,
                   (SELECT COUNT(*) FROM users u WHERE u.branch = b.branch_id AND u.is_active = 1 AND u.tenant_id = ?) as user_count
            FROM branches b
            WHERE b.tenant_id = ? AND b.is_active = 1
            ORDER BY b.branch_name
        ");
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
     * Get all users for tenant (for multi-select)
     */
    public function getUsers(?string $search = null, ?int $department_id = null, ?int $team_id = null, ?int $branch_id = null): array
    {
        $query = "
            SELECT u.id, u.email, u.first_name, u.last_name, 
                   d.department_name, t.team_name, b.branch_name
            FROM users u
            LEFT JOIN departments d ON u.department = d.department_id
            LEFT JOIN teams t ON u.team = t.team_id
            LEFT JOIN branches b ON u.branch = b.branch_id
            WHERE u.tenant_id = ? 
              AND u.is_active = 1";

        $params = [$this->tenant_id];
        $types = "i";

        if ($search) {
            $searchTerm = "%$search%";
            $query .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $types .= "sss";
        }

        if ($department_id) {
            $query .= " AND u.department = ?";
            $params[] = $department_id;
            $types .= "i";
        }

        if ($team_id) {
            $query .= " AND u.team = ?";
            $params[] = $team_id;
            $types .= "i";
        }

        if ($branch_id) {
            $query .= " AND u.branch = ?";
            $params[] = $branch_id;
            $types .= "i";
        }

        $query .= " ORDER BY u.last_name, u.first_name LIMIT 100";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
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
     * Get courses available for enrollment
     */
    public function getCourses(?string $search = null, ?string $status = 'published'): array
    {
        $query = "
            SELECT c.course_id, c.title, c.description,
                   c.duration_minutes, c.level, c.status,
                   c.start_date, c.end_date, c.enrollment_limit,
                   cat.name as category_name,
                   (SELECT COUNT(*) FROM course_enrollments e WHERE e.course_id = c.course_id AND e.is_active = 1 AND e.status != 'dropped') as current_enrollments
            FROM courses c
            LEFT JOIN categories cat ON c.category_id = cat.category_id
            WHERE c.tenant_id = ? AND c.is_active = 1
        ";

        $params = [$this->tenant_id];
        $types = "i";

        if ($status) {
            $query .= " AND c.status = ?";
            $params[] = $status;
            $types .= "s";
        }

        if ($search) {
            $searchTerm = "%$search%";
            $query .= " AND (c.title LIKE ? OR c.description LIKE ?)";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $types .= "ss";
        }

        $query .= " ORDER BY c.title";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $courses = [];
        while ($row = $result->fetch_assoc()) {
            $row['is_full'] = $row['enrollment_limit'] > 0 && $row['current_enrollments'] >= $row['enrollment_limit'];
            $courses[] = $row;
        }
        $stmt->close();

        return $courses;
    }

    // ==========================================
    // ENROLLMENT STATUS METHODS
    // ==========================================

    /**
     * Get course enrollment statistics
     */
    public function getCourseEnrollmentStats(int $course_id): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                COUNT(*) as total_enrolled,
                SUM(CASE WHEN status = 'not_started' THEN 1 ELSE 0 END) as not_started,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'dropped' THEN 1 ELSE 0 END) as dropped,
                SUM(CASE WHEN is_mandatory = 1 THEN 1 ELSE 0 END) as mandatory,
                AVG(progress_percentage) as avg_progress
            FROM course_enrollments
            WHERE course_id = ? AND tenant_id = ? AND is_active = 1
        ");
        $stmt->bind_param("ii", $course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats = $result->fetch_assoc();
        $stmt->close();

        return $stats;
    }

    /**
     * Get course enrollees list
     */
    public function getCourseEnrollees(int $course_id, ?string $status = null, int $page = 1, int $limit = 20): array
    {
        $offset = ($page - 1) * $limit;

        $query = "
            SELECT e.enrollment_id, e.user_id, e.status, e.progress_percentage, 
                   e.enrolled_at, e.completed_at, e.is_mandatory, e.due_date,
                   u.email, u.first_name, u.last_name,
                   d.department_name, t.team_name, b.branch_name
            FROM course_enrollments e
            JOIN users u ON e.user_id = u.id
            LEFT JOIN departments d ON u.department = d.department_id
            LEFT JOIN teams t ON u.team = t.team_id
            LEFT JOIN branches b ON u.branch = b.branch_id
            WHERE e.course_id = ? AND e.tenant_id = ? AND e.is_active = 1
        ";

        $params = [$course_id, $this->tenant_id];
        $types = "ii";

        if ($status) {
            $query .= " AND e.status = ?";
            $params[] = $status;
            $types .= "s";
        }

        $query .= " ORDER BY e.enrolled_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= "ii";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $enrollees = [];
        while ($row = $result->fetch_assoc()) {
            $row['is_mandatory'] = (bool) $row['is_mandatory'];
            $enrollees[] = $row;
        }
        $stmt->close();

        // Get total count
        $countQuery = "SELECT COUNT(*) as total FROM course_enrollments WHERE course_id = ? AND tenant_id = ? AND is_active = 1";
        $countParams = [$course_id, $this->tenant_id];
        $countTypes = "ii";

        if ($status) {
            $countQuery .= " AND status = ?";
            $countParams[] = $status;
            $countTypes .= "s";
        }

        $countStmt = $this->conn->prepare($countQuery);
        $countStmt->bind_param($countTypes, ...$countParams);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $total = $countResult->fetch_assoc()['total'];
        $countStmt->close();

        return [
            'enrollees' => $enrollees,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => ceil($total / $limit)
            ]
        ];
    }

    /**
     * Get users not enrolled in a course (for enrollment UI)
     */
    public function getUnenrolledUsers(int $course_id, ?string $search = null, int $limit = 50): array
    {
        $query = "
            SELECT u.id, u.email, u.first_name, u.last_name,
                   d.department_name, t.team_name, b.branch_name
            FROM users u
            LEFT JOIN departments d ON u.department = d.department_id
            LEFT JOIN teams t ON u.team = t.team_id
            LEFT JOIN branches b ON u.branch = b.branch_id
            WHERE u.tenant_id = ? 
              AND u.is_active = 1
              AND u.role NOT IN ('admin', 'superadmin')
              AND u.id NOT IN (
                  SELECT e.user_id FROM course_enrollments e 
                  WHERE e.course_id = ? AND e.is_active = 1 AND e.status != 'dropped'
              )
        ";

        $params = [$this->tenant_id, $course_id];
        $types = "ii";

        if ($search) {
            $searchTerm = "%$search%";
            $query .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $types .= "sss";
        }

        $query .= " ORDER BY u.last_name, u.first_name LIMIT ?";
        $params[] = $limit;
        $types .= "i";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
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
    // HELPER METHODS
    // ==========================================

    /**
     * Get course details
     */
    private function getCourseDetails(int $course_id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT course_id, title, description, status, start_date, end_date, enrollment_limit
            FROM courses
            WHERE course_id = ? AND tenant_id = ? AND is_active = 1
        ");
        $stmt->bind_param("ii", $course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $course = $result->fetch_assoc();
        $stmt->close();

        return $course;
    }

    /**
     * Check if user is already actively enrolled
     */
    private function isAlreadyEnrolled(int $user_id, int $course_id): bool
    {
        $stmt = $this->conn->prepare("
            SELECT enrollment_id FROM course_enrollments 
            WHERE user_id = ? AND course_id = ? AND tenant_id = ? AND is_active = 1 AND status != 'dropped'
        ");
        $stmt->bind_param("iii", $user_id, $course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result->num_rows > 0;
        $stmt->close();

        return $exists;
    }

    /**
     * Get enrollment ID for a user/course combination
     */
    private function getEnrollmentId(int $user_id, int $course_id): ?int
    {
        $stmt = $this->conn->prepare("
            SELECT enrollment_id FROM course_enrollments 
            WHERE user_id = ? AND course_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("iii", $user_id, $course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row ? $row['enrollment_id'] : null;
    }

    /**
     * Log enrollment action
     */
    private function logEnrollment(int $user_id, int $course_id, ?int $assigned_by, string $source): void
    {
        // Check if enrollment_logs table exists before logging
        $result = $this->conn->query("SHOW TABLES LIKE 'enrollment_logs'");
        if ($result->num_rows === 0) {
            return; // Table doesn't exist, skip logging
        }

        $stmt = $this->conn->prepare("
            INSERT INTO enrollment_logs (
                user_id, course_id, tenant_id, action, assigned_by, source, created_at
            ) VALUES (?, ?, ?, 'enrolled', ?, ?, NOW())
        ");
        $stmt->bind_param("iiiis", $user_id, $course_id, $this->tenant_id, $assigned_by, $source);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Log unenrollment action
     */
    private function logUnenrollment(int $user_id, int $course_id, ?int $removed_by, string $reason): void
    {
        // Check if enrollment_logs table exists before logging
        $result = $this->conn->query("SHOW TABLES LIKE 'enrollment_logs'");
        if ($result->num_rows === 0) {
            return; // Table doesn't exist, skip logging
        }

        $stmt = $this->conn->prepare("
            INSERT INTO enrollment_logs (
                user_id, course_id, tenant_id, action, assigned_by, source, created_at
            ) VALUES (?, ?, ?, 'unenrolled', ?, ?, NOW())
        ");
        $stmt->bind_param("iiiis", $user_id, $course_id, $this->tenant_id, $removed_by, $reason);
        $stmt->execute();
        $stmt->close();
    }

    // ==========================================
    // BULK OPERATIONS
    // ==========================================

    /**
     * Preview enrollment - shows who would be enrolled without actually enrolling
     */
    public function previewEnrollment(array $params): array
    {
        $course_id = (int) ($params['course_id'] ?? 0);
        $enrollment_type = $params['enrollment_type'] ?? '';

        if (!$course_id) {
            return ['success' => false, 'message' => 'course_id is required'];
        }

        $course = $this->getCourseDetails($course_id);
        if (!$course) {
            return ['success' => false, 'message' => 'Course not found'];
        }

        // Get users based on enrollment type
        $users = [];
        switch ($enrollment_type) {
            case 'department':
                $users = $this->getUsersByDepartment((int) ($params['department_id'] ?? 0));
                break;
            case 'team':
                $users = $this->getUsersByTeam((int) ($params['team_id'] ?? 0));
                break;
            case 'branch':
                $users = $this->getUsersByBranch((int) ($params['branch_id'] ?? 0));
                break;
            case 'user':
                $user = $this->getUserById((int) ($params['user_id'] ?? 0));
                if ($user) $users = [$user];
                break;
            case 'users':
                $users = $this->getUsersByIds($params['user_ids'] ?? []);
                break;
        }

        // Check existing enrollments
        $will_enroll = [];
        $already_enrolled = [];

        foreach ($users as $user) {
            if ($this->isAlreadyEnrolled($user['id'], $course_id)) {
                $already_enrolled[] = [
                    'user_id' => $user['id'],
                    'email' => $user['email'],
                    'name' => $user['first_name'] . ' ' . $user['last_name']
                ];
            } else {
                $will_enroll[] = [
                    'user_id' => $user['id'],
                    'email' => $user['email'],
                    'name' => $user['first_name'] . ' ' . $user['last_name']
                ];
            }
        }

        return [
            'success' => true,
            'course' => [
                'course_id' => $course['course_id'],
                'title' => $course['title']
            ],
            'summary' => [
                'total_users' => count($users),
                'will_enroll' => count($will_enroll),
                'already_enrolled' => count($already_enrolled)
            ],
            'users_to_enroll' => $will_enroll,
            'users_already_enrolled' => $already_enrolled
        ];
    }

    /**
     * Update enrollment due_date for multiple users
     */
    public function updateDueDate(int $course_id, array $user_ids, ?string $due_date): array
    {
        if (empty($user_ids)) {
            return ['success' => false, 'message' => 'user_ids required'];
        }

        $user_ids = array_map('intval', $user_ids);
        $placeholders = implode(',', array_fill(0, count($user_ids), '?'));

        $query = "
            UPDATE course_enrollments 
            SET due_date = ?, updated_at = NOW()
            WHERE course_id = ? AND tenant_id = ? AND user_id IN ($placeholders)
        ";

        $types = "sii" . str_repeat('i', count($user_ids));
        $params = array_merge([$due_date, $course_id, $this->tenant_id], $user_ids);

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return [
            'success' => true,
            'message' => "$affected enrollment(s) updated",
            'affected_count' => $affected
        ];
    }

    /**
     * Set mandatory flag for enrollments
     */
    public function setMandatory(int $course_id, array $user_ids, bool $is_mandatory): array
    {
        if (empty($user_ids)) {
            return ['success' => false, 'message' => 'user_ids required'];
        }

        $user_ids = array_map('intval', $user_ids);
        $placeholders = implode(',', array_fill(0, count($user_ids), '?'));

        $query = "
            UPDATE course_enrollments 
            SET is_mandatory = ?, updated_at = NOW()
            WHERE course_id = ? AND tenant_id = ? AND user_id IN ($placeholders)
        ";

        $types = "iii" . str_repeat('i', count($user_ids));
        $params = array_merge([(int)$is_mandatory, $course_id, $this->tenant_id], $user_ids);

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return [
            'success' => true,
            'message' => "$affected enrollment(s) updated",
            'affected_count' => $affected
        ];
    }

    public function sendEnrollmentNotification(array $user): void{

    }
}