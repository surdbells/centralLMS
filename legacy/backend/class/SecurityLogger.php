<?php

/**
 * SecurityLogger - Security event logging and tracking
 * Handles login_attempts, user_login_history, password_change_log, security_alerts
 */
class SecurityLogger {
    private mysqli $conn;
    private int $tenant_id;

    // Alert severities
    const SEVERITY_LOW = 'low';
    const SEVERITY_MEDIUM = 'medium';
    const SEVERITY_HIGH = 'high';
    const SEVERITY_CRITICAL = 'critical';

    // Alert types
    const ALERT_FAILED_LOGIN = 'failed_login_attempt';
    const ALERT_BRUTE_FORCE = 'brute_force_detected';
    const ALERT_SUSPICIOUS_IP = 'suspicious_ip';
    const ALERT_PERMISSION_ESCALATION = 'permission_escalation';
    const ALERT_UNUSUAL_ACTIVITY = 'unusual_activity';
    const ALERT_DATA_EXPORT = 'large_data_export';
    const ALERT_ACCOUNT_LOCKED = 'account_locked';

    public function __construct(mysqli $db) {
        $this->conn = $db;
    }

    public function setTenant(int $tenant_id): self {
        $this->tenant_id = $tenant_id;
        return $this;
    }

    // ==========================================
    // LOGIN ATTEMPTS
    // ==========================================

