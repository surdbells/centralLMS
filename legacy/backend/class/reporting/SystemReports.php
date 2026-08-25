<?php

/**
 * System & Administration Reports
 * Monitor LMS platform health, admin actions, storage, integration status
 */

require_once __DIR__ . '/BaseReport.php';

class SystemReports extends BaseReport
{
    /**
     * 1. System Usage Report
     */
    public function systemUsage(): array
    {
        $t = $this->tenant_id;

        $users = $this->executeOne("SELECT COUNT(*) as total, SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) as active FROM users WHERE tenant_id=?", 'i', [$t]);
        $courses = $this->executeOne("SELECT COUNT(*) as total, SUM(CASE WHEN status='published' THEN 1 ELSE 0 END) as published FROM courses WHERE tenant_id=?", 'i', [$t]);
        $enrollments = $this->executeOne("SELECT COUNT(*) as total, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed FROM course_enrollments WHERE tenant_id=? AND is_active=1", 'i', [$t]);

        $loginLast30 = 0;
        if ($this->tableExists('user_login_history')) {
            $r = $this->executeOne("SELECT COUNT(DISTINCT user_id) as cnt FROM user_login_history WHERE tenant_id=? AND login_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)", 'i', [$t]);
            $loginLast30 = (int) $r['cnt'];
        }

        $assessments = $this->executeOne("SELECT COUNT(*) as total FROM proc_assessments WHERE tenant_id=?", 'i', [$t]);
        $attempts = $this->executeOne("SELECT COUNT(*) as total FROM proc_attempts WHERE tenant_id=?", 'i', [$t]);
        $certs = $this->executeOne("SELECT COUNT(*) as total FROM certificates WHERE tenant_id=?", 'i', [$t]);

        return [
            'users' => ['total' => (int) $users['total'], 'active' => (int) $users['active']],
            'courses' => ['total' => (int) $courses['total'], 'published' => (int) $courses['published']],
            'enrollments' => ['total' => (int) $enrollments['total'], 'completed' => (int) $enrollments['completed']],
            'active_users_last_30d' => $loginLast30,
            'assessments' => (int) $assessments['total'],
            'assessment_attempts' => (int) $attempts['total'],
            'certificates_issued' => (int) $certs['total'],
        ];
    }

    /**
     * 2. Error & Issue Log
     */
    public function errorLog(): array
    {
        if (!$this->tableExists('error_log')) return $this->paginatedResponse([], 0);
        $date = $this->buildDateRangeWhere('el.created_at');
        $baseSql = "SELECT el.id, el.error_type, el.error_code, el.error_message, el.file_path, el.line_number,
            el.is_resolved, el.created_at, CONCAT(u.first_name,' ',u.last_name) as user_name
            FROM error_log el LEFT JOIN users u ON u.id = el.user_id
            WHERE (el.tenant_id = ? OR el.tenant_id IS NULL) {$date['where']}";
        $allTypes = 'i' . $date['types'];
        $allParams = [$this->tenant_id, ...$date['params']];
        if (!empty($this->filters['resolved'])) {
            $baseSql .= " AND el.is_resolved = ?";
            $allTypes .= 'i';
            $allParams[] = $this->filters['resolved'] === 'yes' ? 1 : 0;
        }
        $total = $this->getCount($baseSql, $allTypes, $allParams);
        $sql = $baseSql . " ORDER BY el.created_at DESC" . $this->getLimitClause();
        $data = $this->executeQuery($sql, $allTypes, $allParams);
        return $this->paginatedResponse($data, $total);
    }

    /**
     * 3. Admin Activity Log
     */
    public function adminActivityLog(): array
    {
        if (!$this->tableExists('admin_activity_log')) return $this->paginatedResponse([], 0);
        $date = $this->buildDateRangeWhere('al.created_at');
        $baseSql = "SELECT al.id, al.action, al.action_category, al.target_type, al.target_name,
            al.ip_address, al.created_at,
            CONCAT(u.first_name,' ',u.last_name) as admin_name, u.email
            FROM admin_activity_log al
            JOIN users u ON u.id = al.admin_id
            WHERE al.tenant_id = ? {$date['where']}";
        $allTypes = 'i' . $date['types'];
        $allParams = [$this->tenant_id, ...$date['params']];
        if (!empty($this->filters['action_category'])) {
            $baseSql .= " AND al.action_category = ?";
            $allTypes .= 's';
            $allParams[] = $this->filters['action_category'];
        }
        $total = $this->getCount($baseSql, $allTypes, $allParams);
        $sql = $baseSql . " ORDER BY al.created_at DESC" . $this->getLimitClause();
        $data = $this->executeQuery($sql, $allTypes, $allParams);
        return $this->paginatedResponse($data, $total);
    }

    /**
     * 4. Storage Usage Report (approximation from course content)
     */
    public function storageUsage(): array
    {
        $sql = "SELECT c.course_id, c.title,
            (SELECT COUNT(*) FROM lessons l JOIN course_modules m ON m.module_id=l.module_id WHERE m.course_id=c.course_id) as lesson_count,
            (SELECT COUNT(*) FROM course_modules m WHERE m.course_id=c.course_id) as module_count
            FROM courses c WHERE c.tenant_id = ? AND c.is_active = 1 ORDER BY lesson_count DESC";
        return $this->executeQuery($sql, 'i', [$this->tenant_id]);
    }

    /**
     * 5. Integration Sync Report
     */
    public function integrationSync(): array
    {
        if (!$this->tableExists('integration_sync_log')) return ['info' => 'integration_sync_log table not available'];
        $sql = "SELECT isl.id, isl.integration_name, isl.sync_type, isl.status, isl.records_synced,
            isl.errors_count, isl.started_at, isl.completed_at, isl.error_details
            FROM integration_sync_log isl WHERE isl.tenant_id = ? ORDER BY isl.started_at DESC LIMIT 50";
        return $this->executeQuery($sql, 'i', [$this->tenant_id]);
    }

    /**
     * 6. Tenant Usage Report (multi-tenant comparison — superadmin only)
     */
    public function tenantUsage(): array
    {
        $sql = "SELECT t.tenant_id, t.name as tenant_name,
            (SELECT COUNT(*) FROM users u WHERE u.tenant_id=t.tenant_id AND u.is_active=1) as active_users,
            (SELECT COUNT(*) FROM courses c WHERE c.tenant_id=t.tenant_id AND c.is_active=1) as courses,
            (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.tenant_id=t.tenant_id AND ce.is_active=1) as enrollments,
            (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.tenant_id=t.tenant_id AND ce.is_active=1 AND ce.status='completed') as completions
            FROM tenants t WHERE t.is_active = 1 ORDER BY active_users DESC";
        return $this->executeQuery($sql);
    }

    public function getExportColumns(string $report): array { return []; }
}
