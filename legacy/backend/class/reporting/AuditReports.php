<?php

/**
 * Audit & Data Privacy Reports
 * Comprehensive audit trails, data access logs, GDPR/NDPR compliance, security
 */

require_once __DIR__ . '/BaseReport.php';

class AuditReports extends BaseReport
{
    /**
     * 1. Full Audit Trail
     */
    public function fullAuditTrail(): array
    {
        if (!$this->tableExists('audit_log')) return $this->paginatedResponse([], 0);

        $date = $this->buildDateRangeWhere('al.created_at');
        $org = $this->buildOrgScopeWhere('u');
        $m = $this->mergeWhereClauses($date, $org);

        $extra = '';
        $extraTypes = '';
        $extraParams = [];
        if (!empty($this->filters['action_type'])) {
            $extra .= " AND al.action = ?";
            $extraTypes .= 's';
            $extraParams[] = $this->filters['action_type'];
        }
        if ($this->learner_id) {
            $extra .= " AND al.user_id = ?";
            $extraTypes .= 'i';
            $extraParams[] = $this->learner_id;
        }

        $baseSql = "SELECT al.id, al.user_id, al.action, al.entity_type, al.entity_id,
            al.old_values, al.new_values, al.ip_address, al.created_at,
            CONCAT(u.first_name,' ',u.last_name) as user_name, u.email
            FROM audit_log al
            JOIN users u ON u.id = al.user_id
            WHERE al.tenant_id = ? {$m['where']}{$extra}";

        $allTypes = 'i' . $m['types'] . $extraTypes;
        $allParams = [$this->tenant_id, ...$m['params'], ...$extraParams];
        $total = $this->getCount($baseSql, $allTypes, $allParams);
        $sql = $baseSql . " ORDER BY al.created_at DESC" . $this->getLimitClause();
        $data = $this->executeQuery($sql, $allTypes, $allParams);

        return $this->paginatedResponse($data, $total);
    }

    /**
     * 2. Data Access Log
     */
    public function dataAccessLog(): array
    {
        if (!$this->tableExists('data_access_log')) return $this->paginatedResponse([], 0);

        $date = $this->buildDateRangeWhere('dal.accessed_at');

        $baseSql = "SELECT dal.id, dal.user_id, dal.data_type, dal.data_id, dal.data_description,
            dal.access_type, dal.purpose, dal.ip_address, dal.accessed_at,
            CONCAT(u.first_name,' ',u.last_name) as user_name, u.email
            FROM data_access_log dal
            JOIN users u ON u.id = dal.user_id
            WHERE dal.tenant_id = ? {$date['where']}";

        if (!empty($this->filters['data_type'])) {
            $baseSql .= " AND dal.data_type = '" . $this->conn->real_escape_string($this->filters['data_type']) . "'";
        }

        $allTypes = 'i' . $date['types'];
        $allParams = [$this->tenant_id, ...$date['params']];
        $total = $this->getCount($baseSql, $allTypes, $allParams);
        $sql = $baseSql . " ORDER BY dal.accessed_at DESC" . $this->getLimitClause();
        $data = $this->executeQuery($sql, $allTypes, $allParams);

        return $this->paginatedResponse($data, $total);
    }

    /**
     * 3. GDPR/NDPR Privacy Report
     */
    public function privacyReport(): array
    {
        if (!$this->tableExists('data_subject_requests')) {
            return ['info' => 'data_subject_requests table not available'];
        }

        $sql = "SELECT dsr.id, dsr.request_type, dsr.status, dsr.requested_at, dsr.completed_at,
            dsr.notes, CONCAT(u.first_name,' ',u.last_name) as requester_name, u.email
            FROM data_subject_requests dsr
            JOIN users u ON u.id = dsr.user_id
            WHERE dsr.tenant_id = ?
            ORDER BY dsr.requested_at DESC";

        $data = $this->executeQuery($sql, 'i', [$this->tenant_id]);

        // Summary
        $summary = [
            'total_requests' => count($data),
            'pending' => count(array_filter($data, fn($r) => $r['status'] === 'pending')),
            'completed' => count(array_filter($data, fn($r) => $r['status'] === 'completed')),
            'by_type' => []
        ];
        $byType = [];
        foreach ($data as $r) {
            $byType[$r['request_type']] = ($byType[$r['request_type']] ?? 0) + 1;
        }
        $summary['by_type'] = $byType;

        return ['summary' => $summary, 'requests' => $data];
    }

