<?php
require_once __DIR__ . '/BaseReport.php';
class ComplianceReports extends BaseReport
{

    public function generate(): array
    {
        return $this->complianceStatusDashboard();
    }

    /**
     * Report 13: Compliance Status Dashboard
     */
    public function complianceStatusDashboard(): array
    {
        // Overall compliance stats
        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT 
                    COUNT(DISTINCT ce.user_id) as total_users_with_mandatory,
                    COUNT(ce.enrollment_id) as total_mandatory_enrollments,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status != 'completed' AND (ce.due_date IS NULL OR ce.due_date >= CURDATE()) THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.is_mandatory = 1
                    {$orgFilter}";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();

        $total = (int)$stats['total_mandatory_enrollments'];
        $completed = (int)$stats['completed'];
        $pending = (int)$stats['pending'];
        $overdue = (int)$stats['overdue'];

        // Compliance by course
        $courseQuery = "SELECT c.course_id, c.title,
                          COUNT(ce.enrollment_id) as assigned,
                          SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                          SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue
                        FROM courses c
                        JOIN course_enrollments ce ON c.course_id = ce.course_id
                        JOIN users u ON ce.user_id = u.id
                        WHERE c.tenant_id = ? AND ce.is_active = 1 AND ce.is_mandatory = 1
                        {$orgFilter}
                        GROUP BY c.course_id, c.title
                        ORDER BY overdue DESC, completed ASC
                        LIMIT 10";

        $stmt = $this->conn->prepare($courseQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $courseData = [];
        foreach ($courses as $c) {
            $assigned = (int)$c['assigned'];
            $courseData[] = [
                'course_id' => (int)$c['course_id'],
                'title' => $c['title'],
                'assigned' => $assigned,
                'completed' => (int)$c['completed'],
                'overdue' => (int)$c['overdue'],
                'compliance_rate' => $assigned > 0 ? round(((int)$c['completed'] / $assigned) * 100, 1) : 0
            ];
        }

        // Compliance by department
        $deptQuery = "SELECT d.department_name,
                        COUNT(ce.enrollment_id) as assigned,
                        SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed
                      FROM departments d
                      JOIN users u ON d.department_id = u.department
                      JOIN course_enrollments ce ON u.id = ce.user_id
                      WHERE d.tenant_id = ? AND ce.is_active = 1 AND ce.is_mandatory = 1
                      {$orgFilter}
                      GROUP BY d.department_id, d.department_name
                      ORDER BY completed DESC";

        $stmt = $this->conn->prepare($deptQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $departments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $deptData = [];
        foreach ($departments as $d) {
            $assigned = (int)$d['assigned'];
            $deptData[] = [
                'department' => $d['department_name'],
                'assigned' => $assigned,
                'completed' => (int)$d['completed'],
                'compliance_rate' => $assigned > 0 ? round(((int)$d['completed'] / $assigned) * 100, 1) : 0
            ];
        }

        return [
            'report_name' => 'Compliance Status Dashboard',
            'summary' => [
                'total_users' => (int)$stats['total_users_with_mandatory'],
                'total_mandatory' => $total,
                'completed' => $completed,
                'pending' => $pending,
                'overdue' => $overdue,
                'compliance_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : 100
            ],
            'by_course' => $courseData,
            'by_department' => $deptData
        ];
    }

    /**
     * Report 14: Mandatory Training Compliance
     */
    public function mandatoryTrainingCompliance(): array
    {
        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT u.id as user_id, CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, d.department_name, u.job_title,
                    COUNT(ce.enrollment_id) as total_required,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status != 'completed' AND (ce.due_date IS NULL OR ce.due_date >= CURDATE()) THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
                    MIN(ce.due_date) as nearest_due_date
                  FROM users u
                  JOIN course_enrollments ce ON u.id = ce.user_id
                  LEFT JOIN departments d ON u.department = d.department_id
                  WHERE u.tenant_id = ? AND u.is_active = 1 AND ce.is_active = 1 AND ce.is_mandatory = 1
                  {$orgFilter}
                  GROUP BY u.id, u.first_name, u.last_name, u.email, d.department_name, u.job_title
                  ORDER BY overdue DESC, pending DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $required = (int)$row['total_required'];
            $completed = (int)$row['completed'];
            $overdue = (int)$row['overdue'];

            $status = 'compliant';
            if ($overdue > 0) $status = 'non_compliant';
            elseif ($completed < $required) $status = 'pending';

            $data[] = [
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'department' => $row['department_name'] ?? 'N/A',
                'job_title' => $row['job_title'] ?? 'N/A',
                'total_required' => $required,
                'completed' => $completed,
                'pending' => (int)$row['pending'],
                'overdue' => $overdue,
                'compliance_rate' => $required > 0 ? round(($completed / $required) * 100, 1) : 100,
                'status' => $status,
                'status_badge' => $this->getStatusBadge($status),
                'nearest_due_date' => $row['nearest_due_date']
            ];
        }

        $countQuery = "SELECT COUNT(DISTINCT u.id) as total FROM users u 
                       JOIN course_enrollments ce ON u.id = ce.user_id 
                       WHERE u.tenant_id = ? AND u.is_active = 1 AND ce.is_active = 1 AND ce.is_mandatory = 1
                    {$orgFilter}";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'Mandatory Training Compliance',
            'data' => $data,
            'meta' => $this->buildMeta($total)
        ];
    }

