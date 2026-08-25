<?php

/**
 * AuditLogger - Comprehensive audit logging for compliance and security
 * Handles audit_log, admin_activity_log, and data_access_log
 */
class AuditLogger {
    private mysqli $conn;
    private int $tenant_id;
    private ?int $user_id = null;

    // Action categories
    const CATEGORY_USER = 'user';
    const CATEGORY_COURSE = 'course';
    const CATEGORY_ENROLLMENT = 'enrollment';
    const CATEGORY_ASSESSMENT = 'assessment';
    const CATEGORY_CERTIFICATE = 'certificate';
    const CATEGORY_SYSTEM = 'system';
    const CATEGORY_SECURITY = 'security';
    const CATEGORY_DATA = 'data';

    // Action types
    const ACTION_CREATE = 'create';
    const ACTION_UPDATE = 'update';
    const ACTION_DELETE = 'delete';
    const ACTION_VIEW = 'view';
    const ACTION_EXPORT = 'export';
    const ACTION_LOGIN = 'login';
    const ACTION_LOGOUT = 'logout';
    const ACTION_PERMISSION_CHANGE = 'permission_change';

    public function __construct(mysqli $db) {
        $this->conn = $db;
    }

    public function setContext(int $tenant_id, ?int $user_id = null): self {
        $this->tenant_id = $tenant_id;
        $this->user_id = $user_id;
        return $this;
    }

    // ==========================================
    // GENERAL AUDIT LOG
    // ==========================================

