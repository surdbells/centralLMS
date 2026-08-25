<?php
/**
 * Learning Path Assignment Model
 * Multi-Tenant LMS
 *
 * Handles assigning learning paths to users, teams, departments, or branches.
 */

class LearningPathAssignment {
    private mysqli $conn;

    // Properties
    public ?int $assignment_id = null;
    public int $path_id;
    public int $tenant_id;
    public string $assignment_type = 'users'; // department, team, branch, users
    public ?int $department_id = null;
    public ?int $team_id = null;
    public ?int $branch_id = null;
    public ?array $user_ids = null; // For 'users' assignment type - multiple user IDs
    public int $assigned_by;
    public ?string $due_date = null;
    public ?int $due_days_from_assignment = null;
    public string $priority = 'normal'; // low, normal, high, critical
    public bool $is_mandatory = false;
    public bool $send_notification = true;
    public bool $send_reminder = true;
    public int $reminder_days_before = 7;
    public string $assignment_notes = '';
    public string $status = 'active'; // active, paused, completed, cancelled
    public bool $is_active = true;

    // Pagination
    public int $page = 1;
    public int $per_page = 20;
    public string $sort_by = 'created_at';
    public string $sort_dir = 'desc';

    public function __construct(mysqli $db) {
        $this->conn = $db;
    }

    /**
     * Preview assignment - show which users will be enrolled without actually enrolling
     * @return array Preview data with users to enroll and already enrolled
     */
    public function preview(): array {
        // Get path info
        $pathSql = "SELECT path_id, title, thumbnail_url FROM learning_paths 
                    WHERE path_id = ? AND tenant_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($pathSql);
        if (!$stmt) {
            return ['success' => false, 'error' => 'Database error'];
        }
        $stmt->bind_param("ii", $this->path_id, $this->tenant_id);
        $stmt->execute();
        $pathResult = $stmt->get_result()->fetch_assoc();

        if (!$pathResult) {
            return ['success' => false, 'error' => 'Learning path not found'];
        }

        // Get target users based on assignment type
        $targetUsers = $this->getTargetUsersWithDetails();

        $usersToEnroll = [];
        $usersAlreadyEnrolled = [];

        foreach ($targetUsers as $user) {
            // Check if already enrolled
            $checkSql = "SELECT enrollment_id FROM learning_path_enrollments 
                         WHERE path_id = ? AND user_id = ? AND status IN ('active', 'pending') AND is_active = 1";
            $checkStmt = $this->conn->prepare($checkSql);
            $checkStmt->bind_param("ii", $this->path_id, $user['user_id']);
            $checkStmt->execute();

            $userData = [
                'user_id' => (int)$user['user_id'],
                'email' => $user['email'],
                'name' => trim($user['first_name'] . ' ' . $user['last_name'])
            ];

            if ($checkStmt->get_result()->num_rows > 0) {
                $usersAlreadyEnrolled[] = $userData;
            } else {
                $usersToEnroll[] = $userData;
            }
        }

        return [
            'success' => true,
            'path' => [
                'path_id' => (int)$pathResult['path_id'],
                'title' => $pathResult['title']
            ],
            'summary' => [
                'total_users' => count($targetUsers),
                'will_enroll' => count($usersToEnroll),
                'already_enrolled' => count($usersAlreadyEnrolled)
            ],
            'users_to_enroll' => $usersToEnroll,
            'users_already_enrolled' => $usersAlreadyEnrolled
        ];
    }