    /**
     * Report 15: Certification Expiry Report
     */
    public function certificationExpiryReport(int $days_ahead = 90): array
    {
        $orgFilter = $this->buildOrgFilter('u', 'cert');

        $query = "SELECT cert.certificate_id, cert.certificate_number,
                    u.id as user_id, CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, d.department_name,
                    c.course_id, c.title as course_title,
                    cert.issue_date, cert.expiry_date,
                    DATEDIFF(cert.expiry_date, CURDATE()) as days_until_expiry
                  FROM certificates cert
                  JOIN users u ON cert.user_id = u.id
                  JOIN courses c ON cert.course_id = c.course_id
                  LEFT JOIN departments d ON u.department = d.department_id
                  WHERE cert.tenant_id = ? 
                  AND cert.expiry_date IS NOT NULL
                  AND cert.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                  {$orgFilter}
                  ORDER BY cert.expiry_date ASC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iiii", $this->tenant_id, $days_ahead, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $daysUntil = (int)$row['days_until_expiry'];
            $status = 'valid';
            if ($daysUntil < 0) $status = 'expired';
            elseif ($daysUntil <= 30) $status = 'expiring_soon';

            $data[] = [
                'certificate_id' => (int)$row['certificate_id'],
                'certificate_number' => $row['certificate_number'],
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'department' => $row['department_name'] ?? 'N/A',
                'course_id' => (int)$row['course_id'],
                'course_title' => $row['course_title'],
                'issued_at' => $row['issued_at'],
                'expiry_date' => $row['expiry_date'],
                'days_until_expiry' => $daysUntil,
                'status' => $status,
                'status_badge' => $this->getStatusBadge($status)
            ];
        }

        $countQuery = "SELECT COUNT(*) as total FROM certificates WHERE tenant_id = ? AND expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)";
        $stmt = $this->conn->prepare($countQuery);
        $stmt->bind_param("ii", $this->tenant_id, $days_ahead);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()['total'];