    /**
     * Log a login attempt
     */
    public function logLoginAttempt(string $email, bool $success, ?string $failure_reason = null): bool {
        $query = "INSERT INTO login_attempts 
                  (tenant_id, email, ip_address, user_agent, success, failure_reason, created_at)
                  VALUES (?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $this->conn->prepare($query);
        $ip = $this->getClientIP();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $successInt = $success ? 1 : 0;

        $stmt->bind_param("isssis",
            $this->tenant_id,
            $email,
            $ip,
            $userAgent,
            $successInt,
            $failure_reason
        );

        $result = $stmt->execute();

        // Check for brute force
        if (!$success) {
            $this->checkBruteForce($email, $ip);
        }

        return $result;
    }

    /**
     * Check for brute force attacks
     */
    private function checkBruteForce(string $email, string $ip): void {
        // Check failed attempts in last 15 minutes
        $query = "SELECT COUNT(*) as attempts FROM login_attempts 
                  WHERE (email = ? OR ip_address = ?) 
                  AND success = 0 
                  AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ss", $email, $ip);
        $stmt->execute();
        $result = $stmt->get_result();
        $attempts = $result->fetch_assoc()['attempts'];

        if ($attempts >= 5) {
            $this->createAlert(
                self::ALERT_BRUTE_FORCE,
                self::SEVERITY_HIGH,
                "Multiple failed login attempts detected for $email from IP $ip",
                ['email' => $email, 'ip' => $ip, 'attempts' => $attempts]
            );
        }
    }

    /**
     * Get recent login attempts for email
     */
    public function getLoginAttempts(string $email, int $limit = 10): array {
        $query = "SELECT * FROM login_attempts 
                  WHERE email = ? 
                  ORDER BY created_at DESC 
                  LIMIT ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("si", $email, $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // ==========================================
    // USER LOGIN HISTORY
    // ==========================================

    /**
     * Record successful login
     */
    public function recordLogin(int $user_id, string $login_method = 'password'): int {
        $query = "INSERT INTO user_login_history 
                  (tenant_id, user_id, login_at, ip_address, device_type, browser, os, 
                   location_city, location_country, login_method, login_success)
                  VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, 1)";
        
        $stmt = $this->conn->prepare($query);
        
        $ip = $this->getClientIP();
        $deviceType = $this->detectDeviceType();
        $browser = $this->detectBrowser();
        $os = $this->detectOS();
        $location = $this->getLocationFromIP($ip);

        $stmt->bind_param("iisssssss",
            $this->tenant_id,
            $user_id,
            $ip,
            $deviceType,
            $browser,
            $os,
            $location['city'],
            $location['country'],
            $login_method
        );

        $stmt->execute();
        
        // Update user's last_login_at
        $this->updateUserLastLogin($user_id);
        
        return $this->conn->insert_id;
    }

    /**
     * Record logout
     */
    public function recordLogout(int $login_history_id): bool {
        $query = "UPDATE user_login_history 
                  SET logout_at = NOW(),
                      session_duration_minutes = TIMESTAMPDIFF(MINUTE, login_at, NOW())
                  WHERE id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $login_history_id, $this->tenant_id);
        return $stmt->execute();
    }

    /**
     * Update user's last login timestamp
     */
    private function updateUserLastLogin(int $user_id): void {
        $query = "UPDATE users SET last_login_at = NOW() WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
    }

    /**
     * Get user's login history
     */
    public function getUserLoginHistory(int $user_id, int $limit = 20): array {
        $query = "SELECT * FROM user_login_history 
                  WHERE user_id = ? AND tenant_id = ?
                  ORDER BY login_at DESC 
                  LIMIT ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iii", $user_id, $this->tenant_id, $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // ==========================================
    // PASSWORD CHANGES
    // ==========================================

    /**
     * Log password change
     */
    public function logPasswordChange(int $user_id, string $change_type = 'user_initiated'): bool {
        $query = "INSERT INTO password_change_log 
                  (tenant_id, user_id, changed_at, change_type, ip_address)
                  VALUES (?, ?, NOW(), ?, ?)";
        
        $stmt = $this->conn->prepare($query);
        $ip = $this->getClientIP();
        
        $stmt->bind_param("iiss", $this->tenant_id, $user_id, $change_type, $ip);
        return $stmt->execute();
    }

    /**
     * Get password change history
     */
    public function getPasswordHistory(int $user_id): array {
        $query = "SELECT * FROM password_change_log 
                  WHERE user_id = ? AND tenant_id = ?
                  ORDER BY changed_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // ==========================================
    // SECURITY ALERTS
    // ==========================================

    /**
     * Create security alert
     */
    public function createAlert(
        string $alert_type,
        string $severity,
        string $description,
        ?array $details = null,
        ?int $user_id = null
    ): int {
        $query = "INSERT INTO security_alerts 
                  (tenant_id, user_id, alert_type, severity, description, details_json, ip_address, created_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $this->conn->prepare($query);
        $details_json = $details ? json_encode($details) : null;
        $ip = $this->getClientIP();

        $stmt->bind_param("iisssss",
            $this->tenant_id,
            $user_id,
            $alert_type,
            $severity,
            $description,
            $details_json,
            $ip
        );

        $stmt->execute();
        return $this->conn->insert_id;
    }

    /**
     * Resolve security alert
     */
    public function resolveAlert(int $alert_id, int $resolved_by): bool {
        $query = "UPDATE security_alerts 
                  SET is_resolved = 1, resolved_at = NOW(), resolved_by = ?
                  WHERE id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iii", $resolved_by, $alert_id, $this->tenant_id);
        return $stmt->execute();
    }

    /**
     * Get unresolved alerts
     */
    public function getUnresolvedAlerts(?string $severity = null): array {
        $query = "SELECT sa.*, 
                         CONCAT(u.first_name, ' ', u.last_name) as user_name
                  FROM security_alerts sa
                  LEFT JOIN users u ON sa.user_id = u.id
                  WHERE sa.tenant_id = ? AND sa.is_resolved = 0";
        $params = [$this->tenant_id];
        $types = "i";

        if ($severity) {
            $query .= " AND sa.severity = ?";
            $params[] = $severity;
            $types .= "s";
        }

        $query .= " ORDER BY FIELD(sa.severity, 'critical', 'high', 'medium', 'low'), sa.created_at DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Log permission change
     */
    public function logPermissionChange(int $user_id, int $changed_by, array $old_permissions, array $new_permissions): void {
        $this->createAlert(
            self::ALERT_PERMISSION_ESCALATION,
            self::SEVERITY_MEDIUM,
            "User permissions modified",
            [
                'user_id' => $user_id,
                'changed_by' => $changed_by,
                'old_permissions' => $old_permissions,
                'new_permissions' => $new_permissions
            ],
            $user_id
        );
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

    private function detectDeviceType(): string {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (preg_match('/mobile|android|iphone|ipod/i', $userAgent)) {
            return 'mobile';
        }
        if (preg_match('/ipad|tablet/i', $userAgent)) {
            return 'tablet';
        }
        return 'desktop';
    }

    private function detectBrowser(): string {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (strpos($userAgent, 'Chrome') !== false) return 'Chrome';
        if (strpos($userAgent, 'Firefox') !== false) return 'Firefox';
        if (strpos($userAgent, 'Safari') !== false) return 'Safari';
        if (strpos($userAgent, 'Edge') !== false) return 'Edge';
        if (strpos($userAgent, 'MSIE') !== false || strpos($userAgent, 'Trident') !== false) return 'IE';
        return 'Unknown';
    }

    private function detectOS(): string {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (strpos($userAgent, 'Windows') !== false) return 'Windows';
        if (strpos($userAgent, 'Mac') !== false) return 'macOS';
        if (strpos($userAgent, 'Linux') !== false) return 'Linux';
        if (strpos($userAgent, 'Android') !== false) return 'Android';
        if (strpos($userAgent, 'iOS') !== false || strpos($userAgent, 'iPhone') !== false) return 'iOS';
        return 'Unknown';
    }

    private function getLocationFromIP(string $ip): array {
        // In production, use a geo-IP service
        // This is a placeholder
        return [
            'city' => null,
            'country' => null
        ];
    }
}
