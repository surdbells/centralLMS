<?php
require_once __DIR__ . '/BaseReport.php';
/**
 * User & Department Reports
 * Reports related to users, activity, and department-level metrics
 */
class UserReports extends BaseReport
{

    public function generate(): array
    {
        return $this->activeUsersReport();
    }

    /**
     * Report 28: Active Users Report
     */
    public function activeUsersReport(int $days = 30): array
    {
        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT u.id as user_id, CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, u.avatar, d.department_name, u.job_title,
                    u.last_login_at,
                    COUNT(DISTINCT ce.course_id) as courses_in_progress,
                    COUNT(DISTINCT ls.session_id) as sessions_count,
                    COALESCE(SUM(ls.time_spent_minutes), 0) as total_learning_minutes
                  FROM users u
                  LEFT JOIN departments d ON u.department = d.department_id
                  LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.is_active = 1 AND ce.status = 'in_progress'
                  LEFT JOIN learning_sessions ls ON u.id = ls.user_id AND ls.started_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                  WHERE u.tenant_id = ? AND u.is_active = 1
                  AND u.last_login_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                  {$orgFilter}
                  GROUP BY u.id, u.first_name, u.last_name, u.email, u.avatar, 
                           d.department_name, u.job_title, u.last_login_at
                  ORDER BY u.last_login_at DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iiiii", $days, $this->tenant_id, $days, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'avatar_url' => $row['avatar_url'],
                'department' => $row['department_name'] ?? 'N/A',
                'job_title' => $row['job_title'] ?? 'N/A',
                'last_login' => $row['last_login_at'],
                'courses_in_progress' => (int)$row['courses_in_progress'],
                'sessions_count' => (int)$row['sessions_count'],
                'learning_time' => $this->formatDuration((int)$row['total_learning_minutes']),
                'activity_level' => $this->getActivityLevel((int)$row['sessions_count'])
            ];
        }

        $countQuery = "SELECT COUNT(*) as total FROM users u WHERE u.tenant_id = ? AND u.is_active = 1 AND u.last_login_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                    {$orgFilter}";
        $stmt = $this->conn->prepare($countQuery);
        $stmt->bind_param("ii", $this->tenant_id, $days);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()['total'];

        return [
            'report_name' => 'Active Users Report',
            'period_days' => $days,
            'data' => $data,
            'meta' => $this->buildMeta($total)
        ];
    }

    /**
     * Report 29: Inactive Users Report
     */
    public function inactiveUsersReport(int $days = 30): array
    {
        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT u.id as user_id, CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, d.department_name, u.job_title,
                    u.last_login_at,
                    DATEDIFF(CURDATE(), u.last_login_at) as days_inactive,
                    COUNT(DISTINCT ce.course_id) as assigned_courses,
                    SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) as incomplete_courses
                  FROM users u
                  LEFT JOIN departments d ON u.department = d.department_id
                  LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.is_active = 1
                  WHERE u.tenant_id = ? AND u.is_active = 1
                  AND (u.last_login_at IS NULL OR u.last_login_at < DATE_SUB(CURDATE(), INTERVAL ? DAY))
                  {$orgFilter}
                  GROUP BY u.id, u.first_name, u.last_name, u.email, d.department_name, 
                           u.job_title, u.last_login_at
                  ORDER BY days_inactive DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iiii", $this->tenant_id, $days, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $daysInactive = $row['days_inactive'] !== null ? (int)$row['days_inactive'] : null;
            $data[] = [
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'department' => $row['department_name'] ?? 'N/A',
                'job_title' => $row['job_title'] ?? 'N/A',
                'last_login' => $row['last_login_at'] ?? 'Never',
                'days_inactive' => $daysInactive ?? 'Never logged in',
                'assigned_courses' => (int)$row['assigned_courses'],
                'incomplete_courses' => (int)$row['incomplete_courses']
            ];
        }

        return [
            'report_name' => 'Inactive Users Report',
            'inactive_threshold_days' => $days,
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 30: New Users Report
     */
    public function newUsersReport(int $days = 30): array
    {
        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT u.id as user_id, CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, d.department_name, u.job_title,
                    u.created as join_date,
                    COUNT(ce.enrollment_id) as courses_assigned,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as courses_completed,
                    u.last_login_at
                  FROM users u
                  LEFT JOIN departments d ON u.department = d.department_id
                  LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.is_active = 1
                  WHERE u.tenant_id = ? AND u.is_active = 1
                  AND u.created >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                  {$orgFilter}
                  GROUP BY u.id, u.first_name, u.last_name, u.email, d.department_name, 
                           u.job_title, u.created, u.last_login_at
                  ORDER BY u.created DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iiii", $this->tenant_id, $days, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'department' => $row['department_name'] ?? 'N/A',
                'job_title' => $row['job_title'] ?? 'N/A',
                'join_date' => $row['join_date'],
                'courses_assigned' => (int)$row['courses_assigned'],
                'courses_completed' => (int)$row['courses_completed'],
                'has_logged_in' => !empty($row['last_login_at']),
                'last_login' => $row['last_login_at']
            ];
        }

        return [
            'report_name' => 'New Users Report',
            'period_days' => $days,
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 31: User Activity Summary
     */
    public function userActivitySummary(int $user_id): array
    {
        // User info
        $userQuery = "SELECT u.*, d.department_name FROM users u
                      LEFT JOIN departments d ON u.department = d.department_id
                      WHERE u.id = ? AND u.tenant_id = ?";
        $stmt = $this->conn->prepare($userQuery);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user) {
            return ['error' => 'User not found'];
        }

        // Login history (last 30 days)
        $loginQuery = "SELECT DATE(login_at) as login_date, COUNT(*) as login_count
                       FROM user_login_history
                       WHERE user_id = ? AND login_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                       GROUP BY DATE(login_at)
                       ORDER BY login_date DESC";
        $stmt = $this->conn->prepare($loginQuery);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $logins = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Course activity
        $courseQuery = "SELECT c.title, ce.status, ce.progress_percentage, ce.enrolled_at,
                          ce.started_at, ce.completed_at, ce.time_spent_minutes
                        FROM course_enrollments ce
                        JOIN courses c ON ce.course_id = c.course_id
                        WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1
                        ORDER BY ce.last_activity_at DESC
                        LIMIT 10";
        $stmt = $this->conn->prepare($courseQuery);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Learning sessions (last 30 days)
        $sessionsQuery = "SELECT DATE(started_at) as session_date, 
                            SUM(time_spent_minutes) as total_minutes,
                            COUNT(*) as session_count
                          FROM learning_sessions
                          WHERE user_id = ? AND tenant_id = ?
                          AND started_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                          GROUP BY DATE(started_at)
                          ORDER BY session_date DESC";
        $stmt = $this->conn->prepare($sessionsQuery);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Assessment activity
        $assessmentQuery = "SELECT a.title, aa.score, aa.passed, aa.completed_at
                           FROM assessment_attempts aa
                           JOIN assessments a ON aa.assessment_id = a.assessment_id
                           WHERE aa.user_id = ? AND a.tenant_id = ?
                           ORDER BY aa.completed_at DESC
                           LIMIT 10";
        $stmt = $this->conn->prepare($assessmentQuery);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $assessments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Points earned
        $pointsQuery = "SELECT COALESCE(SUM(points), 0) as total_points FROM user_points WHERE user_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($pointsQuery);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $points = (int)$stmt->get_result()->fetch_assoc()['total_points'];

        // Badges earned
        $badgesQuery = "SELECT COUNT(*) as badge_count FROM user_badges WHERE user_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($badgesQuery);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $badges = (int)$stmt->get_result()->fetch_assoc()['badge_count'];

        return [
            'report_name' => 'User Activity Summary',
            'user' => [
                'user_id' => (int)$user['id'],
                'full_name' => trim($user['first_name'] . ' ' . $user['last_name']),
                'email' => $user['email'],
                'department' => $user['department_name'] ?? 'N/A',
                'job_title' => $user['job_title'] ?? 'N/A',
                'join_date' => $user['created_at'],
                'last_login' => $user['last_login_at']
            ],
            'summary' => [
                'total_points' => $points,
                'total_badges' => $badges,
                'login_days_last_30' => count($logins),
                'courses_count' => count($courses)
            ],
            'login_history' => $logins,
            'recent_courses' => $courses,
            'learning_sessions' => $sessions,
            'recent_assessments' => $assessments
        ];
    }

    /**
     * Report 32: Department Training Summary
     */
    public function departmentTrainingSummary(): array
    {
        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT d.department_id, d.department_name,
                    COUNT(DISTINCT u.id) as total_users,
                    COUNT(ce.enrollment_id) as total_enrollments,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN ce.status = 'not_started' THEN 1 ELSE 0 END) as not_started,
                    AVG(ce.progress_percentage) as avg_progress,
                    SUM(ce.time_spent_minutes) as total_time,
                    AVG(CASE WHEN ce.status = 'completed' THEN ce.final_score END) as avg_score
                  FROM departments d
                  LEFT JOIN users u ON d.department_id = u.department AND u.tenant_id = ? AND u.is_active = 1
                  LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.is_active = 1
                  WHERE d.tenant_id = ?
                  {$orgFilter}
                  GROUP BY d.department_id, d.department_name
                  ORDER BY total_enrollments DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iiii", $this->tenant_id, $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $enrolled = (int)$row['total_enrollments'];
            $completed = (int)$row['completed'];
            $data[] = [
                'department_id' => $row['department'],
                'department_name' => $row['department_name'],
                'total_users' => (int)$row['total_users'],
                'total_enrollments' => $enrolled,
                'completed' => $completed,
                'in_progress' => (int)$row['in_progress'],
                'not_started' => (int)$row['not_started'],
                'completion_rate' => $enrolled > 0 ? round(($completed / $enrolled) * 100, 1) : 0,
                'avg_progress' => round((float)($row['avg_progress'] ?? 0), 1),
                'total_learning_time' => $this->formatDuration((int)$row['total_time']),
                'avg_score' => round((float)($row['avg_score'] ?? 0), 1)
            ];
        }

        return [
            'report_name' => 'Department Training Summary',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 33: Manager's Team Report
     */
    public function managersTeamReport(int $manager_id): array
    {
        $query = "SELECT u.id as user_id, CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, u.job_title,
                    COUNT(ce.enrollment_id) as total_assigned,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
                    SUM(ce.time_spent_minutes) as total_time,
                    AVG(ce.final_score) as avg_score
                  FROM users u
                  LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.is_active = 1
                  WHERE u.manager_id = ? AND u.tenant_id = ? AND u.is_active = 1
                  GROUP BY u.id, u.first_name, u.last_name, u.email, u.job_title
                  ORDER BY overdue DESC, completed DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iiii", $manager_id, $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $assigned = (int)$row['total_assigned'];
            $completed = (int)$row['completed'];
            $data[] = [
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'job_title' => $row['job_title'] ?? 'N/A',
                'total_assigned' => $assigned,
                'completed' => $completed,
                'in_progress' => (int)$row['in_progress'],
                'overdue' => (int)$row['overdue'],
                'completion_rate' => $assigned > 0 ? round(($completed / $assigned) * 100, 1) : 0,
                'total_learning_time' => $this->formatDuration((int)$row['total_time']),
                'avg_score' => round((float)($row['avg_score'] ?? 0), 1)
            ];
        }

        // Manager info
        $mgrQuery = "SELECT CONCAT(first_name, ' ', last_name) as manager_name FROM users WHERE id = ?";
        $stmt = $this->conn->prepare($mgrQuery);
        $stmt->bind_param("i", $manager_id);
        $stmt->execute();
        $manager = $stmt->get_result()->fetch_assoc();

        return [
            'report_name' => "Manager's Team Report",
            'manager' => [
                'manager_id' => $manager_id,
                'manager_name' => $manager['manager_name'] ?? 'Unknown'
            ],
            'team_size' => count($data),
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 34: User Login History
     */
    public function userLoginHistory(?int $user_id = null): array
    {
        $where = "WHERE ulh.tenant_id = ?";
        $params = [$this->tenant_id];
        $types = "i";

        if ($user_id) {
            $where .= " AND ulh.user_id = ?";
            $params[] = $user_id;
            $types .= "i";
        }

        $dateFilter = $this->buildDateFilter('ulh.login_at');
        $where .= $dateFilter;

        $query = "SELECT ulh.id, u.id as user_id, 
                    CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    ulh.login_at, ulh.logout_at, ulh.ip_address,
                    ulh.device_type, ulh.browser, ulh.location,
                    TIMESTAMPDIFF(MINUTE, ulh.login_at, COALESCE(ulh.logout_at, NOW())) as session_duration
                  FROM user_login_history ulh
                  JOIN users u ON ulh.user_id = u.id
                  $where
                  ORDER BY ulh.login_at DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $params[] = $this->per_page;
        $params[] = $offset;
        $types .= "ii";
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'log_id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'login_at' => $row['login_at'],
                'logout_at' => $row['logout_at'],
                'ip_address' => $row['ip_address'],
                'device_type' => $row['device_type'],
                'browser' => $row['browser'],
                'location' => $row['location'],
                'session_duration' => $this->formatDuration((int)$row['session_duration'])
            ];
        }

        return [
            'report_name' => 'User Login History',
            'user_id' => $user_id,
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 35: Role-based Training Report
     */
    public function roleBasedTrainingReport(): array
    {
        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT u.job_title,
                    COUNT(DISTINCT u.id) as users_count,
                    COUNT(ce.enrollment_id) as total_enrollments,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.is_mandatory = 1 THEN 1 ELSE 0 END) as mandatory_count,
                    SUM(CASE WHEN ce.is_mandatory = 1 AND ce.status = 'completed' THEN 1 ELSE 0 END) as mandatory_completed,
                    AVG(ce.final_score) as avg_score,
                    SUM(ce.time_spent_minutes) as total_time
                  FROM users u
                  LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.is_active = 1
                  WHERE u.tenant_id = ? AND u.is_active = 1 AND u.job_title IS NOT NULL
                  {$orgFilter}
                  GROUP BY u.job_title
                  ORDER BY users_count DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $enrolled = (int)$row['total_enrollments'];
            $completed = (int)$row['completed'];
            $mandatory = (int)$row['mandatory_count'];
            $mandatoryCompleted = (int)$row['mandatory_completed'];

            $data[] = [
                'job_title' => $row['job_title'],
                'users_count' => (int)$row['users_count'],
                'total_enrollments' => $enrolled,
                'completed' => $completed,
                'completion_rate' => $enrolled > 0 ? round(($completed / $enrolled) * 100, 1) : 0,
                'mandatory_count' => $mandatory,
                'mandatory_completed' => $mandatoryCompleted,
                'mandatory_compliance' => $mandatory > 0 ? round(($mandatoryCompleted / $mandatory) * 100, 1) : 100,
                'avg_score' => round((float)($row['avg_score'] ?? 0), 1),
                'total_learning_time' => $this->formatDuration((int)$row['total_time'])
            ];
        }

        return [
            'report_name' => 'Role-based Training Report',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    private function getActivityLevel(int $sessions): string
    {
        if ($sessions >= 20) return 'Very High';
        if ($sessions >= 10) return 'High';
        if ($sessions >= 5) return 'Medium';
        if ($sessions >= 1) return 'Low';
        return 'None';
    }

    // =========================================================================
    // Phase 3b: Org-Unit Profile Reports
    // =========================================================================

    /**
     * Department Profile — Complete overview of a single department
     *
     * Returns: unit info, compliance summary, course breakdown,
     * top learners, and overdue items.
     *
     * Usage: action=run&report=department_profile&department_id=5
     */
    public function departmentProfile(int $department_id): array
    {
        // ── 1. Department info ──
        $infoQuery = "SELECT d.department_id, d.department_name, d.department_code,
                        COUNT(DISTINCT u.id) as total_users,
                        COUNT(DISTINCT CASE WHEN u.last_login_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN u.id END) as active_30_days
                      FROM departments d
                      LEFT JOIN users u ON d.department_id = u.department AND u.tenant_id = ? AND u.is_active = 1
                      WHERE d.department_id = ? AND d.tenant_id = ?
                      GROUP BY d.department_id, d.department_name, d.department_code";

        $stmt = $this->conn->prepare($infoQuery);
        $stmt->bind_param("iii", $this->tenant_id, $department_id, $this->tenant_id);
        $stmt->execute();
        $info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$info) {
            return ['report_name' => 'Department Profile', 'error' => 'Department not found'];
        }

        // ── 2. Compliance summary ──
        $compQuery = "SELECT
                    COUNT(ce.enrollment_id) as total_enrollments,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN ce.status = 'not_started' THEN 1 ELSE 0 END) as not_started,
                    SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
                    SUM(CASE WHEN ce.is_mandatory = 1 THEN 1 ELSE 0 END) as mandatory_total,
                    SUM(CASE WHEN ce.is_mandatory = 1 AND ce.status = 'completed' THEN 1 ELSE 0 END) as mandatory_completed,
                    ROUND(AVG(CASE WHEN ce.status = 'completed' THEN ce.final_score END), 1) as avg_score,
                    ROUND(AVG(ce.time_spent_minutes), 0) as avg_time_minutes
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  WHERE u.department = ? AND ce.tenant_id = ? AND ce.is_active = 1 AND u.is_active = 1";

        $stmt = $this->conn->prepare($compQuery);
        $stmt->bind_param("ii", $department_id, $this->tenant_id);
        $stmt->execute();
        $comp = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $totalEnroll = (int)($comp['total_enrollments'] ?? 0);
        $totalComplete = (int)($comp['completed'] ?? 0);
        $mandTotal = (int)($comp['mandatory_total'] ?? 0);
        $mandComplete = (int)($comp['mandatory_completed'] ?? 0);

        $compliance = [
            'total_enrollments' => $totalEnroll,
            'completed' => $totalComplete,
            'in_progress' => (int)($comp['in_progress'] ?? 0),
            'not_started' => (int)($comp['not_started'] ?? 0),
            'overdue' => (int)($comp['overdue'] ?? 0),
            'completion_rate' => $totalEnroll > 0 ? round(($totalComplete / $totalEnroll) * 100, 1) : 0,
            'mandatory_total' => $mandTotal,
            'mandatory_completed' => $mandComplete,
            'mandatory_compliance_rate' => $mandTotal > 0 ? round(($mandComplete / $mandTotal) * 100, 1) : 100,
            'avg_score' => round((float)($comp['avg_score'] ?? 0), 1),
            'avg_time' => $this->formatDuration((int)($comp['avg_time_minutes'] ?? 0))
        ];

        // ── 3. Course breakdown ──
        $courseQuery = "SELECT c.course_id, c.title,
                    COUNT(ce.enrollment_id) as enrolled,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
                    ROUND(AVG(CASE WHEN ce.status = 'completed' THEN ce.final_score END), 1) as avg_score
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  JOIN courses c ON ce.course_id = c.course_id
                  WHERE u.department = ? AND ce.tenant_id = ? AND ce.is_active = 1 AND u.is_active = 1
                  GROUP BY c.course_id, c.title
                  ORDER BY overdue DESC, enrolled DESC
                  LIMIT 20";

        $stmt = $this->conn->prepare($courseQuery);
        $stmt->bind_param("ii", $department_id, $this->tenant_id);
        $stmt->execute();
        $courseRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $courseBreakdown = [];
        foreach ($courseRows as $row) {
            $enrolled = (int)$row['enrolled'];
            $completed = (int)$row['completed'];
            $courseBreakdown[] = [
                'course_id' => (int)$row['course_id'],
                'title' => $row['title'],
                'enrolled' => $enrolled,
                'completed' => $completed,
                'overdue' => (int)$row['overdue'],
                'completion_rate' => $enrolled > 0 ? round(($completed / $enrolled) * 100, 1) : 0,
                'avg_score' => round((float)($row['avg_score'] ?? 0), 1)
            ];
        }

        // ── 4. Top learners by completions ──
        $learnerQuery = "SELECT u.id as user_id,
                    CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, t.team_name,
                    COUNT(ce.enrollment_id) as total_courses,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
                    ROUND(AVG(CASE WHEN ce.status = 'completed' THEN ce.final_score END), 1) as avg_score
                  FROM users u
                  LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.is_active = 1
                  LEFT JOIN teams t ON u.team = t.team_id
                  WHERE u.department = ? AND u.tenant_id = ? AND u.is_active = 1
                  GROUP BY u.id, u.first_name, u.last_name, u.email, t.team_name
                  ORDER BY completed DESC, overdue ASC
                  LIMIT 20";

        $stmt = $this->conn->prepare($learnerQuery);
        $stmt->bind_param("ii", $department_id, $this->tenant_id);
        $stmt->execute();
        $learnerRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $learners = [];
        foreach ($learnerRows as $row) {
            $learners[] = [
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'team_name' => $row['team_name'] ?? 'Unassigned',
                'total_courses' => (int)$row['total_courses'],
                'completed' => (int)$row['completed'],
                'overdue' => (int)$row['overdue'],
                'avg_score' => round((float)($row['avg_score'] ?? 0), 1)
            ];
        }

        return [
            'report_name' => 'Department Profile',
            'unit_info' => [
                'department_id' => (int)$info['department_id'],
                'department_name' => $info['department_name'],
                'department_code' => $info['department_code'],
                'total_users' => (int)$info['total_users'],
                'active_30_days' => (int)$info['active_30_days']
            ],
            'compliance' => $compliance,
            'course_breakdown' => $courseBreakdown,
            'learners' => $learners
        ];
    }

    /**
     * Branch Profile — Complete overview of a single branch
     *
     * Usage: action=run&report=branch_profile&branch_id=2
     */
    public function branchProfile(int $branch_id): array
    {
        // ── 1. Branch info ──
        $infoQuery = "SELECT b.branch_id, b.branch_name, b.branch_code,
                        COUNT(DISTINCT u.id) as total_users,
                        COUNT(DISTINCT u.department) as department_count,
                        COUNT(DISTINCT CASE WHEN u.last_login_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN u.id END) as active_30_days
                      FROM branches b
                      LEFT JOIN users u ON b.branch_id = u.branch AND u.tenant_id = ? AND u.is_active = 1
                      WHERE b.branch_id = ? AND b.tenant_id = ?
                      GROUP BY b.branch_id, b.branch_name, b.branch_code";

        $stmt = $this->conn->prepare($infoQuery);
        $stmt->bind_param("iii", $this->tenant_id, $branch_id, $this->tenant_id);
        $stmt->execute();
        $info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$info) {
            return ['report_name' => 'Branch Profile', 'error' => 'Branch not found'];
        }

        // ── 2. Compliance summary ──
        $compQuery = "SELECT
                    COUNT(ce.enrollment_id) as total_enrollments,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN ce.status = 'not_started' THEN 1 ELSE 0 END) as not_started,
                    SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
                    SUM(CASE WHEN ce.is_mandatory = 1 THEN 1 ELSE 0 END) as mandatory_total,
                    SUM(CASE WHEN ce.is_mandatory = 1 AND ce.status = 'completed' THEN 1 ELSE 0 END) as mandatory_completed,
                    ROUND(AVG(CASE WHEN ce.status = 'completed' THEN ce.final_score END), 1) as avg_score
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  WHERE u.branch = ? AND ce.tenant_id = ? AND ce.is_active = 1 AND u.is_active = 1";

        $stmt = $this->conn->prepare($compQuery);
        $stmt->bind_param("ii", $branch_id, $this->tenant_id);
        $stmt->execute();
        $comp = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $totalEnroll = (int)($comp['total_enrollments'] ?? 0);
        $totalComplete = (int)($comp['completed'] ?? 0);
        $mandTotal = (int)($comp['mandatory_total'] ?? 0);
        $mandComplete = (int)($comp['mandatory_completed'] ?? 0);

        $compliance = [
            'total_enrollments' => $totalEnroll,
            'completed' => $totalComplete,
            'in_progress' => (int)($comp['in_progress'] ?? 0),
            'not_started' => (int)($comp['not_started'] ?? 0),
            'overdue' => (int)($comp['overdue'] ?? 0),
            'completion_rate' => $totalEnroll > 0 ? round(($totalComplete / $totalEnroll) * 100, 1) : 0,
            'mandatory_total' => $mandTotal,
            'mandatory_completed' => $mandComplete,
            'mandatory_compliance_rate' => $mandTotal > 0 ? round(($mandComplete / $mandTotal) * 100, 1) : 100,
            'avg_score' => round((float)($comp['avg_score'] ?? 0), 1)
        ];

        // ── 3. Department breakdown within branch ──
        $deptQuery = "SELECT d.department_id, d.department_name,
                    COUNT(DISTINCT u.id) as total_users,
                    COUNT(ce.enrollment_id) as enrolled,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue
                  FROM users u
                  LEFT JOIN departments d ON u.department = d.department_id
                  LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.is_active = 1
                  WHERE u.branch = ? AND u.tenant_id = ? AND u.is_active = 1
                  GROUP BY d.department_id, d.department_name
                  ORDER BY overdue DESC, total_users DESC";

        $stmt = $this->conn->prepare($deptQuery);
        $stmt->bind_param("ii", $branch_id, $this->tenant_id);
        $stmt->execute();
        $deptRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $departmentBreakdown = [];
        foreach ($deptRows as $row) {
            $enrolled = (int)$row['enrolled'];
            $completed = (int)$row['completed'];
            $departmentBreakdown[] = [
                'department_id' => (int)$row['department_id'],
                'department_name' => $row['department_name'] ?? 'Unassigned',
                'total_users' => (int)$row['total_users'],
                'enrolled' => $enrolled,
                'completed' => $completed,
                'overdue' => (int)$row['overdue'],
                'completion_rate' => $enrolled > 0 ? round(($completed / $enrolled) * 100, 1) : 0
            ];
        }

        // ── 4. Course breakdown for branch ──
        $courseQuery = "SELECT c.course_id, c.title,
                    COUNT(ce.enrollment_id) as enrolled,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  JOIN courses c ON ce.course_id = c.course_id
                  WHERE u.branch = ? AND ce.tenant_id = ? AND ce.is_active = 1 AND u.is_active = 1
                  GROUP BY c.course_id, c.title
                  ORDER BY overdue DESC, enrolled DESC
                  LIMIT 15";

        $stmt = $this->conn->prepare($courseQuery);
        $stmt->bind_param("ii", $branch_id, $this->tenant_id);
        $stmt->execute();
        $courseRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $courseBreakdown = [];
        foreach ($courseRows as $row) {
            $enrolled = (int)$row['enrolled'];
            $completed = (int)$row['completed'];
            $courseBreakdown[] = [
                'course_id' => (int)$row['course_id'],
                'title' => $row['title'],
                'enrolled' => $enrolled,
                'completed' => $completed,
                'overdue' => (int)$row['overdue'],
                'completion_rate' => $enrolled > 0 ? round(($completed / $enrolled) * 100, 1) : 0
            ];
        }

        // ── 5. Overdue learners in this branch ──
        $overdueQuery = "SELECT u.id as user_id,
                    CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    d.department_name,
                    COUNT(ce.enrollment_id) as overdue_count,
                    MAX(DATEDIFF(CURDATE(), ce.due_date)) as max_days_overdue
                  FROM users u
                  JOIN course_enrollments ce ON u.id = ce.user_id
                  LEFT JOIN departments d ON u.department = d.department_id
                  WHERE u.branch = ? AND u.tenant_id = ? AND u.is_active = 1
                    AND ce.is_active = 1 AND ce.status != 'completed' AND ce.due_date < CURDATE()
                  GROUP BY u.id, u.first_name, u.last_name, d.department_name
                  ORDER BY max_days_overdue DESC
                  LIMIT 15";

        $stmt = $this->conn->prepare($overdueQuery);
        $stmt->bind_param("ii", $branch_id, $this->tenant_id);
        $stmt->execute();
        $overdueRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $overdueLearners = [];
        foreach ($overdueRows as $row) {
            $maxDays = (int)$row['max_days_overdue'];
            $overdueLearners[] = [
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'department' => $row['department_name'] ?? 'Unassigned',
                'overdue_count' => (int)$row['overdue_count'],
                'max_days_overdue' => $maxDays,
                'risk_level' => $maxDays > 60 ? 'critical' : ($maxDays > 30 ? 'high' : ($maxDays > 7 ? 'medium' : 'low'))
            ];
        }

        return [
            'report_name' => 'Branch Profile',
            'unit_info' => [
                'branch_id' => (int)$info['branch_id'],
                'branch_name' => $info['branch_name'],
                'branch_code' => $info['branch_code'],
                'total_users' => (int)$info['total_users'],
                'department_count' => (int)$info['department_count'],
                'active_30_days' => (int)$info['active_30_days']
            ],
            'compliance' => $compliance,
            'department_breakdown' => $departmentBreakdown,
            'course_breakdown' => $courseBreakdown,
            'overdue_learners' => $overdueLearners
        ];
    }

    /**
     * Team Profile — Complete overview of a single team
     *
     * Usage: action=run&report=team_profile&team_id=7
     */
    public function teamProfile(int $team_id): array
    {
        // ── 1. Team info ──
        $infoQuery = "SELECT t.team_id, t.team_name, t.team_code,
                        d.department_name, b.branch_name,
                        COUNT(DISTINCT u.id) as total_users,
                        COUNT(DISTINCT CASE WHEN u.last_login_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN u.id END) as active_30_days
                      FROM teams t
                      LEFT JOIN users u ON t.team_id = u.team AND u.tenant_id = ? AND u.is_active = 1
                      LEFT JOIN departments d ON u.department = d.department_id
                      LEFT JOIN branches b ON u.branch = b.branch_id
                      WHERE t.team_id = ? AND t.tenant_id = ?
                      GROUP BY t.team_id, t.team_name, t.team_code, d.department_name, b.branch_name";

        $stmt = $this->conn->prepare($infoQuery);
        $stmt->bind_param("iii", $this->tenant_id, $team_id, $this->tenant_id);
        $stmt->execute();
        $info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$info) {
            return ['report_name' => 'Team Profile', 'error' => 'Team not found'];
        }

        // ── 2. Compliance summary ──
        $compQuery = "SELECT
                    COUNT(ce.enrollment_id) as total_enrollments,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN ce.status = 'not_started' THEN 1 ELSE 0 END) as not_started,
                    SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
                    SUM(CASE WHEN ce.is_mandatory = 1 THEN 1 ELSE 0 END) as mandatory_total,
                    SUM(CASE WHEN ce.is_mandatory = 1 AND ce.status = 'completed' THEN 1 ELSE 0 END) as mandatory_completed,
                    ROUND(AVG(CASE WHEN ce.status = 'completed' THEN ce.final_score END), 1) as avg_score,
                    SUM(ce.time_spent_minutes) as total_time_minutes
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  WHERE u.team = ? AND ce.tenant_id = ? AND ce.is_active = 1 AND u.is_active = 1";

        $stmt = $this->conn->prepare($compQuery);
        $stmt->bind_param("ii", $team_id, $this->tenant_id);
        $stmt->execute();
        $comp = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $totalEnroll = (int)($comp['total_enrollments'] ?? 0);
        $totalComplete = (int)($comp['completed'] ?? 0);
        $mandTotal = (int)($comp['mandatory_total'] ?? 0);
        $mandComplete = (int)($comp['mandatory_completed'] ?? 0);

        $compliance = [
            'total_enrollments' => $totalEnroll,
            'completed' => $totalComplete,
            'in_progress' => (int)($comp['in_progress'] ?? 0),
            'not_started' => (int)($comp['not_started'] ?? 0),
            'overdue' => (int)($comp['overdue'] ?? 0),
            'completion_rate' => $totalEnroll > 0 ? round(($totalComplete / $totalEnroll) * 100, 1) : 0,
            'mandatory_total' => $mandTotal,
            'mandatory_completed' => $mandComplete,
            'mandatory_compliance_rate' => $mandTotal > 0 ? round(($mandComplete / $mandTotal) * 100, 1) : 100,
            'avg_score' => round((float)($comp['avg_score'] ?? 0), 1),
            'total_learning_time' => $this->formatDuration((int)($comp['total_time_minutes'] ?? 0))
        ];

        // ── 3. Course breakdown ──
        $courseQuery = "SELECT c.course_id, c.title, ce.is_mandatory,
                    COUNT(ce.enrollment_id) as enrolled,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
                    ROUND(AVG(CASE WHEN ce.status = 'completed' THEN ce.final_score END), 1) as avg_score
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  JOIN courses c ON ce.course_id = c.course_id
                  WHERE u.team = ? AND ce.tenant_id = ? AND ce.is_active = 1 AND u.is_active = 1
                  GROUP BY c.course_id, c.title, ce.is_mandatory
                  ORDER BY ce.is_mandatory DESC, overdue DESC
                  LIMIT 20";

        $stmt = $this->conn->prepare($courseQuery);
        $stmt->bind_param("ii", $team_id, $this->tenant_id);
        $stmt->execute();
        $courseRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $courseBreakdown = [];
        foreach ($courseRows as $row) {
            $enrolled = (int)$row['enrolled'];
            $completed = (int)$row['completed'];
            $courseBreakdown[] = [
                'course_id' => (int)$row['course_id'],
                'title' => $row['title'],
                'is_mandatory' => (bool)$row['is_mandatory'],
                'enrolled' => $enrolled,
                'completed' => $completed,
                'overdue' => (int)$row['overdue'],
                'completion_rate' => $enrolled > 0 ? round(($completed / $enrolled) * 100, 1) : 0,
                'avg_score' => round((float)($row['avg_score'] ?? 0), 1)
            ];
        }

        // ── 4. Full member list with individual stats ──
        $memberQuery = "SELECT u.id as user_id,
                    CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, u.job_title,
                    COUNT(ce.enrollment_id) as total_courses,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
                    ROUND(AVG(CASE WHEN ce.status = 'completed' THEN ce.final_score END), 1) as avg_score,
                    SUM(ce.time_spent_minutes) as total_minutes,
                    u.last_login_at
                  FROM users u
                  LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.is_active = 1
                  WHERE u.team = ? AND u.tenant_id = ? AND u.is_active = 1
                  GROUP BY u.id, u.first_name, u.last_name, u.email, u.job_title, u.last_login_at
                  ORDER BY overdue DESC, completed DESC";

        $stmt = $this->conn->prepare($memberQuery);
        $stmt->bind_param("ii", $team_id, $this->tenant_id);
        $stmt->execute();
        $memberRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $members = [];
        foreach ($memberRows as $row) {
            $members[] = [
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'job_title' => $row['job_title'],
                'total_courses' => (int)$row['total_courses'],
                'completed' => (int)$row['completed'],
                'overdue' => (int)$row['overdue'],
                'avg_score' => round((float)($row['avg_score'] ?? 0), 1),
                'total_time' => $this->formatDuration((int)($row['total_minutes'] ?? 0)),
                'last_login' => $row['last_login_at']
            ];
        }

        return [
            'report_name' => 'Team Profile',
            'unit_info' => [
                'team_id' => (int)$info['team_id'],
                'team_name' => $info['team_name'],
                'team_code' => $info['team_code'],
                'department_name' => $info['department_name'] ?? 'Unassigned',
                'branch_name' => $info['branch_name'] ?? 'Unassigned',
                'total_users' => (int)$info['total_users'],
                'active_30_days' => (int)$info['active_30_days']
            ],
            'compliance' => $compliance,
            'course_breakdown' => $courseBreakdown,
            'members' => $members
        ];
    }

    public function getSummary(): array
    {
        $query = "SELECT 
                    COUNT(*) as total_users,
                    SUM(CASE WHEN last_login_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as active_7_days,
                    SUM(CASE WHEN last_login_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as active_30_days,
                    SUM(CASE WHEN last_login_at IS NULL OR last_login_at < DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as inactive
                  FROM users WHERE tenant_id = ? AND is_active = 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}