    /**
     * Get target users with full details (for preview)
     * @return array Array of user details
     */
    private function getTargetUsersWithDetails(): array {
        $users = [];

        switch ($this->assignment_type) {
            case 'users':
                // Multiple users selected individually
                if (!empty($this->user_ids) && is_array($this->user_ids)) {
                    $placeholders = implode(',', array_fill(0, count($this->user_ids), '?'));
                    $sql = "SELECT id as user_id, email, first_name, last_name 
                            FROM users 
                            WHERE id IN ($placeholders) 
                            AND tenant_id = ? 
                            AND is_active = 1";

                    $stmt = $this->conn->prepare($sql);
                    if (!$stmt) return [];

                    // Build types string: 'i' for each user_id + 'i' for tenant_id
                    $types = str_repeat('i', count($this->user_ids)) . 'i';
                    $params = array_merge($this->user_ids, [$this->tenant_id]);
                    $stmt->bind_param($types, ...$params);
                } else {
                    return [];
                }
                break;

            case 'department':
                $sql = "SELECT id as user_id, email, first_name, last_name 
                        FROM users 
                        WHERE department = ? AND tenant_id = ? AND is_active = 1";
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) return [];
                $stmt->bind_param("ii", $this->department_id, $this->tenant_id);
                break;

            case 'team':
                $sql = "SELECT id as user_id, email, first_name, last_name 
                        FROM users 
                        WHERE team = ? AND tenant_id = ? AND is_active = 1";
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) return [];
                $stmt->bind_param("ii", $this->team_id, $this->tenant_id);
                break;

