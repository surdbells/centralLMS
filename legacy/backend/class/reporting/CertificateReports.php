<?php

/**
 * Certificate Reports
 * Track certificate issuance, verification, expiry, reprint history
 *
 * Supports scoping by: course, department, team, branch, learner
 */

require_once __DIR__ . '/BaseReport.php';

class CertificateReports extends BaseReport
{
    /**
     * 1. Certificates Issued Report
     */
    public function certificatesIssued(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $course = $this->buildCourseScopeWhere('cert');
        $date = $this->buildDateRangeWhere('cert.issue_date');
        $m = $this->mergeWhereClauses($org, $course, $date);

        // Summary
        $summSql = "SELECT COUNT(*) as total_issued, COUNT(DISTINCT cert.user_id) as unique_recipients,
            COUNT(DISTINCT cert.course_id) as courses_with_certs,
            SUM(CASE WHEN cert.expiry_date IS NOT NULL AND cert.expiry_date < CURDATE() THEN 1 ELSE 0 END) as expired
            FROM certificates cert
            JOIN users u ON u.id = cert.user_id
            WHERE cert.tenant_id = ? {$m['where']}";

        $summary = $this->executeOne($summSql, 'i' . $m['types'], [$this->tenant_id, ...$m['params']]);

        // Detail list
        $baseSql = "SELECT cert.certificate_id, cert.certificate_number,
            cert.issue_date, cert.expiry_date,
            CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.email,
            COALESCE(d.department_name, '') as department_name,
            COALESCE(b.branch_name, '') as branch_name,
            c.title as course_title,
            CASE WHEN cert.expiry_date IS NULL THEN 'no_expiry'
                 WHEN cert.expiry_date < CURDATE() THEN 'expired'
                 WHEN DATEDIFF(cert.expiry_date, CURDATE()) <= 30 THEN 'expiring_soon'
                 ELSE 'active' END as cert_status
            FROM certificates cert
            JOIN users u ON u.id = cert.user_id
            LEFT JOIN departments d ON d.department_id = u.department
            LEFT JOIN branches b ON b.branch_id = u.branch
            JOIN courses c ON c.course_id = cert.course_id
            WHERE cert.tenant_id = ? {$m['where']}";

        $allTypes = 'i' . $m['types'];
        $allParams = [$this->tenant_id, ...$m['params']];
        $total = $this->getCount($baseSql, $allTypes, $allParams);
        $sql = $baseSql . " ORDER BY cert.issue_date DESC" . $this->getLimitClause();
        $data = $this->executeQuery($sql, $allTypes, $allParams);

        return [
            'summary' => [
                'total_issued' => (int) $summary['total_issued'],
                'unique_recipients' => (int) $summary['unique_recipients'],
                'courses_with_certs' => (int) $summary['courses_with_certs'],
                'expired' => (int) $summary['expired'],
            ],
            ...$this->paginatedResponse($data, $total)
        ];
    }

    /**
     * 2. Certificate Verification Log
     */
    public function verificationLog(): array
    {
        if (!$this->tableExists('certificate_verification_log')) {
            return $this->paginatedResponse([], 0);
        }

        $date = $this->buildDateRangeWhere('cvl.verified_at');

        $baseSql = "SELECT cvl.id, cvl.certificate_id, cvl.verifier_name, cvl.verifier_email,
            cvl.verifier_organization, cvl.verified_at, cvl.ip_address,
            cert.certificate_number,
            CONCAT(u.first_name, ' ', u.last_name) as learner_name,
            c.title as course_title
            FROM certificate_verification_log cvl
            JOIN certificates cert ON cert.certificate_id = cvl.certificate_id
            JOIN users u ON u.id = cert.user_id
            JOIN courses c ON c.course_id = cert.course_id
            WHERE cert.tenant_id = ? {$date['where']}";

        $allTypes = 'i' . $date['types'];
        $allParams = [$this->tenant_id, ...$date['params']];
        $total = $this->getCount($baseSql, $allTypes, $allParams);
        $sql = $baseSql . " ORDER BY cvl.verified_at DESC" . $this->getLimitClause();
        $data = $this->executeQuery($sql, $allTypes, $allParams);

        return $this->paginatedResponse($data, $total);
    }

