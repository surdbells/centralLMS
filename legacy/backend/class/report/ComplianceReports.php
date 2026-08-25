<?php

namespace report;

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
        $query = "SELECT 
                    COUNT(DISTINCT ce.user_id) as total_users_with_mandatory,
                    COUNT(ce.enrollment_id) as total_mandatory_enrollments,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status != 'completed' AND (ce.due_date IS NULL OR ce.due_date >= CURDATE()) THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue
                  FROM course_enrollments ce
                  WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.is_mandatory = 1";

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
                        WHERE c.tenant_id = ? AND ce.is_active = 1 AND ce.is_mandatory = 1
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
                       WHERE u.tenant_id = ? AND u.is_active = 1 AND ce.is_active = 1 AND ce.is_mandatory = 1";
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

        $query = "SELECT ce.enrollment_id, u.id as user_id,
                    CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, u.employee_id, d.department_name,
                    c.course_id, c.title as course_title,
                    ce.enrolled_at, ce.completed_at, ce.final_score,
                    cert.certificate_number, cert.issued_at, cert.expiry_date,
                    ce.time_spent_minutes
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  JOIN courses c ON ce.course_id = c.course_id
                  LEFT JOIN departments d ON u.department = d.department_id
                  LEFT JOIN certificates cert ON cert.user_id = u.id AND cert.course_id = c.course_id
                  WHERE ce.tenant_id = ? AND ce.is_active = 1 
                  AND ce.is_mandatory = 1 AND ce.status = 'completed' $dateFilter
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

    public function getSummary(): array
    {
        $dashboard = $this->complianceStatusDashboard();
        return $dashboard['summary'];
    }
}
