<?php
require_once __DIR__ . '/BaseReport.php';
class AuditReports extends BaseReport
{

    public function generate(): array
    {
        return $this->fullAuditTrail();
    }

    /**
     * Report 70: Full Audit Trail
     */
    public function fullAuditTrail(): array
    {
        $dateFilter = $this->buildDateFilter('al.created_at');

        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT al.id, al.action_type, al.action_category,
                    al.entity_type, al.entity_id, al.entity_name,
                    al.user_id, CONCAT(u.first_name, ' ', u.last_name) as user_name,
                    u.email as user_email,
                    al.ip_address, al.user_agent, al.session_id,
                    al.old_values, al.new_values, al.created_at
                  FROM audit_log al
                  LEFT JOIN users u ON al.user_id = u.id
                  WHERE al.tenant_id = ? $dateFilter
                  {$orgFilter}
                  ORDER BY al.created_at DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'audit_id' => (int)$row['id'],
                'action_type' => $row['action_type'],
                'action_category' => $row['action_category'],
                'entity_type' => $row['entity_type'],
                'entity_id' => $row['entity_id'],
                'entity_name' => $row['entity_name'],
                'user_id' => $row['user_id'] ? (int)$row['user_id'] : null,
                'user_name' => $row['user_name'] ?? 'System',
                'user_email' => $row['user_email'],
                'ip_address' => $row['ip_address'],
                'user_agent' => $row['user_agent'],
                'session_id' => $row['session_id'],
                'old_values' => json_decode($row['old_values'] ?? '{}', true),
                'new_values' => json_decode($row['new_values'] ?? '{}', true),
                'timestamp' => $row['created_at']
            ];
        }

        // Action type summary
        $summaryQuery = "SELECT action_type, COUNT(*) as count 
                         FROM audit_log WHERE tenant_id = ? 
                         GROUP BY action_type ORDER BY count DESC";
        $stmt = $this->conn->prepare($summaryQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $actionSummary = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        return [
            'report_name' => 'Full Audit Trail',
            'data' => $data,
            'meta' => $this->buildMeta(count($data)),
            'action_summary' => $actionSummary
        ];
    }

    /**
     * Report 71: Data Access Log
     */
    public function dataAccessLog(): array
    {
        $dateFilter = $this->buildDateFilter('dal.accessed_at');

        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT dal.id, dal.user_id, 
                    CONCAT(u.first_name, ' ', u.last_name) as user_name,
                    u.email, u.job_title,
                    dal.data_type, dal.data_id, dal.data_description,
                    dal.access_type, dal.purpose,
                    dal.ip_address, dal.accessed_at
                  FROM data_access_log dal
                  JOIN users u ON dal.user_id = u.id
                  WHERE dal.tenant_id = ? $dateFilter
                  {$orgFilter}
                  ORDER BY dal.accessed_at DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'log_id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'user_name' => $row['user_name'],
                'email' => $row['email'],
                'job_title' => $row['job_title'],
                'data_type' => $row['data_type'],
                'data_id' => $row['data_id'],
                'data_description' => $row['data_description'],
                'access_type' => $row['access_type'],
                'purpose' => $row['purpose'],
                'ip_address' => $row['ip_address'],
                'accessed_at' => $row['accessed_at']
            ];
        }

        // Access type summary
        $summaryQuery = "SELECT data_type, access_type, COUNT(*) as count 
                         FROM data_access_log WHERE tenant_id = ? 
                         GROUP BY data_type, access_type ORDER BY count DESC";
        $stmt = $this->conn->prepare($summaryQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $accessSummary = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        return [
            'report_name' => 'Data Access Log',
            'data' => $data,
            'meta' => $this->buildMeta(count($data)),
            'access_summary' => $accessSummary
        ];
    }

    /**
     * Report 72: GDPR/Data Privacy Report
     */
    public function gdprDataPrivacyReport(): array
    {
        // User consent status
        $orgFilter = $this->buildOrgFilter();

        $consentQuery = "SELECT 
                           COUNT(*) as total_users,
                           SUM(CASE WHEN privacy_consent = 1 THEN 1 ELSE 0 END) as consented,
                           SUM(CASE WHEN marketing_consent = 1 THEN 1 ELSE 0 END) as marketing_consent,
                           SUM(CASE WHEN data_processing_consent = 1 THEN 1 ELSE 0 END) as processing_consent
                         FROM users u WHERE u.tenant_id = ? AND u.is_active = 1
                    {$orgFilter}";
        $stmt = $this->conn->prepare($consentQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $consent = $stmt->get_result()->fetch_assoc();

        // Data subject requests
        $requestQuery = "SELECT request_type, status, COUNT(*) as count
                         FROM data_subject_requests 
                         WHERE tenant_id = ?
                         GROUP BY request_type, status";
        $stmt = $this->conn->prepare($requestQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Recent data requests
        $recentQuery = "SELECT dsr.id, dsr.request_type, dsr.status,
                          CONCAT(u.first_name, ' ', u.last_name) as requester_name,
                          u.email, dsr.requested_at, dsr.completed_at,
                          DATEDIFF(COALESCE(dsr.completed_at, CURDATE()), dsr.requested_at) as days_to_resolve
                        FROM data_subject_requests dsr
                        JOIN users u ON dsr.user_id = u.id
                        WHERE dsr.tenant_id = ?
                        {$orgFilter}
                        ORDER BY dsr.requested_at DESC
                        LIMIT 20";
        $stmt = $this->conn->prepare($recentQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $recentRequests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Data retention stats
        $retentionQuery = "SELECT 
                            (SELECT COUNT(*) FROM users WHERE tenant_id = ? AND is_active = 0 AND deleted_at IS NOT NULL) as deleted_users,
                            (SELECT COUNT(*) FROM course_enrollments WHERE tenant_id = ? AND is_active = 0) as archived_enrollments";
        $stmt = $this->conn->prepare($retentionQuery);
        $stmt->bind_param("ii", $this->tenant_id, $this->tenant_id);
        $stmt->execute();
        $retention = $stmt->get_result()->fetch_assoc();

        $totalUsers = (int)($consent['total_users'] ?? 0);

        return [
            'report_name' => 'GDPR/Data Privacy Report',
            'consent_summary' => [
                'total_users' => $totalUsers,
                'privacy_consent' => (int)($consent['consented'] ?? 0),
                'privacy_consent_rate' => $totalUsers > 0 ? round(((int)$consent['consented'] / $totalUsers) * 100, 1) : 0,
                'marketing_consent' => (int)($consent['marketing_consent'] ?? 0),
                'data_processing_consent' => (int)($consent['processing_consent'] ?? 0)
            ],
            'data_subject_requests' => [
                'summary' => $requests,
                'recent' => $recentRequests
            ],
            'data_retention' => [
                'deleted_users' => (int)($retention['deleted_users'] ?? 0),
                'archived_enrollments' => (int)($retention['archived_enrollments'] ?? 0)
            ]
        ];
    }

    /**
     * Report 73: Training Records Export
     */
    public function trainingRecordsExport(int $user_id): array
    {
        // User info
        $orgFilter = $this->buildOrgFilter();

        $userQuery = "SELECT u.*, d.department_name FROM users u
                      LEFT JOIN departments d ON u.department = d.department_id
                      WHERE u.id = ? AND u.tenant_id = ?
                    {$orgFilter}";
        $stmt = $this->conn->prepare($userQuery);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user) {
            return ['error' => 'User not found'];
        }

        // All enrollments
        $enrollQuery = "SELECT ce.*, c.title as course_title, c.description as course_description
                        FROM course_enrollments ce
                        JOIN courses c ON ce.course_id = c.course_id
                        WHERE ce.user_id = ? AND ce.tenant_id = ?
                        ORDER BY ce.enrolled_at";
        $stmt = $this->conn->prepare($enrollQuery);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $enrollments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // All certificates
        $certQuery = "SELECT cert.*, c.title as course_title
                      FROM certificates cert
                      JOIN courses c ON cert.course_id = c.course_id
                      WHERE cert.user_id = ? AND cert.tenant_id = ?
                      ORDER BY cert.issue_date";
        $stmt = $this->conn->prepare($certQuery);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $certificates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // All assessment attempts
        $assessmentQuery = "SELECT aa.*, a.title as assessment_title, c.title as course_title
                           FROM assessment_attempts aa
                           JOIN assessments a ON aa.assessment_id = a.assessment_id
                           JOIN courses c ON a.course_id = c.course_id
                           WHERE aa.user_id = ? AND a.tenant_id = ?
                           ORDER BY aa.completed_at";
        $stmt = $this->conn->prepare($assessmentQuery);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $assessments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // All badges
        $badgeQuery = "SELECT ub.*, b.badge_name, b.description
                       FROM user_badges ub
                       JOIN badges b ON ub.badge_id = b.badge_id
                       WHERE ub.user_id = ? AND ub.tenant_id = ?
                       ORDER BY ub.earned_at";
        $stmt = $this->conn->prepare($badgeQuery);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $badges = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Points history
        $pointsQuery = "SELECT * FROM user_points 
                        WHERE user_id = ? AND tenant_id = ?
                        ORDER BY earned_at";
        $stmt = $this->conn->prepare($pointsQuery);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $points = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Login history
        $loginQuery = "SELECT * FROM user_login_history 
                       WHERE user_id = ? AND tenant_id = ?
                       ORDER BY login_at DESC LIMIT 100";
        $stmt = $this->conn->prepare($loginQuery);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $logins = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        return [
            'report_name' => 'Training Records Export',
            'export_date' => date('Y-m-d H:i:s'),
            'user' => [
                'user_id' => (int)$user['id'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'email' => $user['email'],
                'employee_id' => $user['staffId'],
                'department' => $user['department_name'],
                'job_title' => $user['job_title'],
                'created_at' => $user['created_at']
            ],
            'training_summary' => [
                'total_enrollments' => count($enrollments),
                'total_certificates' => count($certificates),
                'total_assessments' => count($assessments),
                'total_badges' => count($badges)
            ],
            'enrollments' => array_map(function ($e) {
                return [
                    'course_title' => $e['course_title'],
                    'enrolled_at' => $e['enrolled_at'],
                    'started_at' => $e['started_at'],
                    'completed_at' => $e['completed_at'],
                    'status' => $e['status'],
                    'progress' => $e['progress_percentage'],
                    'final_score' => $e['final_score'],
                    'time_spent_minutes' => $e['time_spent_minutes'],
                    'is_mandatory' => (bool)$e['is_mandatory']
                ];
            }, $enrollments),
            'certificates' => array_map(function ($c) {
                return [
                    'course_title' => $c['course_title'],
                    'certificate_number' => $c['certificate_number'],
                    'issued_at' => $c['issue_date'],
                    'expiry_date' => $c['expiry_date']
                ];
            }, $certificates),
            'assessment_attempts' => array_map(function ($a) {
                return [
                    'assessment_title' => $a['assessment_title'],
                    'course_title' => $a['course_title'],
                    'attempt_number' => $a['attempt_number'],
                    'score' => $a['score'],
                    'passed' => (bool)$a['passed'],
                    'completed_at' => $a['completed_at'],
                    'time_taken_minutes' => $a['time_taken_minutes']
                ];
            }, $assessments),
            'badges' => array_map(function ($b) {
                return [
                    'badge_name' => $b['badge_name'],
                    'description' => $b['description'],
                    'earned_at' => $b['earned_at']
                ];
            }, $badges),
            'points_history' => $points,
            'login_history' => array_map(function ($l) {
                return [
                    'login_at' => $l['login_at'],
                    'logout_at' => $l['logout_at'],
                    'ip_address' => $l['ip_address'],
                    'device_type' => $l['device_type']
                ];
            }, $logins)
        ];
    }

    /**
     * Compliance Training Audit Trail
     */
    public function complianceTrainingAuditTrail(): array
    {
        $dateFilter = $this->buildDateFilter('ce.completed_at');

        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT ce.enrollment_id, u.id as user_id, u.staffId,
                    CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, d.department_name, u.job_title,
                    c.course_id, c.title as course_title,
                    ce.enrolled_at, ce.started_at, ce.completed_at,
                    ce.final_score, ce.time_spent_minutes,
                    cert.certificate_number, cert.issue_date as certificate_issued,
                    cert.expiry_date as certificate_expiry
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  JOIN courses c ON ce.course_id = c.course_id
                  LEFT JOIN departments d ON u.department = d.department_id
                  LEFT JOIN certificates cert ON ce.user_id = cert.user_id AND ce.course_id = cert.course_id
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
                'employee_id' => $row['staffId'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'department' => $row['department_name'] ?? 'N/A',
                'job_title' => $row['job_title'] ?? 'N/A',
                'course_id' => (int)$row['course_id'],
                'course_title' => $row['course_title'],
                'enrolled_at' => $row['enrolled_at'],
                'started_at' => $row['started_at'],
                'completed_at' => $row['completed_at'],
                'final_score' => (int)$row['final_score'],
                'time_spent' => $this->formatDuration((int)$row['time_spent_minutes']),
                'certificate_number' => $row['certificate_number'],
                'certificate_issued' => $row['certificate_issued'],
                'certificate_expiry' => $row['certificate_expiry']
            ];
        }

        return [
            'report_name' => 'Compliance Training Audit Trail',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Security Audit Report
     */
    public function securityAuditReport(): array
    {
        // Failed login attempts
        $orgFilter = $this->buildOrgFilter();

        $failedLoginsQuery = "SELECT DATE(created_at) as date, COUNT(*) as attempts
                              FROM login_attempts
                              WHERE tenant_id = ? AND success = 0
                              AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                              GROUP BY DATE(created_at)
                              ORDER BY date DESC";
        $stmt = $this->conn->prepare($failedLoginsQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $failedLogins = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Password changes
        $passwordQuery = "SELECT DATE(changed_at) as date, COUNT(*) as changes
                          FROM password_change_log
                          WHERE tenant_id = ?
                          AND changed_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                          GROUP BY DATE(changed_at)
                          ORDER BY date DESC";
        $stmt = $this->conn->prepare($passwordQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $passwordChanges = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Permission changes
        $permissionQuery = "SELECT al.*, CONCAT(u.first_name, ' ', u.last_name) as admin_name
                           FROM audit_log al
                           LEFT JOIN users u ON al.user_id = u.id
                           WHERE al.tenant_id = ? AND al.action_category = 'permission'
                           {$orgFilter}
                           ORDER BY al.created_at DESC LIMIT 20";
        $stmt = $this->conn->prepare($permissionQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $permissionChanges = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Suspicious activity
        $suspiciousQuery = "SELECT * FROM security_alerts 
                           WHERE tenant_id = ?
                           AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                           ORDER BY created_at DESC LIMIT 20";
        $stmt = $this->conn->prepare($suspiciousQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $suspiciousActivity = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        return [
            'report_name' => 'Security Audit Report',
            'period' => 'Last 30 days',
            'failed_login_trend' => $failedLogins,
            'password_change_trend' => $passwordChanges,
            'recent_permission_changes' => $permissionChanges,
            'security_alerts' => $suspiciousActivity
        ];
    }

    /**
     * Data Retention Report — records age analysis across key data categories
     */
    public function dataRetentionReport(): array
    {
        $query = "SELECT 'User Accounts' as data_category,
                    COUNT(*) as total_records,
                    SUM(CASE WHEN DATEDIFF(NOW(), last_login_at) > 365 THEN 1 ELSE 0 END) as older_than_1yr,
                    SUM(CASE WHEN DATEDIFF(NOW(), last_login_at) > 730 THEN 1 ELSE 0 END) as older_than_2yr,
                    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
                    '3 years' as retention_policy
                  FROM users WHERE tenant_id = ?
                  UNION ALL
                  SELECT 'Training Records' as data_category,
                    COUNT(*) as total_records,
                    SUM(CASE WHEN DATEDIFF(NOW(), enrolled_at) > 365 THEN 1 ELSE 0 END) as older_than_1yr,
                    SUM(CASE WHEN DATEDIFF(NOW(), enrolled_at) > 730 THEN 1 ELSE 0 END) as older_than_2yr,
                    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
                    '5 years' as retention_policy
                  FROM course_enrollments WHERE tenant_id = ?
                  UNION ALL
                  SELECT 'Assessment Attempts' as data_category,
                    COUNT(*) as total_records,
                    SUM(CASE WHEN DATEDIFF(NOW(), started_at) > 365 THEN 1 ELSE 0 END) as older_than_1yr,
                    SUM(CASE WHEN DATEDIFF(NOW(), started_at) > 730 THEN 1 ELSE 0 END) as older_than_2yr,
                    0 as inactive,
                    '5 years' as retention_policy
                  FROM assessment_attempts WHERE tenant_id = ?
                  UNION ALL
                  SELECT 'Audit Logs' as data_category,
                    COUNT(*) as total_records,
                    SUM(CASE WHEN DATEDIFF(NOW(), created_at) > 365 THEN 1 ELSE 0 END) as older_than_1yr,
                    SUM(CASE WHEN DATEDIFF(NOW(), created_at) > 730 THEN 1 ELSE 0 END) as older_than_2yr,
                    0 as inactive,
                    '7 years' as retention_policy
                  FROM audit_log WHERE tenant_id = ?";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iiii", $this->tenant_id, $this->tenant_id, $this->tenant_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        $totalRecords = 0;
        $totalOld = 0;
        while ($row = $result->fetch_assoc()) {
            $records = (int)$row['total_records'];
            $old2yr = (int)$row['older_than_2yr'];
            $totalRecords += $records;
            $totalOld += $old2yr;
            $data[] = [
                'data_category' => $row['data_category'],
                'total_records' => $records,
                'older_than_1yr' => (int)$row['older_than_1yr'],
                'older_than_2yr' => $old2yr,
                'inactive' => (int)$row['inactive'],
                'retention_policy' => $row['retention_policy']
            ];
        }
        $stmt->close();

        return [
            'report_name' => 'Data Retention Report',
            'data' => $data,
            'meta' => $this->buildMeta(count($data)),
            'summary' => [
                'total_records' => $totalRecords,
                'records_older_than_2yr' => $totalOld,
                'categories_audited' => count($data)
            ]
        ];
    }

    public function getSummary(): array
    {
        $query = "SELECT 
                    (SELECT COUNT(*) FROM audit_log WHERE tenant_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as audit_entries_30d,
                    (SELECT COUNT(*) FROM data_subject_requests WHERE tenant_id = ? AND status = 'pending') as pending_data_requests
                  FROM dual";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->tenant_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}