    /**
     * 3. Expiring Certificates Report
     */
    public function expiringCertificates(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $daysAhead = (int) ($this->filters['days_ahead'] ?? 90);

        $sql = "SELECT cert.certificate_id, cert.certificate_number,
            cert.issue_date, cert.expiry_date,
            DATEDIFF(cert.expiry_date, CURDATE()) as days_remaining,
            CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.email,
            COALESCE(d.department_name, '') as department_name,
            COALESCE(t.team_name, '') as team_name,
            COALESCE(b.branch_name, '') as branch_name,
            c.title as course_title
            FROM certificates cert
            JOIN users u ON u.id = cert.user_id
            LEFT JOIN departments d ON d.department_id = u.department
            LEFT JOIN teams t ON t.team_id = u.team
            LEFT JOIN branches b ON b.branch_id = u.branch
            JOIN courses c ON c.course_id = cert.course_id
            WHERE cert.tenant_id = ? AND cert.expiry_date IS NOT NULL
            AND cert.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
            {$org['where']}
            ORDER BY cert.expiry_date ASC";

        $data = $this->executeQuery($sql, 'ii' . $org['types'], [$this->tenant_id, $daysAhead, ...$org['params']]);
        foreach ($data as &$row) {
            $row['days_remaining'] = (int) $row['days_remaining'];
            $row['urgency'] = $row['days_remaining'] <= 7 ? 'critical' : ($row['days_remaining'] <= 30 ? 'high' : 'medium');
        }
        return $data;
    }

    /**
     * 4. Certificate Reprint History
     */
    public function reprintHistory(): array
    {
        if (!$this->tableExists('certificate_reprint_history')) {
            return $this->paginatedResponse([], 0);
        }

        $date = $this->buildDateRangeWhere('crh.reprinted_at');

        $baseSql = "SELECT crh.id, crh.certificate_id, crh.reprinted_at, crh.reason,
            crh.requested_by,
            cert.certificate_number,
            CONCAT(u.first_name, ' ', u.last_name) as learner_name,
            c.title as course_title,
            CONCAT(admin.first_name, ' ', admin.last_name) as reprinted_by_name
            FROM certificate_reprint_history crh
            JOIN certificates cert ON cert.certificate_id = crh.certificate_id
            JOIN users u ON u.id = cert.user_id
            JOIN courses c ON c.course_id = cert.course_id
            LEFT JOIN users admin ON admin.id = crh.requested_by
            WHERE cert.tenant_id = ? {$date['where']}";

        $allTypes = 'i' . $date['types'];
        $allParams = [$this->tenant_id, ...$date['params']];
        $total = $this->getCount($baseSql, $allTypes, $allParams);
        $sql = $baseSql . " ORDER BY crh.reprinted_at DESC" . $this->getLimitClause();
        $data = $this->executeQuery($sql, $allTypes, $allParams);

        return $this->paginatedResponse($data, $total);
    }

    /**
     * 5. Certificate Template Usage
     */
    public function templateUsage(): array
    {
        $sql = "SELECT c.certificate_template_id as template_id,
            COUNT(cert.certificate_id) as certificates_issued,
            COUNT(DISTINCT cert.course_id) as courses_using,
            MAX(cert.issue_date) as last_issued
            FROM certificates cert
            JOIN courses c ON c.course_id = cert.course_id
            WHERE cert.tenant_id = ? AND c.certificate_template_id IS NOT NULL
            GROUP BY c.certificate_template_id
            ORDER BY certificates_issued DESC";

        return $this->executeQuery($sql, 'i', [$this->tenant_id]);
    }

    public function getExportColumns(string $report): array
    {
        return match ($report) {
            'certificates_issued', 'expiring_certificates' => [
                ['key' => 'learner_name', 'label' => 'Learner'], ['key' => 'email', 'label' => 'Email'],
                ['key' => 'course_title', 'label' => 'Course'], ['key' => 'certificate_number', 'label' => 'Cert #'],
                ['key' => 'department_name', 'label' => 'Department'], ['key' => 'issue_date', 'label' => 'Issued'],
                ['key' => 'expiry_date', 'label' => 'Expiry'],
            ],
            default => []
        };
    }
}
