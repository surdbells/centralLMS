<?php

namespace report;
require_once __DIR__ . '/BaseReport.php';
class SystemReports extends BaseReport{

    public function generate(): array
    {
        return $this->systemUsageReport();
    }

    /**
     * Report 59: System Usage Report
     */
    public function systemUsageReport(): array
    {
        // Active users stats
        $userQuery = "SELECT 
                        COUNT(*) as total_users,
                        SUM(CASE WHEN last_login_at >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) as active_today,
                        SUM(CASE WHEN last_login_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as active_7d,
                        SUM(CASE WHEN last_login_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as active_30d
                      FROM users WHERE tenant_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($userQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $users = $stmt->get_result()->fetch_assoc();

        // Session stats
        $sessionQuery = "SELECT 
                           COUNT(*) as total_sessions,
                           COUNT(DISTINCT user_id) as unique_users,
                           SUM(time_spent_minutes) as total_minutes,
                           AVG(time_spent_minutes) as avg_duration
                         FROM learning_sessions 
                         WHERE tenant_id = ? AND started_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $stmt = $this->conn->prepare($sessionQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $sessions = $stmt->get_result()->fetch_assoc();

        // Content stats
        $contentQuery = "SELECT 
                           (SELECT COUNT(*) FROM courses WHERE tenant_id = ? AND is_active = 1) as active_courses,
                           (SELECT COUNT(*) FROM modules WHERE tenant_id = ?) as total_modules,
                           (SELECT COUNT(*) FROM lessons WHERE tenant_id = ?) as total_lessons,
                           (SELECT COUNT(*) FROM assessments WHERE tenant_id = ? AND is_active = 1) as active_assessments";
        $stmt = $this->conn->prepare($contentQuery);
        $stmt->bind_param("iiii", $this->tenant_id, $this->tenant_id, $this->tenant_id, $this->tenant_id);
        $stmt->execute();
        $content = $stmt->get_result()->fetch_assoc();

        // Storage usage (if tracked)
        $storageQuery = "SELECT 
                           COALESCE(SUM(file_size), 0) as total_bytes,
                           COUNT(*) as total_files
                         FROM media WHERE tenant_id = ?";
        $stmt = $this->conn->prepare($storageQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $storage = $stmt->get_result()->fetch_assoc();

        $totalBytes = (int)($storage['total_bytes'] ?? 0);

        return [
            'report_name' => 'System Usage Report',
            'users' => [
                'total' => (int)$users['total_users'],
                'active_today' => (int)$users['active_today'],
                'active_7_days' => (int)$users['active_7d'],
                'active_30_days' => (int)$users['active_30d']
            ],
            'sessions_30d' => [
                'total_sessions' => (int)($sessions['total_sessions'] ?? 0),
                'unique_users' => (int)($sessions['unique_users'] ?? 0),
                'total_learning_time' => $this->formatDuration((int)($sessions['total_minutes'] ?? 0)),
                'avg_session_duration' => $this->formatDuration((int)($sessions['avg_duration'] ?? 0))
            ],
            'content' => [
                'active_courses' => (int)$content['active_courses'],
                'total_modules' => (int)$content['total_modules'],
                'total_lessons' => (int)$content['total_lessons'],
                'active_assessments' => (int)$content['active_assessments']
            ],
            'storage' => [
                'total_files' => (int)($storage['total_files'] ?? 0),
                'total_size_bytes' => $totalBytes,
                'total_size_formatted' => $this->formatBytes($totalBytes)
            ]
        ];
    }

    /**
     * Report 60: Error & Issue Log
     */
    public function errorIssueLog(): array
    {
        $dateFilter = $this->buildDateFilter('el.created_at');

        $query = "SELECT el.id, el.error_type, el.error_message, el.error_code,
                    el.file_path, el.line_number, el.user_id,
                    CONCAT(u.first_name, ' ', u.last_name) as user_name,
                    el.ip_address, el.user_agent, el.created_at,
                    el.is_resolved, el.resolved_at
                  FROM error_log el
                  LEFT JOIN users u ON el.user_id = u.id
                  WHERE el.tenant_id = ? $dateFilter
                  ORDER BY el.created_at DESC
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
                'error_type' => $row['error_type'],
                'error_message' => $row['error_message'],
                'error_code' => $row['error_code'],
                'file_path' => $row['file_path'],
                'line_number' => (int)$row['line_number'],
                'user_id' => $row['user_id'] ? (int)$row['user_id'] : null,
                'user_name' => $row['user_name'],
                'ip_address' => $row['ip_address'],
                'user_agent' => $row['user_agent'],
                'created_at' => $row['created_at'],
                'is_resolved' => (bool)$row['is_resolved'],
                'resolved_at' => $row['resolved_at']
            ];
        }

        // Summary
        $summaryQuery = "SELECT error_type, COUNT(*) as count 
                         FROM error_log WHERE tenant_id = ? 
                         AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                         GROUP BY error_type ORDER BY count DESC";
        $stmt = $this->conn->prepare($summaryQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $errorTypes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        return [
            'report_name' => 'Error & Issue Log',
            'data' => $data,
            'meta' => $this->buildMeta(count($data)),
            'error_summary' => $errorTypes
        ];
    }

    /**
     * Report 61: Admin Activity Log
     */
    public function adminActivityLog(): array
    {
        $dateFilter = $this->buildDateFilter('aal.created_at');

        $query = "SELECT aal.id, aal.action, aal.action_category,
                    aal.target_type, aal.target_id, aal.target_name,
                    aal.admin_id, CONCAT(u.first_name, ' ', u.last_name) as admin_name,
                    u.email as admin_email,
                    aal.ip_address, aal.user_agent, aal.details_json,
                    aal.created_at
                  FROM admin_activity_log aal
                  JOIN users u ON aal.admin_id = u.id
                  WHERE aal.tenant_id = ? $dateFilter
                  ORDER BY aal.created_at DESC
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
                'action' => $row['action'],
                'action_category' => $row['action_category'],
                'target_type' => $row['target_type'],
                'target_id' => $row['target_id'],
                'target_name' => $row['target_name'],
                'admin_id' => (int)$row['admin_id'],
                'admin_name' => $row['admin_name'],
                'admin_email' => $row['admin_email'],
                'ip_address' => $row['ip_address'],
                'details' => json_decode($row['details_json'] ?? '{}', true),
                'created_at' => $row['created_at']
            ];
        }

        return [
            'report_name' => 'Admin Activity Log',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 62: Storage Usage Report
     */
    public function storageUsageReport(): array
    {
        // By file type
        $typeQuery = "SELECT media_type, 
                        COUNT(*) as file_count,
                        SUM(file_size) as total_bytes
                      FROM media WHERE tenant_id = ?
                      GROUP BY media_type
                      ORDER BY total_bytes DESC";

        $stmt = $this->conn->prepare($typeQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $byType = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // By course
        $courseQuery = "SELECT c.course_id, c.title,
                          COUNT(mf.media_id) as file_count,
                          COALESCE(SUM(mf.file_size), 0) as total_bytes
                        FROM courses c
                        LEFT JOIN media mf ON c.course_id = mf.course_id
                        WHERE c.tenant_id = ?
                        GROUP BY c.course_id, c.title
                        ORDER BY total_bytes DESC
                        LIMIT 20";

        $stmt = $this->conn->prepare($courseQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $byCourse = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Format data
        $typeData = [];
        $totalBytes = 0;
        foreach ($byType as $row) {
            $bytes = (int)$row['total_bytes'];
            $totalBytes += $bytes;
            $typeData[] = [
                'file_type' => $row['file_type'],
                'file_count' => (int)$row['file_count'],
                'total_size' => $this->formatBytes($bytes),
                'total_bytes' => $bytes
            ];
        }

        $courseData = [];
        foreach ($byCourse as $row) {
            $bytes = (int)$row['total_bytes'];
            $courseData[] = [
                'course_id' => (int)$row['course_id'],
                'course_title' => $row['title'],
                'file_count' => (int)$row['file_count'],
                'total_size' => $this->formatBytes($bytes),
                'total_bytes' => $bytes
            ];
        }

        // Get quota if exists
        $quotaQuery = "SELECT storage_quota_bytes FROM tenant_settings WHERE tenant_id = ?";
        $stmt = $this->conn->prepare($quotaQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $quotaResult = $stmt->get_result()->fetch_assoc();
        $quota = (int)($quotaResult['storage_quota_bytes'] ?? 0);

        return [
            'report_name' => 'Storage Usage Report',
            'summary' => [
                'total_storage_used' => $this->formatBytes($totalBytes),
                'total_bytes' => $totalBytes,
                'storage_quota' => $quota > 0 ? $this->formatBytes($quota) : 'Unlimited',
                'quota_bytes' => $quota,
                'usage_percentage' => $quota > 0 ? round(($totalBytes / $quota) * 100, 1) : 0
            ],
            'by_file_type' => $typeData,
            'by_course' => $courseData
        ];
    }

    /**
     * Report 63: Integration Sync Report
     */
    public function integrationSyncReport(): array
    {
        $query = "SELECT isl.id, isl.integration_name, isl.sync_type,
                    isl.started_at, isl.completed_at,
                    isl.status, isl.records_processed, isl.records_created,
                    isl.records_updated, isl.records_failed,
                    isl.error_message, isl.details_json
                  FROM integration_sync_log isl
                  WHERE isl.tenant_id = ?
                  ORDER BY isl.started_at DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'sync_id' => (int)$row['id'],
                'integration' => $row['integration_name'],
                'sync_type' => $row['sync_type'],
                'started_at' => $row['started_at'],
                'completed_at' => $row['completed_at'],
                'duration' => $row['completed_at'] && $row['started_at'] ?
                    $this->formatDuration((strtotime($row['completed_at']) - strtotime($row['started_at'])) / 60) : 'In Progress',
                'status' => $row['status'],
                'records_processed' => (int)$row['records_processed'],
                'records_created' => (int)$row['records_created'],
                'records_updated' => (int)$row['records_updated'],
                'records_failed' => (int)$row['records_failed'],
                'error_message' => $row['error_message']
            ];
        }

        // Get integration status summary
        $statusQuery = "SELECT integration_name, 
                          MAX(started_at) as last_sync,
                          SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count,
                          SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as fail_count
                        FROM integration_sync_log WHERE tenant_id = ?
                        AND started_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                        GROUP BY integration_name";
        $stmt = $this->conn->prepare($statusQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $summary = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        return [
            'report_name' => 'Integration Sync Report',
            'data' => $data,
            'meta' => $this->buildMeta(count($data)),
            'integration_summary' => $summary
        ];
    }

    /**
     * Report 64: Tenant Usage Report (for multi-tenant admin)
     */
    public function tenantUsageReport(): array
    {
        $query = "SELECT t.tenant_id, t.name, t.created_at,
                    (SELECT COUNT(*) FROM users WHERE tenant_id = t.tenant_id AND is_active = 1) as active_users,
                    (SELECT COUNT(*) FROM courses WHERE tenant_id = t.tenant_id AND is_active = 1) as active_courses,
                    (SELECT COUNT(*) FROM course_enrollments WHERE tenant_id = t.tenant_id AND is_active = 1) as total_enrollments,
                    (SELECT COUNT(*) FROM learning_sessions WHERE tenant_id = t.tenant_id 
                     AND started_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as sessions_30d,
                    (SELECT COALESCE(SUM(file_size), 0) FROM media WHERE tenant_id = t.tenant_id) as storage_bytes,
                    t.storage_limit_mb
                  FROM tenants t
                  WHERE t.is_active = 1
                  ORDER BY active_users DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("ii", $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $storageUsed = (int)$row['storage_bytes'];
            $quota = (int)$row['storage_quota_bytes'];

            $data[] = [
                'tenant_id' => (int)$row['tenant_id'],
                'tenant_name' => $row['tenant_name'],
                'created_at' => $row['created_at'],
                'active_users' => (int)$row['active_users'],
                'active_courses' => (int)$row['active_courses'],
                'total_enrollments' => (int)$row['total_enrollments'],
                'sessions_30d' => (int)$row['sessions_30d'],
                'storage_used' => $this->formatBytes($storageUsed),
                'storage_quota' => $quota > 0 ? $this->formatBytes($quota) : 'Unlimited',
                'storage_percent' => $quota > 0 ? round(($storageUsed / $quota) * 100, 1) : 0
            ];
        }

        return [
            'report_name' => 'Tenant Usage Report',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    public function getSummary(): array
    {
        return $this->systemUsageReport();
    }
}
