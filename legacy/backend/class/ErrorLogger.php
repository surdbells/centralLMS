<?php

/**
 * ErrorLogger - System error logging and tracking
 * Used for system reports and debugging
 */
class ErrorLogger {
    private mysqli $conn;
    private ?int $tenant_id = null;

    public function __construct(mysqli $db) {
        $this->conn = $db;
    }

    public function setTenant(?int $tenant_id): self {
        $this->tenant_id = $tenant_id;
        return $this;
    }

    /**
     * Log an error
     */
    public function logError(
        string $error_type,
        string $error_message,
        ?string $error_code = null,
        ?string $file_path = null,
        ?int $line_number = null,
        ?string $stack_trace = null,
        ?int $user_id = null
    ): int {
        $query = "INSERT INTO error_log 
                  (tenant_id, user_id, error_type, error_code, error_message, 
                   file_path, line_number, stack_trace, ip_address, user_agent, 
                   request_url, request_method, request_body, created_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $this->conn->prepare($query);
        
        $ip = $this->getClientIP();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $requestUrl = $_SERVER['REQUEST_URI'] ?? null;
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $requestBody = $this->getRequestBody();

        $stmt->bind_param("iisssisssssss",
            $this->tenant_id,
            $user_id,
            $error_type,
            $error_code,
            $error_message,
            $file_path,
            $line_number,
            $stack_trace,
            $ip,
            $userAgent,
            $requestUrl,
            $requestMethod,
            $requestBody
        );

        $stmt->execute();
        return $this->conn->insert_id;
    }

    /**
     * Log exception
     */
    public function logException(Throwable $exception, ?int $user_id = null): int {
        return $this->logError(
            get_class($exception),
            $exception->getMessage(),
            (string) $exception->getCode(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString(),
            $user_id
        );
    }

    /**
     * Log PHP error
     */
    public function logPhpError(int $errno, string $errstr, string $errfile, int $errline): int {
        $errorTypes = [
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_STRICT => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED'
        ];

        return $this->logError(
            $errorTypes[$errno] ?? 'UNKNOWN',
            $errstr,
            (string) $errno,
            $errfile,
            $errline
        );
    }

    /**
     * Mark error as resolved
     */
    public function resolveError(int $error_id, int $resolved_by, ?string $notes = null): bool {
        $query = "UPDATE error_log 
                  SET is_resolved = 1, resolved_at = NOW(), resolved_by = ?, resolution_notes = ?
                  WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("isi", $resolved_by, $notes, $error_id);
        return $stmt->execute();
    }

    /**
     * Get errors
     */
    public function getErrors(array $filters = [], int $page = 1, int $per_page = 50): array {
        $query = "SELECT el.*, 
                         CONCAT(u.first_name, ' ', u.last_name) as user_name,
                         CONCAT(r.first_name, ' ', r.last_name) as resolved_by_name
                  FROM error_log el
                  LEFT JOIN users u ON el.user_id = u.id
                  LEFT JOIN users r ON el.resolved_by = r.id
                  WHERE 1=1";
        
        $params = [];
        $types = "";

        if ($this->tenant_id) {
            $query .= " AND el.tenant_id = ?";
            $params[] = $this->tenant_id;
            $types .= "i";
        }

        if (!empty($filters['error_type'])) {
            $query .= " AND el.error_type = ?";
            $params[] = $filters['error_type'];
            $types .= "s";
        }

        if (isset($filters['is_resolved'])) {
            $query .= " AND el.is_resolved = ?";
            $params[] = $filters['is_resolved'] ? 1 : 0;
            $types .= "i";
        }

        if (!empty($filters['date_from'])) {
            $query .= " AND el.created_at >= ?";
            $params[] = $filters['date_from'];
            $types .= "s";
        }

        if (!empty($filters['date_to'])) {
            $query .= " AND el.created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
            $types .= "s";
        }

        if (!empty($filters['search'])) {
            $query .= " AND (el.error_message LIKE ? OR el.file_path LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $types .= "ss";
        }

        $query .= " ORDER BY el.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $per_page;
        $params[] = ($page - 1) * $per_page;
        $types .= "ii";

        $stmt = $this->conn->prepare($query);
        if (!empty($types)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();

        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get error statistics
     */
    public function getErrorStats(int $days = 30): array {
        $query = "SELECT 
                    COUNT(*) as total_errors,
                    SUM(CASE WHEN is_resolved = 0 THEN 1 ELSE 0 END) as unresolved_count,
                    COUNT(DISTINCT error_type) as unique_error_types
                  FROM error_log 
                  WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
        
        $params = [$days];
        $types = "i";

        if ($this->tenant_id) {
            $query .= " AND tenant_id = ?";
            $params[] = $this->tenant_id;
            $types .= "i";
        }

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();

        // Get error breakdown by type
        $query = "SELECT error_type, COUNT(*) as count
                  FROM error_log 
                  WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
        $params = [$days];
        $types = "i";

        if ($this->tenant_id) {
            $query .= " AND tenant_id = ?";
            $params[] = $this->tenant_id;
            $types .= "i";
        }

        $query .= " GROUP BY error_type ORDER BY count DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stats['by_type'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        return $stats;
    }

    /**
     * Get error trends
     */
    public function getErrorTrends(int $days = 30): array {
        $query = "SELECT DATE(created_at) as date, COUNT(*) as count
                  FROM error_log 
                  WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
        $params = [$days];
        $types = "i";

        if ($this->tenant_id) {
            $query .= " AND tenant_id = ?";
            $params[] = $this->tenant_id;
            $types .= "i";
        }

        $query .= " GROUP BY DATE(created_at) ORDER BY date";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // ==========================================
    // HELPER METHODS
    // ==========================================

    private function getClientIP(): string {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private function getRequestBody(): ?string {
        $body = file_get_contents('php://input');
        if (empty($body)) {
            return null;
        }
        
        // Sanitize sensitive data
        $decoded = json_decode($body, true);
        if ($decoded && is_array($decoded)) {
            $sensitive = ['password', 'token', 'api_key', 'secret', 'credit_card'];
            foreach ($sensitive as $field) {
                if (isset($decoded[$field])) {
                    $decoded[$field] = '[REDACTED]';
                }
            }
            return json_encode($decoded);
        }
        
        return substr($body, 0, 1000); // Limit size
    }

    /**
     * Register as PHP error handler
     */
    public function registerErrorHandler(): void {
        set_error_handler([$this, 'logPhpError']);
        set_exception_handler(function (Throwable $e) {
            $this->logException($e);
            throw $e; // Re-throw after logging
        });
    }
}
