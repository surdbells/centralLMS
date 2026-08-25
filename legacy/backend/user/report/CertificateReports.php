<?php

require_once __DIR__ . '/BaseReport.php';

/**
 * Certificate Reports
 * Reports related to certificates issued, expiring, and verification
 */
class CertificateReports extends BaseReport {

    public function generate(): array {
        return $this->certificatesIssuedReport();
    }

    /**
     * Report 55: Certificates Issued Report
     */
    public function certificatesIssuedReport(): array {
        $dateFilter = $this->buildDateFilter('cert.issued_at');

        $query = "SELECT cert.certificate_id, cert.certificate_number,
                    u.id as user_id, CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, d.department_name,
                    c.course_id, c.title as course_title,
                    cert.issued_at, cert.expiry_date,
                    CASE 
                      WHEN cert.expiry_date IS NULL THEN 'No Expiry'
                      WHEN cert.expiry_date < CURDATE() THEN 'Expired'
                      WHEN cert.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'Expiring Soon'
                      ELSE 'Valid'
                    END as status
                  FROM certificates cert
                  JOIN users u ON cert.user_id = u.id
                  JOIN courses c ON cert.course_id = c.course_id
                  LEFT JOIN departments d ON u.department = d.department
                  WHERE cert.tenant_id = ? $dateFilter
                  ORDER BY cert.issued_at DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'certificate_id' => (int) $row['certificate_id'],
                'certificate_number' => $row['certificate_number'],
                'user_id' => (int) $row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'department' => $row['department_name'] ?? 'N/A',
                'course_id' => (int) $row['course_id'],
                'course_title' => $row['course_title'],
                'issued_at' => $row['issued_at'],
                'expiry_date' => $row['expiry_date'],
                'status' => $row['status'],
                'status_badge' => $this->getStatusBadge(strtolower(str_replace(' ', '_', $row['status'])))
            ];
        }

        $countQuery = "SELECT COUNT(*) as total FROM certificates WHERE tenant_id = ?";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'Certificates Issued Report',
            'data' => $data,
            'meta' => $this->buildMeta($total),
            'summary' => $this->getCertificateSummary()
        ];
    }

    /**
     * Report 56: Certificate Verification Log
     */
    public function certificateVerificationLog(): array {
        $dateFilter = $this->buildDateFilter('cvl.verified_at');

        $query = "SELECT cvl.id, cvl.certificate_number,
                    cert.certificate_id,
                    CONCAT(u.first_name, ' ', u.last_name) as certificate_holder,
                    c.title as course_title,
                    cvl.verified_at, cvl.verified_by_name, cvl.verified_by_email,
                    cvl.verified_by_company, cvl.ip_address,
                    cvl.verification_status
                  FROM certificate_verification_log cvl
                  LEFT JOIN certificates cert ON cvl.certificate_number = cert.certificate_number
                  LEFT JOIN users u ON cert.user_id = u.id
                  LEFT JOIN courses c ON cert.course_id = c.course_id
                  WHERE cvl.tenant_id = ? $dateFilter
                  ORDER BY cvl.verified_at DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'log_id' => (int) $row['id'],
                'certificate_number' => $row['certificate_number'],
                'certificate_holder' => $row['certificate_holder'] ?? 'Unknown',
                'course_title' => $row['course_title'] ?? 'Unknown',
                'verified_at' => $row['verified_at'],
                'verified_by' => $row['verified_by_name'],
                'verified_by_email' => $row['verified_by_email'],
                'verified_by_company' => $row['verified_by_company'],
                'ip_address' => $row['ip_address'],
                'status' => $row['verification_status']
            ];
        }

        return [
            'report_name' => 'Certificate Verification Log',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 57: Expiring Certificates Report
     */
    public function expiringCertificatesReport(int $days_ahead = 90): array {
        $query = "SELECT cert.certificate_id, cert.certificate_number,
                    u.id as user_id, CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, d.department_name,
                    CONCAT(mgr.first_name, ' ', mgr.last_name) as manager_name,
                    mgr.email as manager_email,
                    c.course_id, c.title as course_title,
                    cert.issued_at, cert.expiry_date,
                    DATEDIFF(cert.expiry_date, CURDATE()) as days_until_expiry
                  FROM certificates cert
                  JOIN users u ON cert.user_id = u.id
                  JOIN courses c ON cert.course_id = c.course_id
                  LEFT JOIN departments d ON u.department = d.department
                  LEFT JOIN users mgr ON u.manager_id = mgr.id
                  WHERE cert.tenant_id = ?
                  AND cert.expiry_date IS NOT NULL
                  AND cert.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                  AND cert.expiry_date >= CURDATE()
                  ORDER BY cert.expiry_date ASC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iiii", $this->tenant_id, $days_ahead, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $daysUntil = (int) $row['days_until_expiry'];
            $urgency = 'low';
            if ($daysUntil <= 7) $urgency = 'critical';
            elseif ($daysUntil <= 30) $urgency = 'high';
            elseif ($daysUntil <= 60) $urgency = 'medium';

            $data[] = [
                'certificate_id' => (int) $row['certificate_id'],
                'certificate_number' => $row['certificate_number'],
                'user_id' => (int) $row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'department' => $row['department_name'] ?? 'N/A',
                'manager' => $row['manager_name'] ?? 'N/A',
                'manager_email' => $row['manager_email'],
                'course_id' => (int) $row['course_id'],
                'course_title' => $row['course_title'],
                'issued_at' => $row['issued_at'],
                'expiry_date' => $row['expiry_date'],
                'days_until_expiry' => $daysUntil,
                'urgency' => $urgency
            ];
        }

        $countQuery = "SELECT COUNT(*) as total FROM certificates 
                       WHERE tenant_id = ? AND expiry_date IS NOT NULL 
                       AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) AND expiry_date >= CURDATE()";
        $stmt = $this->conn->prepare($countQuery);
        $stmt->bind_param("ii", $this->tenant_id, $days_ahead);
        $stmt->execute();
        $total = (int) $stmt->get_result()->fetch_assoc()['total'];

        return [
            'report_name' => 'Expiring Certificates Report',
            'days_ahead' => $days_ahead,
            'data' => $data,
            'meta' => $this->buildMeta($total),
            'summary' => [
                'total_expiring' => $total,
                'critical' => count(array_filter($data, fn($d) => $d['urgency'] === 'critical')),
                'high' => count(array_filter($data, fn($d) => $d['urgency'] === 'high')),
                'medium' => count(array_filter($data, fn($d) => $d['urgency'] === 'medium')),
                'low' => count(array_filter($data, fn($d) => $d['urgency'] === 'low'))
            ]
        ];
    }

    /**
     * Report 58: Certificate Reprint History
     */
    public function certificateReprintHistory(): array {
        $dateFilter = $this->buildDateFilter('crh.reprinted_at');

        $query = "SELECT crh.id, cert.certificate_id, cert.certificate_number,
                    CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    c.title as course_title,
                    cert.issued_at as original_issue_date,
                    crh.reprinted_at, crh.reason,
                    CONCAT(admin.first_name, ' ', admin.last_name) as reprinted_by
                  FROM certificate_reprint_history crh
                  JOIN certificates cert ON crh.certificate_id = cert.certificate_id
                  JOIN users u ON cert.user_id = u.id
                  JOIN courses c ON cert.course_id = c.course_id
                  LEFT JOIN users admin ON crh.reprinted_by = admin.id
                  WHERE cert.tenant_id = ? $dateFilter
                  ORDER BY crh.reprinted_at DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'reprint_id' => (int) $row['id'],
                'certificate_id' => (int) $row['certificate_id'],
                'certificate_number' => $row['certificate_number'],
                'learner_name' => $row['learner_name'],
                'course_title' => $row['course_title'],
                'original_issue_date' => $row['original_issue_date'],
                'reprinted_at' => $row['reprinted_at'],
                'reason' => $row['reason'],
                'reprinted_by' => $row['reprinted_by'] ?? 'System'
            ];
        }

        return [
            'report_name' => 'Certificate Reprint History',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Certificates by Course
     */
    public function certificatesByCourse(): array {
        $query = "SELECT c.course_id, c.title as course_title,
                    COUNT(cert.certificate_id) as certificates_issued,
                    COUNT(DISTINCT cert.user_id) as unique_recipients,
                    MIN(cert.issued_at) as first_issued,
                    MAX(cert.issued_at) as last_issued,
                    SUM(CASE WHEN cert.expiry_date < CURDATE() THEN 1 ELSE 0 END) as expired,
                    SUM(CASE WHEN cert.expiry_date >= CURDATE() OR cert.expiry_date IS NULL THEN 1 ELSE 0 END) as valid
                  FROM courses c
                  LEFT JOIN certificates cert ON c.course_id = cert.course_id
                  WHERE c.tenant_id = ? AND c.is_active = 1
                  GROUP BY c.course_id, c.title
                  HAVING certificates_issued > 0
                  ORDER BY certificates_issued DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'course_id' => (int) $row['course_id'],
                'course_title' => $row['course_title'],
                'certificates_issued' => (int) $row['certificates_issued'],
                'unique_recipients' => (int) $row['unique_recipients'],
                'first_issued' => $row['first_issued'],
                'last_issued' => $row['last_issued'],
                'valid' => (int) $row['valid'],
                'expired' => (int) $row['expired']
            ];
        }

        return [
            'report_name' => 'Certificates by Course',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    private function getCertificateSummary(): array {
        $query = "SELECT 
                    COUNT(*) as total_issued,
                    COUNT(DISTINCT user_id) as unique_recipients,
                    COUNT(DISTINCT course_id) as courses_with_certificates,
                    SUM(CASE WHEN expiry_date IS NULL OR expiry_date >= CURDATE() THEN 1 ELSE 0 END) as valid,
                    SUM(CASE WHEN expiry_date < CURDATE() THEN 1 ELSE 0 END) as expired,
                    SUM(CASE WHEN expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as expiring_soon
                  FROM certificates WHERE tenant_id = ?";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function getSummary(): array {
        return $this->getCertificateSummary();
    }
}
