<?php
namespace report;
require_once __DIR__ . '/BaseReport.php';
class CompletionReports extends BaseReport
{

    public function generate(): array
    {
        return $this->courseCompletionSummary();
    }

    /**
     * Report 6: Course Completion Summary
     */
    public function courseCompletionSummary(): array
    {
        $dateFilter = $this->buildDateFilter('ce.completed_at');

        $query = "SELECT c.course_id, c.title as course_title, c.duration_hours,
                    COUNT(ce.enrollment_id) as total_enrolled,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    AVG(CASE WHEN ce.status = 'completed' THEN ce.final_score END) as avg_score,
                    AVG(CASE WHEN ce.status = 'completed' THEN DATEDIFF(ce.completed_at, ce.enrolled_at) END) as avg_days_to_complete,
                    AVG(CASE WHEN ce.status = 'completed' THEN ce.time_spent_minutes END) as avg_time_spent
                  FROM courses c
                  LEFT JOIN course_enrollments ce ON c.course_id = ce.course_id AND ce.is_active = 1
                  WHERE c.tenant_id = ? AND c.is_active = 1 $dateFilter
                  GROUP BY c.course_id, c.title, c.duration_hours
                  ORDER BY completed DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $enrolled = (int)$row['total_enrolled'];
            $completed = (int)$row['completed'];
            $data[] = [
                'course_id' => (int)$row['course_id'],
                'course_title' => $row['course_title'],
                'duration_hours' => (float)$row['duration_hours'],
                'total_enrolled' => $enrolled,
                'completed' => $completed,
                'completion_rate' => $enrolled > 0 ? round(($completed / $enrolled) * 100, 1) : 0,
                'avg_score' => round((float)($row['avg_score'] ?? 0), 1),
                'avg_days_to_complete' => round((float)($row['avg_days_to_complete'] ?? 0), 1),
                'avg_time_spent' => $this->formatDuration((int)($row['avg_time_spent'] ?? 0))
            ];
        }