            case 'branch':
                $sql = "SELECT id as user_id, email, first_name, last_name 
                        FROM users 
                        WHERE branch = ? AND tenant_id = ? AND is_active = 1";
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) return [];
                $stmt->bind_param("ii", $this->branch_id, $this->tenant_id);
                break;

            default:
                return [];
        }

        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }

        return $users;
    }

    /**
     * Create assignment
     */
    public function create(): array {
        $sql = "INSERT INTO learning_path_assignments (
            path_id, tenant_id, assignment_type,
            department_id, team_id, branch_id,
            assigned_by, assigned_at, due_date, due_days_from_assignment,
            priority, is_mandatory, send_notification, send_reminder,
            reminder_days_before, assignment_notes, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return ['success' => false, 'error' => 'Database error: ' . $this->conn->error];
        }

        $is_mandatory = $this->is_mandatory ? 1 : 0;
        $send_notification = $this->send_notification ? 1 : 0;
        $send_reminder = $this->send_reminder ? 1 : 0;

        $stmt->bind_param(
            "iisiiiisissiiiss",
            $this->path_id, $this->tenant_id, $this->assignment_type,
            $this->department_id, $this->team_id, $this->branch_id,
            $this->assigned_by, $this->due_date, $this->due_days_from_assignment,
            $this->priority, $is_mandatory, $send_notification, $send_reminder,
            $this->reminder_days_before, $this->assignment_notes, $this->status
        );

        if ($stmt->execute()) {
            $this->assignment_id = $this->conn->insert_id;

            // Process the assignment (enroll users)
            $enrollmentResult = $this->processAssignment();

            return [
                'success' => true,
                'assignment_id' => $this->assignment_id,
                'enrollments_created' => count($enrollmentResult['enrolled']),
                'users_enrolled' => $enrollmentResult['enrolled'],
                'users_skipped' => $enrollmentResult['skipped']
            ];
        }

        return ['success' => false, 'error' => $stmt->error];
    }

    /**
     * Process assignment and enroll users
     */
    private function processAssignment(): array {
        $users = $this->getTargetUsers();
        $enrolled = [];
        $skipped = [];

        foreach ($users as $user_id) {
            $result = $this->enrollUser($user_id);
            if ($result['success']) {
                $enrolled[] = $user_id;

                if ($this->send_notification) {
                    $this->sendAssignmentNotification($user_id, $result['enrollment_id']);
                }
            } else {
                $skipped[] = ['user_id' => $user_id, 'reason' => $result['error']];
            }
        }

        return ['enrolled' => $enrolled, 'skipped' => $skipped];
    }

    /**
     * Get target users based on assignment type (IDs only)
     * @return array Array of user IDs
     */
    private function getTargetUsers(): array {
        $users = [];

        switch ($this->assignment_type) {
            case 'users':
                // Multiple users selected individually
                if (!empty($this->user_ids) && is_array($this->user_ids)) {
                    // Validate that these users exist and are active
                    $placeholders = implode(',', array_fill(0, count($this->user_ids), '?'));
                    $sql = "SELECT id as user_id FROM users 
                            WHERE id IN ($placeholders) 
                            AND tenant_id = ? 
                            AND is_active = 1";

                    $stmt = $this->conn->prepare($sql);
                    if (!$stmt) return [];

                    $types = str_repeat('i', count($this->user_ids)) . 'i';
                    $params = array_merge($this->user_ids, [$this->tenant_id]);
                    $stmt->bind_param($types, ...$params);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    while ($row = $result->fetch_assoc()) {
                        $users[] = (int)$row['user_id'];
                    }
                }
                break;

            case 'department':
                $sql = "SELECT id as user_id FROM users WHERE department = ? AND tenant_id = ? AND is_active = 1";
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) return [];
                $stmt->bind_param("ii", $this->department_id, $this->tenant_id);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $users[] = (int)$row['user_id'];
                }
                break;

            case 'team':
                $sql = "SELECT id as user_id FROM users WHERE team = ? AND tenant_id = ? AND is_active = 1";
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) return [];
                $stmt->bind_param("ii", $this->team_id, $this->tenant_id);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $users[] = (int)$row['user_id'];
                }
                break;

            case 'branch':
                $sql = "SELECT id as user_id FROM users WHERE branch = ? AND tenant_id = ? AND is_active = 1";
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) return [];
                $stmt->bind_param("ii", $this->branch_id, $this->tenant_id);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $users[] = (int)$row['user_id'];
                }
                break;
        }

        return $users;
    }

    /**
     * Enroll a single user
     */
    private function enrollUser(int $user_id): array {
        // Check if already enrolled
        $sql = "SELECT enrollment_id FROM learning_path_enrollments 
                WHERE path_id = ? AND user_id = ? AND status IN ('active', 'pending')";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->path_id, $user_id);
        $stmt->execute();

        if ($stmt->get_result()->num_rows > 0) {
            return ['success' => false, 'error' => 'Already enrolled'];
        }

        // Calculate due date
        $dueDate = $this->due_date;
        if (!$dueDate && $this->due_days_from_assignment) {
            $dueDate = date('Y-m-d', strtotime("+ $this->due_days_from_assignment days"));
        }

        // Get items count
        $sql = "SELECT COUNT(*) as count FROM learning_path_items WHERE path_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->path_id);
        $stmt->execute();
        $itemsTotal = $stmt->get_result()->fetch_assoc()['count'];

        // Create enrollment
        $sql = "INSERT INTO learning_path_enrollments (
            path_id, user_id, tenant_id, status, enrollment_type,
            enrolled_by, items_total, due_date, admin_notes, enrolled_at
        ) VALUES (?, ?, ?, 'active', 'admin', ?, ?, ?, ?, NOW())";

        $stmt = $this->conn->prepare($sql);
        $notes = "Assigned via assignment #$this->assignment_id";
        $stmt->bind_param("iiiisss", $this->path_id, $user_id, $this->tenant_id, $this->assigned_by, $itemsTotal, $dueDate, $notes);

        if ($stmt->execute()) {
            $enrollmentId = $this->conn->insert_id;

            // Initialize progress records
            $this->initializeProgress($enrollmentId, $user_id);

            return ['success' => true, 'enrollment_id' => $enrollmentId];
        }

        return ['success' => false, 'error' => $stmt->error];
    }

    /**
     * Initialize progress records for enrollment
     */
    private function initializeProgress(int $enrollment_id, int $user_id): void {
        // Initialize item progress
        $sql = "INSERT INTO learning_path_item_progress (enrollment_id, item_id, user_id, tenant_id, status, created_at)
                SELECT ?, item_id, ?, ?,
                    IF(sort_order = (SELECT MIN(sort_order) FROM learning_path_items WHERE path_id = ? AND is_active = 1), 'available', 'locked'),
                    NOW()
                FROM learning_path_items WHERE path_id = ? AND is_active = 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiiii", $enrollment_id, $user_id, $this->tenant_id, $this->path_id, $this->path_id);
        $stmt->execute();

        // Initialize section progress
        $sql = "INSERT INTO learning_path_section_progress (enrollment_id, section_id, user_id, tenant_id, status, items_total, created_at)
                SELECT ?, section_id, ?, ?,
                    IF(sort_order = 1, 'available', 'locked'),
                    (SELECT COUNT(*) FROM learning_path_items WHERE section_id = s.section_id AND is_active = 1),
                    NOW()
                FROM learning_path_sections s WHERE path_id = ? AND is_active = 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiii", $enrollment_id, $user_id, $this->tenant_id, $this->path_id);
        $stmt->execute();
    }

    /**
     * Send assignment notification
     */
    private function sendAssignmentNotification(int $user_id, int $enrollment_id): void {
        $sql = "INSERT INTO notifications (
            user_id, tenant_id, type, title, message, 
            entity_type, entity_id, created_at
        ) VALUES (?, ?, 'assignment', 'New Learning Path Assigned', ?, 'learning_path_enrollment', ?, NOW())";

        $stmt = $this->conn->prepare($sql);
        $message = "You have been assigned a new learning path. " . ($this->is_mandatory ? "This is mandatory." : "");
        $stmt->bind_param("iisi", $user_id, $this->tenant_id, $message, $enrollment_id);
        $stmt->execute();
    }

    /**
     * Get assignment by ID
     */
    public function getById(int $assignment_id): ?array {
        $sql = "SELECT a.*, 
                lp.title as path_title, lp.thumbnail_url as path_thumbnail,
                u.first_name as assigned_by_first_name, u.last_name as assigned_by_last_name,
                d.department_name as department_name,
                t.team_name as team_name,
                b.branch_name as branch_name,
                (SELECT COUNT(*) FROM learning_path_enrollments WHERE path_id = a.path_id AND admin_notes LIKE CONCAT('%assignment #', a.assignment_id, '%')) as users_enrolled
                FROM learning_path_assignments a
                JOIN learning_paths lp ON a.path_id = lp.path_id
                JOIN users u ON a.assigned_by = u.id
                LEFT JOIN departments d ON a.department_id = d.department_id
                LEFT JOIN teams t ON a.team_id = t.team_id
                LEFT JOIN branches b ON a.branch_id = b.branch_id
                WHERE a.assignment_id = ? AND a.tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $assignment_id, $this->tenant_id);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * List assignments
     */
    public function list(?int $path_id = null): array {
        $where = ["a.tenant_id = ?", "a.is_active = 1"];
        $params = [$this->tenant_id];
        $types = "i";

        if ($path_id) {
            $where[] = "a.path_id = ?";
            $params[] = $path_id;
            $types .= "i";
        }

        $whereClause = implode(" AND ", $where);

        // Count
        $countSql = "SELECT COUNT(*) as total FROM learning_path_assignments a WHERE $whereClause";
        $stmt = $this->conn->prepare($countSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];

        // Get data
        $offset = ($this->page - 1) * $this->per_page;
        $sql = "SELECT a.*, 
                lp.title as path_title,
                u.first_name as assigned_by_first_name, u.last_name as assigned_by_last_name,
                d.name as department_name,
                t.name as team_name,
                b.name as branch_name
                FROM learning_path_assignments a
                JOIN learning_paths lp ON a.path_id = lp.path_id
                JOIN users u ON a.assigned_by = u.id
                LEFT JOIN departments d ON a.department_id = d.id
                LEFT JOIN teams t ON a.team_id = t.id
                LEFT JOIN branches b ON a.branch_id = b.id
                WHERE $whereClause
                ORDER BY a.created_at DESC
                LIMIT ? OFFSET ?";

        $params[] = $this->per_page;
        $params[] = $offset;
        $types .= "ii";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $assignments = [];
        while ($row = $result->fetch_assoc()) {
            // Add target_description
            switch ($row['assignment_type']) {
                case 'department':
                    $row['target_description'] = $row['department_name'] ?? 'Department';
                    break;
                case 'team':
                    $row['target_description'] = $row['team_name'] ?? 'Team';
                    break;
                case 'branch':
                    $row['target_description'] = $row['branch_name'] ?? 'Branch';
                    break;
                case 'users':
                    // Count enrolled users for this assignment
                    $countSql = "SELECT COUNT(*) as cnt FROM learning_path_enrollments 
                                 WHERE admin_notes LIKE ? AND is_active = 1";
                    $countStmt = $this->conn->prepare($countSql);
                    $pattern = "%assignment #" . $row['assignment_id'] . "%";
                    $countStmt->bind_param("s", $pattern);
                    $countStmt->execute();
                    $cnt = $countStmt->get_result()->fetch_assoc()['cnt'];
                    $row['target_description'] = $cnt . ' User' . ($cnt != 1 ? 's' : '');
                    break;
                default:
                    $row['target_description'] = 'Unknown';
            }
            $assignments[] = $row;
        }

        return [
            'data' => $assignments,
            'pagination' => [
                'total' => (int)$total,
                'page' => $this->page,
                'per_page' => $this->per_page,
                'total_pages' => ceil($total / $this->per_page)
            ]
        ];
    }

    /**
     * Cancel assignment
     */
    public function cancel(): bool {
        $sql = "UPDATE learning_path_assignments SET status = 'cancelled', updated_at = NOW() WHERE assignment_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->assignment_id, $this->tenant_id);
        return $stmt->execute();
    }

    /**
     * Pause assignment (stop sending reminders, but keep enrollments active)
     */
    public function pause(): bool {
        $sql = "UPDATE learning_path_assignments SET status = 'paused', updated_at = NOW() WHERE assignment_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->assignment_id, $this->tenant_id);
        return $stmt->execute();
    }

    /**
     * Resume paused assignment
     */
    public function resume(): bool {
        $sql = "UPDATE learning_path_assignments SET status = 'active', updated_at = NOW() WHERE assignment_id = ? AND tenant_id = ? AND status = 'paused'";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->assignment_id, $this->tenant_id);
        return $stmt->execute();
    }

    /**
     * Get assignment statistics
     * @param int $assignment_id Assignment ID
     * @return array Statistics data
     */
    public function getStatistics(int $assignment_id): array {
        // Get assignment details
        $assignment = $this->getById($assignment_id);
        if (!$assignment) {
            return ['error' => 'Assignment not found'];
        }

        // Get enrollment statistics for users assigned by this assignment
        $sql = "SELECT 
                COUNT(*) as total_enrollments,
                SUM(CASE WHEN e.status = 'active' AND e.progress_percentage > 0 THEN 1 ELSE 0 END) as in_progress_enrollments,
                SUM(CASE WHEN e.status = 'active' AND e.progress_percentage = 0 THEN 1 ELSE 0 END) as not_started_enrollments,
                SUM(CASE WHEN e.status = 'completed' THEN 1 ELSE 0 END) as completed_enrollments,
                SUM(CASE WHEN e.status = 'withdrawn' THEN 1 ELSE 0 END) as withdrawn_enrollments,
                SUM(CASE WHEN e.due_date < CURDATE() AND e.status = 'active' THEN 1 ELSE 0 END) as overdue_enrollments,
                COALESCE(AVG(e.progress_percentage), 0) as avg_progress,
                COALESCE(AVG(e.total_time_spent_minutes), 0) as avg_time_spent,
                COALESCE(AVG(CASE WHEN e.completed_at IS NOT NULL THEN DATEDIFF(e.completed_at, e.enrolled_at) END), 0) as avg_completion_days
                FROM learning_path_enrollments e
                WHERE e.path_id = ? 
                AND e.admin_notes LIKE ?
                AND e.is_active = 1";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return ['error' => 'Database error'];
        }

        $notePattern = "%assignment #" . $assignment_id . "%";
        $stmt->bind_param("is", $assignment['path_id'], $notePattern);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();

        // Type cast all values
        return [
            'total_enrollments' => (int)($stats['total_enrollments'] ?? 0),
            'completed_enrollments' => (int)($stats['completed_enrollments'] ?? 0),
            'in_progress_enrollments' => (int)($stats['in_progress_enrollments'] ?? 0),
            'not_started_enrollments' => (int)($stats['not_started_enrollments'] ?? 0),
            'overdue_enrollments' => (int)($stats['overdue_enrollments'] ?? 0),
            'avg_progress' => round((float)($stats['avg_progress'] ?? 0), 1),
            'avg_completion_days' => round((float)($stats['avg_completion_days'] ?? 0), 1),
            'avg_time_spent' => round((float)($stats['avg_time_spent'] ?? 0), 0),
            'on_track_count' => (int)($stats['total_enrollments'] ?? 0) - (int)($stats['overdue_enrollments'] ?? 0),
            'at_risk_count' => (int)($stats['overdue_enrollments'] ?? 0)
        ];
    }

    /**
     * Send reminders to all incomplete enrollees NOW (manual trigger from UI)
     * @return array Results with count of sent reminders
     */
    public function sendRemindersNow(): array {
        $results = [
            'sent' => 0,
            'failed' => 0,
            'users' => []
        ];

        // Get assignment details
        $assignment = $this->getById($this->assignment_id);
        if (!$assignment) {
            return $results;
        }

        // Get all active enrollments for this assignment that are not completed
        $sql = "SELECT e.enrollment_id, e.user_id, e.due_date, e.progress_percentage,
                lp.title as path_title, u.email, u.first_name
                FROM learning_path_enrollments e
                JOIN learning_paths lp ON e.path_id = lp.path_id
                JOIN users u ON e.user_id = u.id
                WHERE e.path_id = ?
                AND e.admin_notes LIKE ?
                AND e.status = 'active'
                AND e.is_active = 1
                AND u.is_active = 1";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return $results;

        $notePattern = "%assignment #" . $this->assignment_id . "%";
        $stmt->bind_param("is", $assignment['path_id'], $notePattern);
        $stmt->execute();
        $enrollments = $stmt->get_result();

        while ($enrollment = $enrollments->fetch_assoc()) {
            // Create notification
            $notifSql = "INSERT INTO notifications (
                user_id, tenant_id, type, title, message, 
                entity_type, entity_id, created_at
            ) VALUES (?, ?, 'reminder', 'Learning Path Reminder', ?, 'learning_path_enrollment', ?, NOW())";

            $notifStmt = $this->conn->prepare($notifSql);
            if (!$notifStmt) continue;

            $progress = round($enrollment['progress_percentage']);
            $message = "Reminder: You have an assigned learning path \"{$enrollment['path_title']}\" to complete. Current progress: {$progress}%.";

            if ($enrollment['due_date']) {
                $daysLeft = (int)((strtotime($enrollment['due_date']) - time()) / 86400);
                if ($daysLeft > 0) {
                    $message .= " Due in {$daysLeft} day" . ($daysLeft !== 1 ? 's' : '') . ".";
                } elseif ($daysLeft < 0) {
                    $message .= " This is overdue!";
                } else {
                    $message .= " Due today!";
                }
            }

            $notifStmt->bind_param("iisi",
                $enrollment['user_id'],
                $this->tenant_id,
                $message,
                $enrollment['enrollment_id']
            );

            if ($notifStmt->execute()) {
                $results['sent']++;
                $results['users'][] = $enrollment['user_id'];
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Get assignments due for reminder (for batch processing)
     */
    public function getDueForReminder(): array {
        $sql = "SELECT a.*, lpe.enrollment_id, lpe.user_id, lpe.due_date,
                lp.title as path_title
                FROM learning_path_assignments a
                JOIN learning_path_enrollments lpe ON lpe.path_id = a.path_id
                    AND lpe.admin_notes LIKE CONCAT('%assignment #', a.assignment_id, '%')
                JOIN learning_paths lp ON a.path_id = lp.path_id
                WHERE a.send_reminder = 1
                AND a.status = 'active'
                AND lpe.status = 'active'
                AND lpe.due_date IS NOT NULL
                AND DATEDIFF(lpe.due_date, CURDATE()) = a.reminder_days_before
                AND a.tenant_id = ?
                AND a.is_active = 1
                AND lpe.is_active = 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $reminders = [];
        while ($row = $result->fetch_assoc()) {
            $reminders[] = $row;
        }

        return $reminders;
    }
}