    /**
     * 4. Training Records Export (for a specific user — legal/audit)
     */
    public function trainingRecordsExport(): array
    {
        $lid = $this->learner_id;
        if (!$lid) return ['error' => 'learner_id required'];

        $learner = $this->executeOne(
            "SELECT u.id, CONCAT(u.first_name,' ',u.last_name) as name, u.email, u.created_at,
                COALESCE(d.department_name,'') as department, COALESCE(b.branch_name,'') as branch
            FROM users u LEFT JOIN departments d ON d.department_id=u.department
            LEFT JOIN branches b ON b.branch_id=u.branch
            WHERE u.id=? AND u.tenant_id=?", 'ii', [$lid, $this->tenant_id]
        );

        // All enrollments
        $enrollments = $this->executeQuery(
            "SELECT c.title, ce.status, ce.progress_percentage, ce.final_score, ce.time_spent_minutes,
                ce.enrolled_at, ce.started_at, ce.completed_at, ce.is_mandatory, ce.due_date
            FROM course_enrollments ce JOIN courses c ON c.course_id=ce.course_id
            WHERE ce.user_id=? AND ce.tenant_id=? AND ce.is_active=1 ORDER BY ce.enrolled_at",
            'ii', [$lid, $this->tenant_id]
        );

        // All assessment attempts
        $assessments = $this->executeQuery(
            "SELECT a.title, at.score, at.max_score, at.passed, at.grade, at.status, at.started_at, at.completed_at
            FROM proc_attempts at JOIN proc_assessments a ON a.assessment_id=at.assessment_id
            WHERE at.user_id=? AND at.tenant_id=? ORDER BY at.started_at",
            'ii', [$lid, $this->tenant_id]
        );

        // Certificates
        $certificates = $this->executeQuery(
            "SELECT c.title as course_title, cert.certificate_number, cert.issue_date, cert.expiry_date
            FROM certificates cert JOIN courses c ON c.course_id=cert.course_id
            WHERE cert.user_id=? AND cert.tenant_id=? ORDER BY cert.issue_date",
            'ii', [$lid, $this->tenant_id]
        );

        return [
            'learner' => $learner,
            'enrollments' => $enrollments,
            'assessments' => $assessments,
            'certificates' => $certificates,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 5. Security Audit Report
     */
    public function securityAudit(): array
    {
        $result = [];

        // Login attempts (failed)
        if ($this->tableExists('login_attempts')) {
            $date = $this->buildDateRangeWhere('la.attempted_at');
            $sql = "SELECT la.email, la.ip_address, la.attempted_at, la.status, la.failure_reason
                FROM login_attempts la WHERE la.tenant_id = ? AND la.status = 'failed' {$date['where']}
                ORDER BY la.attempted_at DESC LIMIT 100";
            $result['failed_logins'] = $this->executeQuery($sql, 'i' . $date['types'], [$this->tenant_id, ...$date['params']]);
        }

        // Security alerts
        if ($this->tableExists('security_alerts')) {
            $sql = "SELECT sa.id, sa.alert_type, sa.severity, sa.description, sa.user_id, sa.ip_address, sa.created_at,
                CONCAT(u.first_name,' ',u.last_name) as user_name
                FROM security_alerts sa LEFT JOIN users u ON u.id=sa.user_id
                WHERE sa.tenant_id = ? ORDER BY sa.created_at DESC LIMIT 50";
            $result['alerts'] = $this->executeQuery($sql, 'i', [$this->tenant_id]);
        }

        // Password changes
        if ($this->tableExists('password_change_log')) {
            $sql = "SELECT COUNT(*) as total, COUNT(CASE WHEN change_type='reset' THEN 1 END) as resets,
                COUNT(CASE WHEN change_type='change' THEN 1 END) as changes
                FROM password_change_log WHERE tenant_id = ? AND changed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            $result['password_activity'] = $this->executeOne($sql, 'i', [$this->tenant_id]);
        }

        return $result;
    }

    /**
     * 6. Data Retention Report
     */
    public function dataRetention(): array
    {
        $t = $this->tenant_id;

        // Age of data in key tables
        $tables = [
            ['table' => 'course_enrollments', 'date_col' => 'enrolled_at', 'label' => 'Enrollments'],
            ['table' => 'proc_attempts', 'date_col' => 'started_at', 'label' => 'Assessment Attempts'],
            ['table' => 'certificates', 'date_col' => 'issue_date', 'label' => 'Certificates'],
        ];

        $result = [];
        foreach ($tables as $tbl) {
            $row = $this->executeOne(
                "SELECT COUNT(*) as total_records, MIN({$tbl['date_col']}) as oldest_record,
                    MAX({$tbl['date_col']}) as newest_record,
                    COUNT(CASE WHEN {$tbl['date_col']} < DATE_SUB(NOW(), INTERVAL 2 YEAR) THEN 1 END) as older_than_2y,
                    COUNT(CASE WHEN {$tbl['date_col']} < DATE_SUB(NOW(), INTERVAL 5 YEAR) THEN 1 END) as older_than_5y
                FROM {$tbl['table']} WHERE tenant_id = ?", 'i', [$t]
            );
            $result[] = array_merge(['label' => $tbl['label']], $row ?? []);
        }

        return $result;
    }

    public function getExportColumns(string $report): array
    {
        return match ($report) {
            'full_audit_trail' => [
                ['key' => 'user_name', 'label' => 'User'], ['key' => 'email', 'label' => 'Email'],
                ['key' => 'action', 'label' => 'Action'], ['key' => 'entity_type', 'label' => 'Entity'],
                ['key' => 'ip_address', 'label' => 'IP'], ['key' => 'created_at', 'label' => 'Date'],
            ],
            'training_records_export' => [
                ['key' => 'title', 'label' => 'Course'], ['key' => 'status', 'label' => 'Status'],
                ['key' => 'final_score', 'label' => 'Score'], ['key' => 'enrolled_at', 'label' => 'Enrolled'],
                ['key' => 'completed_at', 'label' => 'Completed'],
            ],
            default => []
        };
    }
}