        $countQuery = "SELECT COUNT(*) as total FROM courses WHERE tenant_id = ? AND is_active = 1";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'Course Completion Summary',
            'data' => $data,
            'meta' => $this->buildMeta($total),
            'summary' => $this->getCompletionSummaryStats()
        ];
    }

    /**
     * Report 7: Individual Progress Report
     */
    public function individualProgressReport(int $user_id): array
    {
        // User info
        $userQuery = "SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) as full_name,
                        u.email, u.job_title, d.department_name, u.created as joined_date
                      FROM users u
                      LEFT JOIN departments d ON u.department = d.department_id
                      WHERE u.id = ? AND u.tenant_id = ?";
        $stmt = $this->conn->prepare($userQuery);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user) {
            return ['error' => 'User not found'];
        }

        // Enrollments
        $enrollQuery = "SELECT ce.enrollment_id, c.course_id, c.title as course_title,
                          ce.status, ce.progress_percentage, ce.final_score,
                          ce.enrolled_at, ce.started_at, ce.completed_at,
                          ce.due_date, ce.time_spent_minutes, ce.is_mandatory,
                          cat.name as category
                        FROM course_enrollments ce
                        JOIN courses c ON ce.course_id = c.course_id
                        LEFT JOIN categories cat ON c.category_id = cat.category_id
                        WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1
                        ORDER BY ce.enrolled_at DESC";
        $stmt = $this->conn->prepare($enrollQuery);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $courses = [];
        $totalTime = 0;
        $completed = 0;
        $inProgress = 0;
        $notStarted = 0;
        $totalScore = 0;
        $scoreCount = 0;

        while ($row = $result->fetch_assoc()) {
            $courses[] = [
                'course_id' => (int)$row['course_id'],
                'course_title' => $row['course_title'],
                'category' => $row['category'] ?? 'General',
                'status' => $row['status'],
                'status_badge' => $this->getStatusBadge($row['status']),
                'progress' => (int)$row['progress_percentage'],
                'score' => $row['final_score'] ? (int)$row['final_score'] : null,
                'enrolled_at' => $row['enrolled_at'],
                'started_at' => $row['started_at'],
                'completed_at' => $row['completed_at'],
                'due_date' => $row['due_date'],
                'time_spent' => $this->formatDuration((int)$row['time_spent_minutes']),
                'is_mandatory' => (bool)$row['is_mandatory']
            ];

            $totalTime += (int)$row['time_spent_minutes'];
            if ($row['status'] === 'completed') {
                $completed++;
                if ($row['final_score']) {
                    $totalScore += $row['final_score'];
                    $scoreCount++;
                }
            } elseif ($row['status'] === 'in_progress') {
                $inProgress++;
            } else {
                $notStarted++;
            }
        }

        // Certificates
        $certQuery = "SELECT cert.certificate_id, c.title as course_title,
                        cert.certificate_number, cert.issue_date, cert.expiry_date
                      FROM certificates cert
                      JOIN courses c ON cert.course_id = c.course_id
                      WHERE cert.user_id = ? AND cert.tenant_id = ?
                      ORDER BY cert.issue_date DESC";
        $stmt = $this->conn->prepare($certQuery);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $certificates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Badges
        $badgeQuery = "SELECT b.badge_id, b.badge_name, b.description, b.icon, ub.earned_at
                       FROM user_badges ub
                       JOIN badges b ON ub.badge_id = b.badge_id
                       WHERE ub.user_id = ? AND ub.tenant_id = ?
                       ORDER BY ub.earned_at DESC";
        $stmt = $this->conn->prepare($badgeQuery);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $badges = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Points
        $pointsQuery = "SELECT COALESCE(SUM(points), 0) as total_points FROM user_points WHERE user_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($pointsQuery);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $totalPoints = (int)$stmt->get_result()->fetch_assoc()['total_points'];

        $totalCourses = count($courses);
        return [
            'report_name' => 'Individual Progress Report',
            'user' => [
                'user_id' => (int)$user['id'],
                'full_name' => $user['full_name'],
                'email' => $user['email'],
                'job_title' => $user['job_title'],
                'department' => $user['department_name'] ?? 'N/A',
                'joined_date' => $user['joined_date']
            ],
            'summary' => [
                'total_courses' => $totalCourses,
                'completed' => $completed,
                'in_progress' => $inProgress,
                'not_started' => $notStarted,
                'completion_rate' => $totalCourses > 0 ? round(($completed / $totalCourses) * 100, 1) : 0,
                'average_score' => $scoreCount > 0 ? round($totalScore / $scoreCount, 1) : 0,
                'total_time_spent' => $this->formatDuration($totalTime),
                'total_certificates' => count($certificates),
                'total_badges' => count($badges),
                'total_points' => $totalPoints
            ],
            'courses' => $courses,
            'certificates' => $certificates,
            'badges' => $badges
        ];
    }

    /**
     * Report 8: Incomplete Courses Report
     */
    public function incompleteCoursesReport(): array
    {
        $query = "SELECT ce.enrollment_id, u.id as user_id,
                    CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, d.department_name, c.course_id, c.title as course_title,
                    ce.status, ce.progress_percentage, ce.enrolled_at, ce.due_date,
                    ce.last_activity_at,
                    DATEDIFF(CURDATE(), ce.due_date) as days_overdue,
                    CONCAT(mgr.first_name, ' ', mgr.last_name) as manager_name
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  JOIN courses c ON ce.course_id = c.course_id
                  LEFT JOIN departments d ON u.department = d.department_id
                  LEFT JOIN users mgr ON u.manager_id = mgr.id
                  WHERE ce.tenant_id = ? AND ce.is_active = 1 
                  AND ce.status IN ('not_started', 'in_progress')
                  ORDER BY days_overdue DESC, ce.progress_percentage ASC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $daysOverdue = $row['days_overdue'] !== null ? (int)$row['days_overdue'] : null;
            $data[] = [
                'enrollment_id' => (int)$row['enrollment_id'],
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'department' => $row['department_name'] ?? 'N/A',
                'course_id' => (int)$row['course_id'],
                'course_title' => $row['course_title'],
                'status' => $row['status'],
                'status_badge' => $this->getStatusBadge($row['status']),
                'progress' => (int)$row['progress_percentage'],
                'enrolled_at' => $row['enrolled_at'],
                'due_date' => $row['due_date'],
                'last_activity' => $row['last_activity_at'],
                'days_overdue' => $daysOverdue,
                'is_overdue' => $daysOverdue !== null && $daysOverdue > 0,
                'manager' => $row['manager_name'] ?? 'N/A'
            ];
        }

        $countQuery = "SELECT COUNT(*) as total FROM course_enrollments ce WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.status IN ('not_started', 'in_progress')";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'Incomplete Courses Report',
            'data' => $data,
            'meta' => $this->buildMeta($total)
        ];
    }

    /**
     * Report 9: Completion by Department
     */
    public function completionByDepartment(): array
    {
        $dateFilter = $this->buildDateFilter('ce.completed_at');

        $query = "SELECT d.department_id, d.department_name,
                    COUNT(DISTINCT u.id) as total_users,
                    COUNT(ce.enrollment_id) as total_enrollments,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN ce.status = 'not_started' THEN 1 ELSE 0 END) as not_started,
                    AVG(CASE WHEN ce.status = 'completed' THEN ce.final_score END) as avg_score,
                    SUM(ce.time_spent_minutes) as total_time
                  FROM departments d
                  LEFT JOIN users u ON d.department_id = u.department AND u.tenant_id = ? AND u.is_active = 1
                  LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.is_active = 1
                  WHERE d.tenant_id = ? $dateFilter
                  GROUP BY d.department_id, d.department_name
                  ORDER BY completed DESC
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
                'avg_score' => round((float)($row['avg_score'] ?? 0), 1),
                'total_time' => $this->formatDuration((int)$row['total_time'])
            ];
        }

        $countQuery = "SELECT COUNT(*) as total FROM departments WHERE tenant_id = ?";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'Completion by Department',
            'data' => $data,
            'meta' => $this->buildMeta($total)
        ];
    }

    /**
     * Report 10: Completion by Manager
     */
    public function completionByManager(): array
    {
        $query = "SELECT mgr.id as manager_id,
                    CONCAT(mgr.first_name, ' ', mgr.last_name) as manager_name,
                    mgr.email as manager_email,
                    COUNT(DISTINCT u.id) as team_size,
                    COUNT(ce.enrollment_id) as total_enrollments,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN ce.status = 'not_started' THEN 1 ELSE 0 END) as not_started,
                    SUM(CASE WHEN ce.due_date < CURDATE() AND ce.status != 'completed' THEN 1 ELSE 0 END) as overdue
                  FROM users mgr
                  JOIN users u ON u.manager_id = mgr.id AND u.is_active = 1
                  LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.is_active = 1
                  WHERE mgr.tenant_id = ? AND mgr.is_active = 1
                  GROUP BY mgr.id, mgr.first_name, mgr.last_name, mgr.email
                  HAVING team_size > 0
                  ORDER BY completed DESC
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
            $data[] = [
                'manager_id' => (int)$row['manager_id'],
                'manager_name' => $row['manager_name'],
                'manager_email' => $row['manager_email'],
                'team_size' => (int)$row['team_size'],
                'total_enrollments' => $enrolled,
                'completed' => $completed,
                'in_progress' => (int)$row['in_progress'],
                'not_started' => (int)$row['not_started'],
                'overdue' => (int)$row['overdue'],
                'completion_rate' => $enrolled > 0 ? round(($completed / $enrolled) * 100, 1) : 0
            ];
        }

        return [
            'report_name' => 'Completion by Manager',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 11: Time to Completion Analysis
     */
    public function timeToCompletionAnalysis(): array
    {
        $query = "SELECT c.course_id, c.title as course_title, c.duration_hours as expected_hours,
                    COUNT(ce.enrollment_id) as completions,
                    AVG(DATEDIFF(ce.completed_at, ce.enrolled_at)) as avg_days,
                    MIN(DATEDIFF(ce.completed_at, ce.enrolled_at)) as min_days,
                    MAX(DATEDIFF(ce.completed_at, ce.enrolled_at)) as max_days,
                    AVG(ce.time_spent_minutes) as avg_time_minutes,
                    MIN(ce.time_spent_minutes) as min_time_minutes,
                    MAX(ce.time_spent_minutes) as max_time_minutes
                  FROM courses c
                  JOIN course_enrollments ce ON c.course_id = ce.course_id
                  WHERE c.tenant_id = ? AND ce.is_active = 1 AND ce.status = 'completed'
                  AND ce.completed_at IS NOT NULL
                  GROUP BY c.course_id, c.title, c.duration_hours
                  ORDER BY completions DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'course_id' => (int)$row['course_id'],
                'course_title' => $row['course_title'],
                'expected_hours' => (float)$row['expected_hours'],
                'completions' => (int)$row['completions'],
                'avg_days_to_complete' => round((float)$row['avg_days'], 1),
                'fastest_completion_days' => (int)$row['min_days'],
                'slowest_completion_days' => (int)$row['max_days'],
                'avg_time_spent' => $this->formatDuration((int)$row['avg_time_minutes']),
                'min_time_spent' => $this->formatDuration((int)$row['min_time_minutes']),
                'max_time_spent' => $this->formatDuration((int)$row['max_time_minutes'])
            ];
        }

        return [
            'report_name' => 'Time to Completion Analysis',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 12: Overdue Training Report
     */
    public function overdueTrainingReport(): array
    {
        $query = "SELECT ce.enrollment_id, u.id as user_id,
                    CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, d.department_name, c.course_id, c.title as course_title,
                    ce.status, ce.progress_percentage, ce.due_date,
                    DATEDIFF(CURDATE(), ce.due_date) as days_overdue,
                    ce.is_mandatory,
                    CONCAT(mgr.first_name, ' ', mgr.last_name) as manager_name,
                    mgr.email as manager_email
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  JOIN courses c ON ce.course_id = c.course_id
                  LEFT JOIN departments d ON u.department = d.department_id
                  LEFT JOIN users mgr ON u.manager_id = mgr.id
                  WHERE ce.tenant_id = ? AND ce.is_active = 1 
                  AND ce.status != 'completed'
                  AND ce.due_date < CURDATE()
                  ORDER BY days_overdue DESC, ce.is_mandatory DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $daysOverdue = (int)$row['days_overdue'];
            $data[] = [
                'enrollment_id' => (int)$row['enrollment_id'],
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'department' => $row['department_name'] ?? 'N/A',
                'course_id' => (int)$row['course_id'],
                'course_title' => $row['course_title'],
                'status' => $row['status'],
                'progress' => (int)$row['progress_percentage'],
                'due_date' => $row['due_date'],
                'days_overdue' => $daysOverdue,
                'is_mandatory' => (bool)$row['is_mandatory'],
                'urgency' => $daysOverdue > 30 ? 'critical' : ($daysOverdue > 7 ? 'high' : 'medium'),
                'manager' => $row['manager_name'] ?? 'N/A',
                'manager_email' => $row['manager_email']
            ];
        }

        $countQuery = "SELECT COUNT(*) as total FROM course_enrollments ce WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.status != 'completed' AND ce.due_date < CURDATE()";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'Overdue Training Report',
            'data' => $data,
            'meta' => $this->buildMeta($total),
            'summary' => [
                'total_overdue' => $total,
                'critical' => count(array_filter($data, fn($d) => $d['urgency'] === 'critical')),
                'high' => count(array_filter($data, fn($d) => $d['urgency'] === 'high')),
                'medium' => count(array_filter($data, fn($d) => $d['urgency'] === 'medium'))
            ]
        ];
    }

    /**
     * Get completion summary statistics
     */
    private function getCompletionSummaryStats(): array
    {
        $query = "SELECT 
                    COUNT(*) as total_enrollments,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as total_completed,
                    AVG(CASE WHEN status = 'completed' THEN final_score END) as avg_score,
                    SUM(time_spent_minutes) as total_time,
                    AVG(CASE WHEN status = 'completed' THEN DATEDIFF(completed_at, enrolled_at) END) as avg_completion_days
                  FROM course_enrollments
                  WHERE tenant_id = ? AND is_active = 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        $total = (int)$row['total_enrollments'];
        $completed = (int)$row['total_completed'];

        return [
            'total_enrollments' => $total,
            'total_completed' => $completed,
            'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0,
            'average_score' => round((float)($row['avg_score'] ?? 0), 1),
            'total_learning_time' => $this->formatDuration((int)$row['total_time']),
            'avg_completion_days' => round((float)($row['avg_completion_days'] ?? 0), 1)
        ];
    }

    public function getSummary(): array
    {
        return $this->getCompletionSummaryStats();
    }
}
