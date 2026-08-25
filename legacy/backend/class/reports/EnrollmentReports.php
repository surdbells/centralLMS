<?php
require_once __DIR__ . '/BaseReport.php';
/**
 * Enrollment Reports
 * Reports related to course enrollments and participation
 */
class EnrollmentReports extends BaseReport
{

    public function generate(): array
    {
        return $this->courseEnrollmentSummary();
    }

    /**
     * Report 1: Course Enrollment Summary
     */
    public function courseEnrollmentSummary(): array
    {
        $dateFilter = $this->buildDateFilter('ce.enrolled_at');

        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT c.course_id, c.title as course_title, cat.name as category,
                    COUNT(ce.enrollment_id) as total_enrolled,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN ce.status = 'not_started' THEN 1 ELSE 0 END) as not_started,
                    ROUND(AVG(ce.progress_percentage), 1) as avg_progress,
                    MIN(ce.enrolled_at) as first_enrollment,
                    MAX(ce.enrolled_at) as last_enrollment
                  FROM courses c
                  LEFT JOIN course_enrollments ce ON c.course_id = ce.course_id AND ce.is_active = 1
                  LEFT JOIN categories cat ON c.category_id = cat.category_id
                  JOIN users u ON ce.user_id = u.id
                  WHERE c.tenant_id = ? AND c.is_active = 1 $dateFilter
                  {$orgFilter}
                  GROUP BY c.course_id, c.title, cat.name
                  ORDER BY total_enrolled DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $total = (int)$row['total_enrolled'];
            $data[] = [
                'course_id' => (int)$row['course_id'],
                'course_title' => $row['course_title'],
                'category' => $row['category'] ?? 'Uncategorized',
                'total_enrolled' => $total,
                'completed' => (int)$row['completed'],
                'in_progress' => (int)$row['in_progress'],
                'not_started' => (int)$row['not_started'],
                'completion_rate' => $total > 0 ? round(($row['completed'] / $total) * 100, 1) : 0,
                'avg_progress' => (float)$row['avg_progress'],
                'first_enrollment' => $row['first_enrollment'],
                'last_enrollment' => $row['last_enrollment']
            ];
        }

        // Get total count
        $countQuery = "SELECT COUNT(DISTINCT c.course_id) as total FROM courses c WHERE c.tenant_id = ? AND c.is_active = 1";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'Course Enrollment Summary',
            'data' => $data,
            'meta' => $this->buildMeta($total),
            'summary' => $this->getEnrollmentSummaryStats()
        ];
    }

    /**
     * Report 2: Enrollment Trends
     */
    public function enrollmentTrends(string $period = 'daily'): array
    {
        $groupBy = match ($period) {
            'daily' => "DATE(ce.enrolled_at)",
            'weekly' => "YEARWEEK(ce.enrolled_at)",
            'monthly' => "DATE_FORMAT(ce.enrolled_at, '%Y-%m')",
            default => "DATE(ce.enrolled_at)"
        };

        $selectDate = match ($period) {
            'daily' => "DATE(ce.enrolled_at) as period_date",
            'weekly' => "CONCAT(YEAR(ce.enrolled_at), '-W', LPAD(WEEK(ce.enrolled_at), 2, '0')) as period_date",
            'monthly' => "DATE_FORMAT(ce.enrolled_at, '%Y-%m') as period_date",
            default => "DATE(ce.enrolled_at) as period_date"
        };

        $dateFilter = $this->buildDateFilter('ce.enrolled_at');

        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT $selectDate,
                    COUNT(*) as enrollments,
                    COUNT(DISTINCT ce.user_id) as unique_users,
                    COUNT(DISTINCT ce.course_id) as courses_enrolled
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  WHERE ce.tenant_id = ? AND ce.is_active = 1 $dateFilter
                  {$orgFilter}
                  GROUP BY $groupBy
                  ORDER BY period_date DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'period' => $row['period_date'],
                'enrollments' => (int)$row['enrollments'],
                'unique_users' => (int)$row['unique_users'],
                'courses_enrolled' => (int)$row['courses_enrolled']
            ];
        }

        return [
            'report_name' => 'Enrollment Trends',
            'period' => $period,
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 3: Pending Enrollments
     */
    public function pendingEnrollments(): array
    {
        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT ce.enrollment_id, u.id as user_id, 
                    CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, d.department_name, c.course_id, c.title as course_title,
                    ce.enrolled_at, ce.due_date,
                    DATEDIFF(CURDATE(), ce.enrolled_at) as days_pending,
                    CONCAT(assigner.first_name, ' ', assigner.last_name) as assigned_by
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  JOIN courses c ON ce.course_id = c.course_id
                  LEFT JOIN departments d ON u.department = d.department_id
                  LEFT JOIN users assigner ON ce.assigned_by = assigner.id
                  WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.status = 'not_started'
                  {$orgFilter}
                  ORDER BY days_pending DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'enrollment_id' => (int)$row['enrollment_id'],
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'department' => $row['department_name'] ?? 'N/A',
                'course_id' => (int)$row['course_id'],
                'course_title' => $row['course_title'],
                'enrolled_at' => $row['enrolled_at'],
                'due_date' => $row['due_date'],
                'days_pending' => (int)$row['days_pending'],
                'assigned_by' => $row['assigned_by'] ?? 'Self-Enrolled'
            ];
        }

        $countQuery = "SELECT COUNT(*) as total FROM course_enrollments ce
                    JOIN users u ON ce.user_id = u.id
                    WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.status = 'not_started'
                    {$orgFilter}";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'Pending Enrollments',
            'data' => $data,
            'meta' => $this->buildMeta($total)
        ];
    }

    /**
     * Report 4: Self-Enrollment Report
     */
    public function selfEnrollmentReport(): array
    {
        $dateFilter = $this->buildDateFilter('ce.enrolled_at');

        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT c.course_id, c.title as course_title, cat.name as category,
                    COUNT(ce.enrollment_id) as self_enrollments,
                    COUNT(DISTINCT ce.user_id) as unique_users,
                    COUNT(DISTINCT d.department_id) as departments_represented
                  FROM course_enrollments ce
                  JOIN courses c ON ce.course_id = c.course_id
                  JOIN users u ON ce.user_id = u.id
                  LEFT JOIN categories cat ON c.category_id = cat.category_id
                  LEFT JOIN departments d ON u.department = d.department_id
                  WHERE ce.tenant_id = ? AND ce.is_active = 1 
                  AND ce.assigned_by IS NULL $dateFilter
                  {$orgFilter}
                  GROUP BY c.course_id, c.title, cat.name
                  ORDER BY self_enrollments DESC
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
                'category' => $row['category'] ?? 'Uncategorized',
                'self_enrollments' => (int)$row['self_enrollments'],
                'unique_users' => (int)$row['unique_users'],
                'departments_represented' => (int)$row['departments_represented']
            ];
        }

        return [
            'report_name' => 'Self-Enrollment Report',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 5: Waitlist Report
     */
    public function waitlistReport(): array
    {
        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT c.course_id, c.title as course_title, c.enrollment_limit,
                    COUNT(DISTINCT ce.user_id) as current_enrolled,
                    COUNT(DISTINCT wl.user_id) as waitlist_count,
                    MIN(wl.created_at) as oldest_waitlist,
                    AVG(DATEDIFF(CURDATE(), wl.created_at)) as avg_wait_days
                  FROM courses c
                  LEFT JOIN course_enrollments ce ON c.course_id = ce.course_id AND ce.is_active = 1
                  LEFT JOIN course_waitlist wl ON c.course_id = wl.course_id AND wl.status = 'waiting'
                  JOIN users u ON ce.user_id = u.id
                  WHERE c.tenant_id = ? AND c.is_active = 1 AND c.enrollment_limit IS NOT NULL
                  {$orgFilter}
                  GROUP BY c.course_id, c.title, c.enrollment_limit
                  HAVING waitlist_count > 0
                  ORDER BY waitlist_count DESC
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
                'max_capacity' => (int)$row['max_capacity'],
                'current_enrolled' => (int)$row['current_enrolled'],
                'available_spots' => max(0, (int)$row['max_capacity'] - (int)$row['current_enrolled']),
                'waitlist_count' => (int)$row['waitlist_count'],
                'oldest_waitlist' => $row['oldest_waitlist'],
                'avg_wait_days' => round((float)$row['avg_wait_days'], 1)
            ];
        }

        return [
            'report_name' => 'Waitlist Report',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Get enrollment summary statistics
     */
    private function getEnrollmentSummaryStats(): array
    {
        $query = "SELECT 
                    COUNT(*) as total_enrollments,
                    COUNT(DISTINCT user_id) as unique_learners,
                    COUNT(DISTINCT course_id) as courses_with_enrollments,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as total_completed,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as total_in_progress,
                    SUM(CASE WHEN status = 'not_started' THEN 1 ELSE 0 END) as total_not_started
                  FROM course_enrollments
                  WHERE tenant_id = ? AND is_active = 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        $total = (int)$row['total_enrollments'];
        return [
            'total_enrollments' => $total,
            'unique_learners' => (int)$row['unique_learners'],
            'courses_with_enrollments' => (int)$row['courses_with_enrollments'],
            'total_completed' => (int)$row['total_completed'],
            'total_in_progress' => (int)$row['total_in_progress'],
            'total_not_started' => (int)$row['total_not_started'],
            'overall_completion_rate' => $total > 0 ? round(($row['total_completed'] / $total) * 100, 1) : 0
        ];
    }


    public function enrollmentBySource(): array
    {
        $dateFilter = $this->buildDateFilter('ce.enrolled_at');
        $orgFilter = $this->buildOrgFilter();
        $orgJoins = $this->buildOrgJoins();

        $query = "SELECT ce.enrollment_source as source,
                    COUNT(*) as total_enrollments,
                    COUNT(DISTINCT ce.user_id) as unique_learners,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN ce.status = 'not_started' THEN 1 ELSE 0 END) as not_started,
                    ROUND(SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0) * 100, 1) as completion_rate
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  {$orgJoins}
                  WHERE ce.tenant_id = ? AND ce.is_active = 1 {$dateFilter} {$orgFilter}
                  GROUP BY ce.enrollment_source
                  ORDER BY total_enrollments DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        $grandTotal = 0;
        while ($row = $result->fetch_assoc()) {
            $total = (int)$row['total_enrollments'];
            $grandTotal += $total;
            $data[] = [
                'source' => $row['source'] ?? 'unknown',
                'total_enrollments' => $total,
                'unique_learners' => (int)$row['unique_learners'],
                'completed' => (int)$row['completed'],
                'in_progress' => (int)$row['in_progress'],
                'not_started' => (int)$row['not_started'],
                'completion_rate' => round((float)($row['completion_rate'] ?? 0), 1)
            ];
        }
        $stmt->close();

        foreach ($data as &$item) {
            $item['percentage'] = $grandTotal > 0 ? round(($item['total_enrollments'] / $grandTotal) * 100, 1) : 0;
        }

        $countQuery = "SELECT COUNT(DISTINCT ce.enrollment_source) as total
                       FROM course_enrollments ce
                       JOIN users u ON ce.user_id = u.id
                       {$orgJoins}
                       WHERE ce.tenant_id = ? AND ce.is_active = 1 {$dateFilter} {$orgFilter}";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'Enrollment by Source',
            'data' => $data,
            'meta' => $this->buildMeta($total),
            'summary' => [
                'total_enrollments' => $grandTotal,
                'total_sources' => count($data)
            ]
        ];
    }

    /**
     * Report: Drop-off Report — learners who enrolled but never started
     */
    public function dropOffReport(): array
    {
        $dateFilter = $this->buildDateFilter('ce.enrolled_at');
        $orgFilter = $this->buildOrgFilter();
        $orgJoins = $this->buildOrgJoins('u', true);
        $orgCols = $this->buildOrgSelectColumns();

        $query = "SELECT u.id as user_id, CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email {$orgCols},
                    c.title as course_title, ce.enrolled_at,
                    ce.enrollment_source as source,
                    DATEDIFF(NOW(), ce.enrolled_at) as days_since_enrollment,
                    CASE 
                        WHEN DATEDIFF(NOW(), ce.enrolled_at) > 90 THEN 'Critical'
                        WHEN DATEDIFF(NOW(), ce.enrolled_at) > 30 THEN 'High'
                        ELSE 'Medium'
                    END as risk_level
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  JOIN courses c ON ce.course_id = c.course_id
                  {$orgJoins}
                  WHERE ce.status = 'not_started' AND ce.tenant_id = ? AND ce.is_active = 1 {$dateFilter} {$orgFilter}
                  ORDER BY days_since_enrollment DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'department_name' => $row['department_name'] ?? null,
                'team_name' => $row['team_name'] ?? null,
                'branch_name' => $row['branch_name'] ?? null,
                'course_title' => $row['course_title'],
                'enrolled_at' => $row['enrolled_at'],
                'source' => $row['source'] ?? 'unknown',
                'days_since_enrollment' => (int)$row['days_since_enrollment'],
                'risk_level' => $row['risk_level']
            ];
        }
        $stmt->close();

        $countQuery = "SELECT COUNT(*) as total FROM course_enrollments ce
                       JOIN users u ON ce.user_id = u.id
                       {$orgJoins}
                       WHERE ce.status = 'not_started' AND ce.tenant_id = ? AND ce.is_active = 1 {$dateFilter} {$orgFilter}";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'Drop-off Report',
            'data' => $data,
            'meta' => $this->buildMeta($total),
            'summary' => [
                'total_dropoffs' => $total,
                'critical_count' => count(array_filter($data, fn($r) => $r['risk_level'] === 'Critical')),
                'high_count' => count(array_filter($data, fn($r) => $r['risk_level'] === 'High'))
            ]
        ];
    }

    public function getSummary(): array
    {
        return $this->getEnrollmentSummaryStats();
    }
}