    /**
     * Log an audit event
     */
    public function log(
        string $action_type,
        string $action_category,
        ?string $entity_type = null,
        ?string $entity_id = null,
        ?string $entity_name = null,
        ?array $old_values = null,
        ?array $new_values = null
    ): bool {
        $query = "INSERT INTO audit_log 
                  (tenant_id, user_id, action_type, action_category, entity_type, entity_id, 
                   entity_name, old_values, new_values, ip_address, user_agent, session_id, created_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $this->conn->prepare($query);
        
        $old_json = $old_values ? json_encode($old_values) : null;
        $new_json = $new_values ? json_encode($new_values) : null;
        $ip = $this->getClientIP();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $sessionId = session_id() ?: null;

        $stmt->bind_param("iissssssssss",
            $this->tenant_id,
            $this->user_id,
            $action_type,
            $action_category,
            $entity_type,
            $entity_id,
            $entity_name,
            $old_json,
            $new_json,
            $ip,
            $userAgent,
            $sessionId
        );

        return $stmt->execute();
    }

    /**
     * Log entity creation
     */
    public function logCreate(string $entity_type, $entity_id, string $entity_name, array $data = []): bool {
        return $this->log(
            self::ACTION_CREATE,
            $this->getCategoryForEntity($entity_type),
            $entity_type,
            (string) $entity_id,
            $entity_name,
            null,
            $this->sanitizeData($data)
        );
    }

    /**
     * Log entity update
     */
    public function logUpdate(string $entity_type, $entity_id, string $entity_name, array $old_data, array $new_data): bool {
        // Only log changed fields
        $changes = $this->getChangedFields($old_data, $new_data);
        if (empty($changes['old']) && empty($changes['new'])) {
            return true; // No changes
        }

        return $this->log(
            self::ACTION_UPDATE,
            $this->getCategoryForEntity($entity_type),
            $entity_type,
            (string) $entity_id,
            $entity_name,
            $this->sanitizeData($changes['old']),
            $this->sanitizeData($changes['new'])
        );
    }

    /**
     * Log entity deletion
     */
    public function logDelete(string $entity_type, $entity_id, string $entity_name, array $data = []): bool {
        return $this->log(
            self::ACTION_DELETE,
            $this->getCategoryForEntity($entity_type),
            $entity_type,
            (string) $entity_id,
            $entity_name,
            $this->sanitizeData($data),
            null
        );
    }

    // ==========================================
    // ADMIN ACTIVITY LOG
    // ==========================================

    /**
     * Log admin activity
     */
    public function logAdminActivity(
        string $action,
        ?string $action_category = null,
        ?string $target_type = null,
        ?string $target_id = null,
        ?string $target_name = null,
        ?array $details = null
    ): bool {
        $query = "INSERT INTO admin_activity_log 
                  (tenant_id, admin_id, action, action_category, target_type, target_id, 
                   target_name, details_json, ip_address, user_agent, created_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $this->conn->prepare($query);
        
        $details_json = $details ? json_encode($details) : null;
        $ip = $this->getClientIP();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $stmt->bind_param("iissssssss",
            $this->tenant_id,
            $this->user_id,
            $action,
            $action_category,
            $target_type,
            $target_id,
            $target_name,
            $details_json,
            $ip,
            $userAgent
        );

        return $stmt->execute();
    }

    // ==========================================
    // DATA ACCESS LOG
    // ==========================================

    /**
     * Log data access
     */
    public function logDataAccess(
        string $data_type,
        ?string $data_id = null,
        ?string $data_description = null,
        string $access_type = 'view',
        ?string $purpose = null
    ): bool {
        $query = "INSERT INTO data_access_log 
                  (tenant_id, user_id, data_type, data_id, data_description, access_type, purpose, ip_address, accessed_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $this->conn->prepare($query);
        $ip = $this->getClientIP();

        $stmt->bind_param("iissssss",
            $this->tenant_id,
            $this->user_id,
            $data_type,
            $data_id,
            $data_description,
            $access_type,
            $purpose,
            $ip
        );

        return $stmt->execute();
    }

    /**
     * Log report view
     */
    public function logReportAccess(string $report_key, string $report_name, ?array $params = null): bool {
        return $this->logDataAccess(
            'report',
            $report_key,
            $report_name,
            'view',
            $params ? json_encode($params) : null
        );
    }

    /**
     * Log data export
     */
    public function logDataExport(string $data_type, ?string $data_id, string $description, ?string $format = 'csv'): bool {
        return $this->logDataAccess(
            $data_type,
            $data_id,
            $description,
            'export',
            "Exported as $format"
        );
    }

    // ==========================================
    // QUERY METHODS
    // ==========================================

    /**
     * Get audit log entries
     */
    public function getAuditLog(array $filters = [], int $page = 1, int $per_page = 50): array {
        $query = "SELECT al.*, 
                         CONCAT(u.first_name, ' ', u.last_name) as user_name,
                         u.email as user_email
                  FROM audit_log al
                  LEFT JOIN users u ON al.user_id = u.id
                  WHERE al.tenant_id = ?";
        $params = [$this->tenant_id];
        $types = "i";

        if (!empty($filters['user_id'])) {
            $query .= " AND al.user_id = ?";
            $params[] = $filters['user_id'];
            $types .= "i";
        }
        if (!empty($filters['action_type'])) {
            $query .= " AND al.action_type = ?";
            $params[] = $filters['action_type'];
            $types .= "s";
        }
        if (!empty($filters['action_category'])) {
            $query .= " AND al.action_category = ?";
            $params[] = $filters['action_category'];
            $types .= "s";
        }
        if (!empty($filters['entity_type'])) {
            $query .= " AND al.entity_type = ?";
            $params[] = $filters['entity_type'];
            $types .= "s";
        }
        if (!empty($filters['date_from'])) {
            $query .= " AND al.created_at >= ?";
            $params[] = $filters['date_from'];
            $types .= "s";
        }
        if (!empty($filters['date_to'])) {
            $query .= " AND al.created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
            $types .= "s";
        }

        $query .= " ORDER BY al.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $per_page;
        $params[] = ($page - 1) * $per_page;
        $types .= "ii";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get entity history
     */
    public function getEntityHistory(string $entity_type, $entity_id): array {
        $query = "SELECT al.*, 
                         CONCAT(u.first_name, ' ', u.last_name) as user_name
                  FROM audit_log al
                  LEFT JOIN users u ON al.user_id = u.id
                  WHERE al.tenant_id = ? AND al.entity_type = ? AND al.entity_id = ?
                  ORDER BY al.created_at DESC";
        
        $stmt = $this->conn->prepare($query);
        $entity_id_str = (string) $entity_id;
        $stmt->bind_param("iss", $this->tenant_id, $entity_type, $entity_id_str);
        $stmt->execute();

        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // ==========================================
    // HELPER METHODS
    // ==========================================

    /**
     * Get category for entity type
     */
    private function getCategoryForEntity(string $entity_type): string {
        $mapping = [
            'user' => self::CATEGORY_USER,
            'course' => self::CATEGORY_COURSE,
            'lesson' => self::CATEGORY_COURSE,
            'module' => self::CATEGORY_COURSE,
            'enrollment' => self::CATEGORY_ENROLLMENT,
            'assessment' => self::CATEGORY_ASSESSMENT,
            'quiz' => self::CATEGORY_ASSESSMENT,
            'certificate' => self::CATEGORY_CERTIFICATE,
            'badge' => self::CATEGORY_USER,
            'role' => self::CATEGORY_SECURITY,
            'permission' => self::CATEGORY_SECURITY,
        ];

        return $mapping[$entity_type] ?? self::CATEGORY_SYSTEM;
    }

    /**
     * Get changed fields between old and new data
     */
    private function getChangedFields(array $old, array $new): array {
        $old_changes = [];
        $new_changes = [];

        foreach ($new as $key => $value) {
            if (!isset($old[$key]) || $old[$key] !== $value) {
                $old_changes[$key] = $old[$key] ?? null;
                $new_changes[$key] = $value;
            }
        }

        return ['old' => $old_changes, 'new' => $new_changes];
    }

    /**
     * Sanitize data for logging (remove sensitive fields)
     */
    private function sanitizeData(array $data): array {
        $sensitive = ['password', 'password_hash', 'token', 'api_key', 'secret', 'credit_card', 'ssn'];
        
        foreach ($sensitive as $field) {
            if (isset($data[$field])) {
                $data[$field] = '[REDACTED]';
            }
        }

        return $data;
    }

    /**
     * Get client IP address
     */
    private function getClientIP(): string {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