        return [
            'report_name' => 'Certification Expiry Report',
            'days_ahead' => $days_ahead,
            'data' => $data,
            'meta' => $this->buildMeta($total),
            'summary' => [
                'expired' => count(array_filter($data, fn($d) => $d['status'] === 'expired')),
                'expiring_soon' => count(array_filter($data, fn($d) => $d['status'] === 'expiring_soon')),
                'valid' => count(array_filter($data, fn($d) => $d['status'] === 'valid'))
            ]
        ];
    }

    /**
     * Report 16: Recertification Due Report
     */
    public function recertificationDueReport(): array
    {
        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT u.id as user_id, CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, d.department_name,
                    c.course_id, c.title as course_title,
                    cert.issue_date as last_completed,
                    cert.expiry_date,
                    DATEDIFF(cert.expiry_date, CURDATE()) as days_until_due,
                    CASE 
                      WHEN cert.expiry_date < CURDATE() THEN 'overdue'
                      WHEN cert.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'due_soon'
                      ELSE 'upcoming'
                    END as urgency
                  FROM certificates cert
                  JOIN users u ON cert.user_id = u.id
                  JOIN courses c ON cert.course_id = c.course_id
                  LEFT JOIN departments d ON u.department = d.department_id
                  WHERE cert.tenant_id = ? 
                  AND cert.expiry_date IS NOT NULL
                  AND cert.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
                  AND NOT EXISTS (
                    SELECT 1 FROM course_enrollments ce 
                    WHERE ce.user_id = u.id AND ce.course_id = c.course_id 
                    AND ce.status = 'in_progress' AND ce.is_active = 1
                  )
                  {$orgFilter}
                  ORDER BY cert.expiry_date ASC
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
                'department' => $row['department_name'] ?? 'N/A',
                'course_id' => (int)$row['course_id'],
                'course_title' => $row['course_title'],
                'last_completed' => $row['last_completed'],
                'expiry_date' => $row['expiry_date'],
                'days_until_due' => (int)$row['days_until_due'],
                'urgency' => $row['urgency']
            ];
        }

        return [
            'report_name' => 'Recertification Due Report',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 17: Compliance by Department
     */
    public function complianceByDepartment(): array
    {
        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT d.department_id, d.department_name,
                    COUNT(DISTINCT u.id) as total_users,
                    COUNT(ce.enrollment_id) as mandatory_assigned,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
                    COUNT(DISTINCT CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN u.id END) as users_non_compliant
                  FROM departments d
                  LEFT JOIN users u ON d.department_id = u.department AND u.tenant_id = ? AND u.is_active = 1
                  LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.is_active = 1 AND ce.is_mandatory = 1
                  WHERE d.tenant_id = ?
                  {$orgFilter}
                  GROUP BY d.department_id, d.department_name
                  ORDER BY overdue DESC, completed ASC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iiii", $this->tenant_id, $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $assigned = (int)$row['mandatory_assigned'];
            $completed = (int)$row['completed'];
            $totalUsers = (int)$row['total_users'];
            $nonCompliant = (int)$row['users_non_compliant'];

            $data[] = [
                'department_id' => $row['department'],
                'department_name' => $row['department_name'],
                'total_users' => $totalUsers,
                'mandatory_assigned' => $assigned,
                'completed' => $completed,
                'overdue' => (int)$row['overdue'],
                'compliance_rate' => $assigned > 0 ? round(($completed / $assigned) * 100, 1) : 100,
                'users_non_compliant' => $nonCompliant,
                'users_compliant' => $totalUsers - $nonCompliant
            ];
        }

        $countQuery = "SELECT COUNT(*) as total FROM departments WHERE tenant_id = ?";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'Compliance by Department',
            'data' => $data,
            'meta' => $this->buildMeta($total)
        ];
    }

    /**
     * Report 18: Regulatory Training Audit
     */
    public function regulatoryTrainingAudit(): array
    {
        $dateFilter = $this->buildDateFilter('ce.completed_at');

        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT ce.enrollment_id, u.id as user_id,
                    CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, u.staffId, d.department_name,
                    c.course_id, c.title as course_title,
                    ce.enrolled_at, ce.completed_at, ce.final_score,
                    cert.certificate_number, cert.issue_date, cert.expiry_date,
                    ce.time_spent_minutes
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  JOIN courses c ON ce.course_id = c.course_id
                  LEFT JOIN departments d ON u.department = d.department_id
                  LEFT JOIN certificates cert ON cert.user_id = u.id AND cert.course_id = c.course_id
                  WHERE ce.tenant_id = ? AND ce.is_active = 1 
                  AND ce.is_mandatory = 1 AND ce.status = 'completed' $dateFilter
                  {$orgFilter}
                  ORDER BY ce.completed_at DESC
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
                'employee_id' => $row['employee_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'department' => $row['department_name'] ?? 'N/A',
                'course_id' => (int)$row['course_id'],
                'course_title' => $row['course_title'],
                'enrolled_at' => $row['enrolled_at'],
                'completed_at' => $row['completed_at'],
                'final_score' => (int)$row['final_score'],
                'certificate_number' => $row['certificate_number'],
                'certificate_issued' => $row['issued_at'],
                'certificate_expiry' => $row['expiry_date'],
                'time_spent' => $this->formatDuration((int)$row['time_spent_minutes'])
            ];
        }

        return [
            'report_name' => 'Regulatory Training Audit',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 19: Non-Compliant Users Report
     */
    public function nonCompliantUsersReport(): array
    {
        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT u.id as user_id, CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, u.staffId, d.department_name, u.job_title,
                    CONCAT(mgr.first_name, ' ', mgr.last_name) as manager_name,
                    mgr.email as manager_email,
                    COUNT(ce.enrollment_id) as overdue_count,
                    GROUP_CONCAT(c.title SEPARATOR ', ') as overdue_courses,
                    MIN(ce.due_date) as oldest_due_date,
                    MAX(DATEDIFF(CURDATE(), ce.due_date)) as max_days_overdue
                  FROM users u
                  JOIN course_enrollments ce ON u.id = ce.user_id
                  JOIN courses c ON ce.course_id = c.course_id
                  LEFT JOIN departments d ON u.department = d.department_id
                  LEFT JOIN users mgr ON u.manager_id = mgr.id
                  WHERE u.tenant_id = ? AND u.is_active = 1 
                  AND ce.is_active = 1 AND ce.is_mandatory = 1
                  AND ce.status != 'completed' AND ce.due_date < CURDATE()
                  {$orgFilter}
                  GROUP BY u.id, u.first_name, u.last_name, u.email, u.staffId, 
                           d.department_name, u.job_title, mgr.first_name, mgr.last_name, mgr.email
                  ORDER BY max_days_overdue DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $maxDaysOverdue = (int)$row['max_days_overdue'];
            $riskLevel = 'low';
            if ($maxDaysOverdue > 60) $riskLevel = 'critical';
            elseif ($maxDaysOverdue > 30) $riskLevel = 'high';
            elseif ($maxDaysOverdue > 7) $riskLevel = 'medium';

            $data[] = [
                'user_id' => (int)$row['user_id'],
                'employee_id' => $row['employee_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'department' => $row['department_name'] ?? 'N/A',
                'job_title' => $row['job_title'] ?? 'N/A',
                'manager' => $row['manager_name'] ?? 'N/A',
                'manager_email' => $row['manager_email'],
                'overdue_count' => (int)$row['overdue_count'],
                'overdue_courses' => $row['overdue_courses'],
                'oldest_due_date' => $row['oldest_due_date'],
                'max_days_overdue' => $maxDaysOverdue,
                'risk_level' => $riskLevel
            ];
        }

        return [
            'report_name' => 'Non-Compliant Users Report',
            'data' => $data,
            'meta' => $this->buildMeta(count($data)),
            'summary' => [
                'total_non_compliant' => count($data),
                'critical_risk' => count(array_filter($data, fn($d) => $d['risk_level'] === 'critical')),
                'high_risk' => count(array_filter($data, fn($d) => $d['risk_level'] === 'high')),
                'medium_risk' => count(array_filter($data, fn($d) => $d['risk_level'] === 'medium')),
                'low_risk' => count(array_filter($data, fn($d) => $d['risk_level'] === 'low'))
            ]
        ];
    }

    /**
     * Report: Compliance Gap Analysis
     * Shows mandatory training coverage per department with severity levels
     */
    public function complianceGapAnalysis(): array
    {
        $orgFilter = $this->buildOrgFilter();
        $orgJoins = $this->buildOrgJoins();

        $query = "SELECT d.department_id, d.department_name,
                    COUNT(DISTINCT u.id) as total_users,
                    COUNT(DISTINCT ce.course_id) as mandatory_courses_assigned,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
                    COUNT(ce.enrollment_id) as total_mandatory_enrollments,
                    ROUND(
                        SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) /
                        NULLIF(COUNT(ce.enrollment_id), 0) * 100, 1
                    ) as coverage_rate
                  FROM departments d
                  LEFT JOIN users u ON d.department_id = u.department AND u.tenant_id = ? AND u.is_active = 1
                  LEFT JOIN course_enrollments ce ON ce.user_id = u.id AND ce.is_mandatory = 1 AND ce.is_active = 1
                  WHERE d.tenant_id = ? AND d.is_active = 1
                  GROUP BY d.department_id, d.department_name
                  ORDER BY coverage_rate ASC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iiii", $this->tenant_id, $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $totalEnrollments = (int)$row['total_mandatory_enrollments'];
            $completed = (int)$row['completed'];
            $coverageRate = round((float)($row['coverage_rate'] ?? 0), 1);
            $data[] = [
                'department_id' => (int)$row['department_id'],
                'department_name' => $row['department_name'],
                'total_users' => (int)$row['total_users'],
                'mandatory_courses_assigned' => (int)$row['mandatory_courses_assigned'],
                'completed' => $completed,
                'overdue' => (int)$row['overdue'],
                'gap_count' => $totalEnrollments - $completed,
                'coverage_rate' => $coverageRate,
                'severity' => $coverageRate < 50 ? 'Critical'
                    : ($coverageRate < 80 ? 'High'
                        : ($coverageRate < 95 ? 'Medium' : 'Low'))
            ];
        }
        $stmt->close();

        $countQuery = "SELECT COUNT(*) as total FROM departments WHERE tenant_id = ? AND is_active = 1";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'Compliance Gap Analysis',
            'data' => $data,
            'meta' => $this->buildMeta($total),
            'summary' => [
                'total_departments' => count($data),
                'total_gaps' => array_sum(array_column($data, 'gap_count')),
                'critical_departments' => count(array_filter($data, fn($r) => $r['severity'] === 'Critical')),
                'overall_coverage_rate' => count($data) > 0 ? round(array_sum(array_column($data, 'coverage_rate')) / count($data), 1) : 0
            ]
        ];
    }

    // =========================================================================
    // Phase 3: Executive Compliance Drilldown
    // =========================================================================

    /**
     * Executive Compliance Summary — Hierarchical drilldown report
     *
     * Returns org-wide summary + breakdowns by branch, department, team,
     * course, and a non-compliant learners list. Fully filterable by all
     * org-unit dimensions from BaseReport.
     *
     * Usage: action=run&report=compliance_executive_summary
     *   Optional: &department_id=5&branch_id=2&team_id=3&course_id=10
     *             &date_from=2025-01-01&date_to=2025-12-31
     */
    public function complianceExecutiveSummary(): array
    {
        $orgFilter = $this->buildOrgFilter();
        $orgJoins = $this->buildOrgJoins('u', true);
        $courseFilter = $this->course_id
            ? " AND ce.course_id = " . (int)$this->course_id
            : "";

        // ── 1. Org-wide summary ──────────────────────────────────────────
        $summaryQuery = "SELECT
                    COUNT(DISTINCT ce.user_id) as total_users,
                    COUNT(ce.enrollment_id) as total_mandatory,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status != 'completed' AND (ce.due_date IS NULL OR ce.due_date >= CURDATE()) THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  {$orgJoins}
                  WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.is_mandatory = 1
                  {$courseFilter} {$orgFilter}";

        $stmt = $this->conn->prepare($summaryQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $s = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $totalMandatory = (int)$s['total_mandatory'];
        $totalCompleted = (int)$s['completed'];

        $orgSummary = [
            'total_users' => (int)$s['total_users'],
            'total_mandatory' => $totalMandatory,
            'completed' => $totalCompleted,
            'pending' => (int)$s['pending'],
            'overdue' => (int)$s['overdue'],
            'compliance_rate' => $totalMandatory > 0 ? round(($totalCompleted / $totalMandatory) * 100, 1) : 100
        ];

        // ── 2. By Branch ─────────────────────────────────────────────────
        $branchQuery = "SELECT b.branch_id, b.branch_name,
                    COUNT(DISTINCT u.id) as total_users,
                    COUNT(ce.enrollment_id) as assigned,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
                    COUNT(DISTINCT CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN u.id END) as non_compliant_count
                  FROM branches b
                  LEFT JOIN users u ON b.branch_id = u.branch AND u.tenant_id = ? AND u.is_active = 1
                  LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.is_active = 1 AND ce.is_mandatory = 1
                    {$courseFilter}
                  WHERE b.tenant_id = ? AND b.is_active = 1
                  GROUP BY b.branch_id, b.branch_name
                  ORDER BY overdue DESC, completed ASC";

        $stmt = $this->conn->prepare($branchQuery);
        $stmt->bind_param("ii", $this->tenant_id, $this->tenant_id);
        $stmt->execute();
        $branchRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $byBranch = [];
        foreach ($branchRows as $row) {
            $assigned = (int)$row['assigned'];
            $completed = (int)$row['completed'];
            $byBranch[] = [
                'branch_id' => (int)$row['branch_id'],
                'branch_name' => $row['branch_name'],
                'total_users' => (int)$row['total_users'],
                'assigned' => $assigned,
                'completed' => $completed,
                'overdue' => (int)$row['overdue'],
                'non_compliant_count' => (int)$row['non_compliant_count'],
                'compliance_rate' => $assigned > 0 ? round(($completed / $assigned) * 100, 1) : 100
            ];
        }

        // ── 3. By Department (with branch context) ───────────────────────
        $deptQuery = "SELECT d.department_id, d.department_name,
                    b.branch_name,
                    COUNT(DISTINCT u.id) as total_users,
                    COUNT(ce.enrollment_id) as assigned,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
                    COUNT(DISTINCT CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN u.id END) as non_compliant_count
                  FROM departments d
                  LEFT JOIN users u ON d.department_id = u.department AND u.tenant_id = ? AND u.is_active = 1
                  LEFT JOIN branches b ON u.branch = b.branch_id
                  LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.is_active = 1 AND ce.is_mandatory = 1
                    {$courseFilter}
                  WHERE d.tenant_id = ? AND d.is_active = 1
                  GROUP BY d.department_id, d.department_name, b.branch_name
                  ORDER BY overdue DESC";

        $stmt = $this->conn->prepare($deptQuery);
        $stmt->bind_param("ii", $this->tenant_id, $this->tenant_id);
        $stmt->execute();
        $deptRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $byDepartment = [];
        foreach ($deptRows as $row) {
            $assigned = (int)$row['assigned'];
            $completed = (int)$row['completed'];
            $byDepartment[] = [
                'department_id' => (int)$row['department_id'],
                'department_name' => $row['department_name'],
                'branch_name' => $row['branch_name'] ?? 'Unassigned',
                'total_users' => (int)$row['total_users'],
                'assigned' => $assigned,
                'completed' => $completed,
                'overdue' => (int)$row['overdue'],
                'non_compliant_count' => (int)$row['non_compliant_count'],
                'compliance_rate' => $assigned > 0 ? round(($completed / $assigned) * 100, 1) : 100
            ];
        }

        // ── 4. By Team (with department context) ─────────────────────────
        $teamQuery = "SELECT t.team_id, t.team_name,
                    d.department_name,
                    COUNT(DISTINCT u.id) as total_users,
                    COUNT(ce.enrollment_id) as assigned,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
                    COUNT(DISTINCT CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN u.id END) as non_compliant_count
                  FROM teams t
                  LEFT JOIN users u ON t.team_id = u.team AND u.tenant_id = ? AND u.is_active = 1
                  LEFT JOIN departments d ON u.department = d.department_id
                  LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.is_active = 1 AND ce.is_mandatory = 1
                    {$courseFilter}
                  WHERE t.tenant_id = ? AND t.is_active = 1
                  GROUP BY t.team_id, t.team_name, d.department_name
                  ORDER BY overdue DESC";

        $stmt = $this->conn->prepare($teamQuery);
        $stmt->bind_param("ii", $this->tenant_id, $this->tenant_id);
        $stmt->execute();
        $teamRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $byTeam = [];
        foreach ($teamRows as $row) {
            $assigned = (int)$row['assigned'];
            $completed = (int)$row['completed'];
            $byTeam[] = [
                'team_id' => (int)$row['team_id'],
                'team_name' => $row['team_name'],
                'department_name' => $row['department_name'] ?? 'Unassigned',
                'total_users' => (int)$row['total_users'],
                'assigned' => $assigned,
                'completed' => $completed,
                'overdue' => (int)$row['overdue'],
                'non_compliant_count' => (int)$row['non_compliant_count'],
                'compliance_rate' => $assigned > 0 ? round(($completed / $assigned) * 100, 1) : 100
            ];
        }

        // ── 5. By Course (with worst department + worst branch) ──────────
        $courseQuery = "SELECT c.course_id, c.title as course_title,
                    COUNT(ce.enrollment_id) as total_assigned,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue
                  FROM courses c
                  JOIN course_enrollments ce ON c.course_id = ce.course_id
                  JOIN users u ON ce.user_id = u.id
                  {$orgJoins}
                  WHERE c.tenant_id = ? AND ce.is_active = 1 AND ce.is_mandatory = 1
                  {$orgFilter}
                  GROUP BY c.course_id, c.title
                  ORDER BY overdue DESC, completed ASC
                  LIMIT 20";

        $stmt = $this->conn->prepare($courseQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $courseRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $byCourse = [];
        foreach ($courseRows as $row) {
            $assigned = (int)$row['total_assigned'];
            $completed = (int)$row['completed'];
            $courseId = (int)$row['course_id'];

            // Find worst department for this course
            $worstDeptQuery = "SELECT *
                            FROM (
                                SELECT d.department_id,
                                       d.department_name,
                                       COUNT(ce.enrollment_id) AS dept_assigned,
                                       SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) AS dept_completed
                                FROM course_enrollments ce
                                JOIN users u ON ce.user_id = u.id
                                LEFT JOIN departments d ON u.department = d.department_id
                                WHERE ce.course_id = ?
                                  AND ce.tenant_id = ?
                                  AND ce.is_active = 1
                                  AND ce.is_mandatory = 1
                                GROUP BY d.department_id, d.department_name
                            ) t
                            WHERE t.dept_assigned > 0
                      ORDER BY (t.dept_completed / NULLIF(t.dept_assigned, 0)) ASC
                      LIMIT 1";
            $stmt2 = $this->conn->prepare($worstDeptQuery);
            $stmt2->bind_param("ii", $courseId, $this->tenant_id);
            $stmt2->execute();
            $worstDept = $stmt2->get_result()->fetch_assoc();
            $stmt2->close();

            // Find worst branch for this course
            $worstBranchQuery = "SELECT *
            FROM (
                SELECT b.branch_id,
                       b.branch_name,
                       COUNT(ce.enrollment_id) AS br_assigned,
                       SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) AS br_completed
                FROM course_enrollments ce
                JOIN users u ON ce.user_id = u.id
                LEFT JOIN branches b ON u.branch = b.branch_id
                WHERE ce.course_id = ?
                  AND ce.tenant_id = ?
                  AND ce.is_active = 1
                  AND ce.is_mandatory = 1
                GROUP BY b.branch_id, b.branch_name
            ) t
            WHERE t.br_assigned > 0
            ORDER BY (t.br_completed / NULLIF(t.br_assigned, 0)) ASC
            LIMIT 1";
            $stmt2 = $this->conn->prepare($worstBranchQuery);
            $stmt2->bind_param("ii", $courseId, $this->tenant_id);
            $stmt2->execute();
            $worstBranch = $stmt2->get_result()->fetch_assoc();
            $stmt2->close();

            $byCourse[] = [
                'course_id' => $courseId,
                'course_title' => $row['course_title'],
                'total_assigned' => $assigned,
                'completed' => $completed,
                'overdue' => (int)$row['overdue'],
                'compliance_rate' => $assigned > 0 ? round(($completed / $assigned) * 100, 1) : 100,
                'worst_department' => $worstDept['department_name'] ?? 'N/A',
                'worst_branch' => $worstBranch['branch_name'] ?? 'N/A'
            ];
        }

        // ── 6. Non-compliant learners (top 25) ──────────────────────────
        $learnerQuery = "SELECT u.id as user_id,
                    CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, u.staffId,
                    d.department_name, t.team_name, b.branch_name,
                    COUNT(ce.enrollment_id) as overdue_count,
                    GROUP_CONCAT(c.title ORDER BY ce.due_date ASC SEPARATOR ', ') as overdue_courses,
                    MIN(ce.due_date) as oldest_due_date,
                    MAX(DATEDIFF(CURDATE(), ce.due_date)) as max_days_overdue
                  FROM users u
                  JOIN course_enrollments ce ON u.id = ce.user_id
                  JOIN courses c ON ce.course_id = c.course_id
                  LEFT JOIN departments d ON u.department = d.department_id
                  LEFT JOIN teams t ON u.team = t.team_id
                  LEFT JOIN branches b ON u.branch = b.branch_id
                  WHERE u.tenant_id = ? AND u.is_active = 1
                    AND ce.is_active = 1 AND ce.is_mandatory = 1
                    AND ce.status != 'completed' AND ce.due_date < CURDATE()
                    {$courseFilter} {$orgFilter}
                  GROUP BY u.id, u.first_name, u.last_name, u.email, u.staffId,
                           d.department_name, t.team_name, b.branch_name
                  ORDER BY max_days_overdue DESC
                  LIMIT 25";

        $stmt = $this->conn->prepare($learnerQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $learnerRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $nonCompliantLearners = [];
        foreach ($learnerRows as $row) {
            $maxDays = (int)$row['max_days_overdue'];
            $nonCompliantLearners[] = [
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'staff_id' => $row['staffId'],
                'department' => $row['department_name'] ?? 'Unassigned',
                'team' => $row['team_name'] ?? 'Unassigned',
                'branch' => $row['branch_name'] ?? 'Unassigned',
                'overdue_count' => (int)$row['overdue_count'],
                'overdue_courses' => $row['overdue_courses'],
                'oldest_due_date' => $row['oldest_due_date'],
                'max_days_overdue' => $maxDays,
                'risk_level' => $maxDays > 60 ? 'critical'
                    : ($maxDays > 30 ? 'high'
                        : ($maxDays > 7 ? 'medium' : 'low'))
            ];
        }

        // ── Build response ───────────────────────────────────────────────
        return [
            'report_name' => 'Compliance Executive Summary',
            'generated_at' => date('Y-m-d H:i:s'),
            'applied_filters' => $this->buildMeta(0)['applied_filters'] ?? [],
            'org_summary' => $orgSummary,
            'by_branch' => $byBranch,
            'by_department' => $byDepartment,
            'by_team' => $byTeam,
            'by_course' => $byCourse,
            'non_compliant_learners' => $nonCompliantLearners,
            'risk_breakdown' => [
                'critical' => count(array_filter($nonCompliantLearners, fn($l) => $l['risk_level'] === 'critical')),
                'high' => count(array_filter($nonCompliantLearners, fn($l) => $l['risk_level'] === 'high')),
                'medium' => count(array_filter($nonCompliantLearners, fn($l) => $l['risk_level'] === 'medium')),
                'low' => count(array_filter($nonCompliantLearners, fn($l) => $l['risk_level'] === 'low'))
            ]
        ];
    }

    /**
     * Compliance by Branch — Paginated standalone report
     *
     * Usage: action=run&report=compliance_by_branch
     */
    public function complianceByBranch(): array
    {
        $orgFilter = $this->buildOrgFilter();
        $courseFilter = $this->course_id
            ? " AND ce.course_id = " . (int)$this->course_id
            : "";

        $query = "SELECT b.branch_id, b.branch_name, b.branch_code,
                    COUNT(DISTINCT u.id) as total_users,
                    COUNT(ce.enrollment_id) as mandatory_assigned,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN ce.status = 'not_started' THEN 1 ELSE 0 END) as not_started,
                    SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
                    COUNT(DISTINCT CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN u.id END) as users_non_compliant,
                    ROUND(AVG(CASE WHEN ce.status = 'completed' THEN ce.final_score END), 1) as avg_score
                  FROM branches b
                  LEFT JOIN users u ON b.branch_id = u.branch AND u.tenant_id = ? AND u.is_active = 1
                  LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.is_active = 1 AND ce.is_mandatory = 1
                    {$courseFilter}
                  WHERE b.tenant_id = ? AND b.is_active = 1
                  GROUP BY b.branch_id, b.branch_name, b.branch_code
                  ORDER BY overdue DESC, completed ASC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iiii", $this->tenant_id, $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $assigned = (int)$row['mandatory_assigned'];
            $completed = (int)$row['completed'];
            $totalUsers = (int)$row['total_users'];
            $nonCompliant = (int)$row['users_non_compliant'];

            $data[] = [
                'branch_id' => (int)$row['branch_id'],
                'branch_name' => $row['branch_name'],
                'branch_code' => $row['branch_code'],
                'total_users' => $totalUsers,
                'mandatory_assigned' => $assigned,
                'completed' => $completed,
                'in_progress' => (int)$row['in_progress'],
                'not_started' => (int)$row['not_started'],
                'overdue' => (int)$row['overdue'],
                'compliance_rate' => $assigned > 0 ? round(($completed / $assigned) * 100, 1) : 100,
                'users_non_compliant' => $nonCompliant,
                'users_compliant' => max(0, $totalUsers - $nonCompliant),
                'avg_score' => round((float)($row['avg_score'] ?? 0), 1)
            ];
        }
        $stmt->close();

        $countQuery = "SELECT COUNT(*) as total FROM branches WHERE tenant_id = ? AND is_active = 1";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'Compliance by Branch',
            'data' => $data,
            'meta' => $this->buildMeta($total),
            'summary' => [
                'total_branches' => count($data),
                'overall_compliance_rate' => count($data) > 0
                    ? round(array_sum(array_column($data, 'compliance_rate')) / count($data), 1) : 100,
                'total_non_compliant_users' => array_sum(array_column($data, 'users_non_compliant'))
            ]
        ];
    }

    /**
     * Compliance by Team — Paginated standalone report
     *
     * Usage: action=run&report=compliance_by_team
     *   Optional: &department_id=5&branch_id=2
     */
    public function complianceByTeam(): array
    {
        $orgFilter = $this->buildOrgFilter();
        $orgJoins = $this->buildOrgJoins('u', true);
        $courseFilter = $this->course_id
            ? " AND ce.course_id = " . (int)$this->course_id
            : "";

        $query = "SELECT t.team_id, t.team_name, t.team_code,
                    d.department_name, b.branch_name,
                    COUNT(DISTINCT u.id) as total_users,
                    COUNT(ce.enrollment_id) as mandatory_assigned,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN ce.status = 'not_started' THEN 1 ELSE 0 END) as not_started,
                    SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
                    COUNT(DISTINCT CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN u.id END) as users_non_compliant,
                    ROUND(AVG(CASE WHEN ce.status = 'completed' THEN ce.final_score END), 1) as avg_score
                  FROM teams t
                  LEFT JOIN users u ON t.team_id = u.team AND u.tenant_id = ? AND u.is_active = 1
                  LEFT JOIN departments d ON u.department = d.department_id
                  LEFT JOIN branches b ON u.branch = b.branch_id
                  LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.is_active = 1 AND ce.is_mandatory = 1
                    {$courseFilter}
                  WHERE t.tenant_id = ? AND t.is_active = 1 {$orgFilter}
                  GROUP BY t.team_id, t.team_name, t.team_code, d.department_name, b.branch_name
                  ORDER BY overdue DESC, completed ASC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iiii", $this->tenant_id, $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $assigned = (int)$row['mandatory_assigned'];
            $completed = (int)$row['completed'];
            $totalUsers = (int)$row['total_users'];
            $nonCompliant = (int)$row['users_non_compliant'];

            $data[] = [
                'team_id' => (int)$row['team_id'],
                'team_name' => $row['team_name'],
                'team_code' => $row['team_code'],
                'department_name' => $row['department_name'] ?? 'Unassigned',
                'branch_name' => $row['branch_name'] ?? 'Unassigned',
                'total_users' => $totalUsers,
                'mandatory_assigned' => $assigned,
                'completed' => $completed,
                'in_progress' => (int)$row['in_progress'],
                'not_started' => (int)$row['not_started'],
                'overdue' => (int)$row['overdue'],
                'compliance_rate' => $assigned > 0 ? round(($completed / $assigned) * 100, 1) : 100,
                'users_non_compliant' => $nonCompliant,
                'users_compliant' => max(0, $totalUsers - $nonCompliant),
                'avg_score' => round((float)($row['avg_score'] ?? 0), 1)
            ];
        }
        $stmt->close();

        $countQuery = "SELECT COUNT(*) as total FROM teams WHERE tenant_id = ? AND is_active = 1";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'Compliance by Team',
            'data' => $data,
            'meta' => $this->buildMeta($total),
            'summary' => [
                'total_teams' => count($data),
                'overall_compliance_rate' => count($data) > 0
                    ? round(array_sum(array_column($data, 'compliance_rate')) / count($data), 1) : 100,
                'total_non_compliant_users' => array_sum(array_column($data, 'users_non_compliant'))
            ]
        ];
    }

    public function getSummary(): array
    {
        $dashboard = $this->complianceStatusDashboard();
        return $dashboard['summary'];
    }
}
