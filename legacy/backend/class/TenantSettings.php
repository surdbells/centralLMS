<?php

/**
 * Tenant Settings Manager
 * Handles all tenant-specific configuration settings
 * For Multi-Tenant LMS Admin
 */

class TenantSettings
{
    private mysqli $conn;
    private int $tenant_id;

    // Default settings structure
    private array $defaultSettings = [
        'general' => [
            'company_name' => '',
            'company_email' => '',
            'company_phone' => '',
            'company_address' => '',
            'timezone' => 'Africa/Lagos',
            'date_format' => 'Y-m-d',
            'time_format' => 'H:i',
            'language' => 'en',
            'currency' => 'NGN'
        ],
        'branding' => [
            'logo_url' => '',
            'favicon_url' => '',
            'primary_color' => '#c9a227',
            'secondary_color' => '#1a1a2e',
            'accent_color' => '#e8b923',
            'login_background_url' => '',
            'email_header_logo_url' => '',
            'show_powered_by' => true
        ],
        'learning' => [
            'default_course_duration_days' => 30,
            'allow_self_enrollment' => true,
            'require_sequential_lessons' => false,
            'video_completion_threshold' => 90,
            'quiz_passing_score' => 70,
            'max_quiz_attempts' => 3,
            'show_correct_answers' => true,
            'certificate_enabled' => true,
            'certificate_expiry_days' => 365,
            'auto_enroll_new_users' => false,
            'mandatory_course_reminder_days' => [7, 3, 1]
        ],
        'gamification' => [
            'enabled' => true,
            'points_per_lesson' => 10,
            'points_per_course' => 100,
            'points_per_quiz_correct' => 5,
            'points_per_certificate' => 50,
            'leaderboard_enabled' => true,
            'leaderboard_display_count' => 10,
            'badges_enabled' => true,
            'streaks_enabled' => true,
            'streak_bonus_multiplier' => 1.5
        ],
        'notifications' => [
            'email_notifications_enabled' => true,
            'enrollment_notification' => true,
            'course_completion_notification' => true,
            'certificate_notification' => true,
            'deadline_reminder_notification' => true,
            'quiz_result_notification' => true,
            'new_course_notification' => true,
            'weekly_progress_digest' => false,
            'manager_progress_reports' => true,
            'notification_from_name' => '',
            'notification_from_email' => ''
        ],
        'security' => [
            'password_min_length' => 8,
            'password_require_uppercase' => true,
            'password_require_lowercase' => true,
            'password_require_number' => true,
            'password_require_special' => false,
            'password_expiry_days' => 0,
            'max_login_attempts' => 5,
            'lockout_duration_minutes' => 30,
            'session_timeout_minutes' => 60,
            'force_password_change_first_login' => true,
            'two_factor_enabled' => false,
            'allowed_ip_ranges' => []
        ],
        'storage' => [
            'max_file_size_mb' => 100,
            'allowed_file_types' => ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'mp4', 'mp3', 'zip'],
            'video_max_size_mb' => 500,
            'audio_max_size_mb' => 100,
            'document_max_size_mb' => 50,
            'scorm_max_size_mb' => 200
        ],
        'integrations' => [
            'sso_enabled' => false,
            'sso_provider' => '',
            'sso_config' => [],
            'api_access_enabled' => false,
            'webhook_url' => '',
            'webhook_events' => []
        ]
    ];

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function setTenant(int $tenant_id): void
    {
        $this->tenant_id = $tenant_id;
    }

    // ==========================================
    // SETTINGS RETRIEVAL
    // ==========================================

    /**
     * Get all settings for tenant
     */
    public function getAllSettings(): array
    {
        $settings = $this->defaultSettings;

        // Load saved settings from database
        $stmt = $this->conn->prepare("
            SELECT setting_category, setting_key, setting_value, value_type
            FROM tenant_settings
            WHERE tenant_id = ?
        ");
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $category = $row['setting_category'];
            $key = $row['setting_key'];
            $value = $this->castValue($row['setting_value'], $row['value_type']);

            if (isset($settings[$category])) {
                $settings[$category][$key] = $value;
            }
        }
        $stmt->close();

        return $settings;
    }

    /**
     * Get settings for a specific category
     */
    public function getCategorySettings(string $category): array
    {
        if (!isset($this->defaultSettings[$category])) {
            return [];
        }

        $settings = $this->defaultSettings[$category];

        $stmt = $this->conn->prepare("
            SELECT setting_key, setting_value, value_type
            FROM tenant_settings
            WHERE tenant_id = ? AND setting_category = ?
        ");
        $stmt->bind_param("is", $this->tenant_id, $category);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $key = $row['setting_key'];
            if (array_key_exists($key, $settings)) {
                $settings[$key] = $this->castValue($row['setting_value'], $row['value_type']);
            }
        }
        $stmt->close();

        return $settings;
    }

    /**
     * Get a single setting value
     */
    public function getSetting(string $category, string $key, $default = null)
    {
        $stmt = $this->conn->prepare("
            SELECT setting_value, value_type
            FROM tenant_settings
            WHERE tenant_id = ? AND setting_category = ? AND setting_key = ?
        ");
        $stmt->bind_param("iss", $this->tenant_id, $category, $key);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row) {
            return $this->castValue($row['setting_value'], $row['value_type']);
        }

        // Return default from schema or provided default
        if ($default !== null) {
            return $default;
        }

        return $this->defaultSettings[$category][$key] ?? null;
    }

    // ==========================================
    // SETTINGS UPDATE
    // ==========================================

    /**
     * Update settings for a category
     */
    public function updateCategorySettings(string $category, array $settings, int $updated_by): array
    {
        if (!isset($this->defaultSettings[$category])) {
            return ['success' => false, 'message' => 'Invalid category'];
        }

        $validKeys = array_keys($this->defaultSettings[$category]);
        $updated = 0;
        $errors = [];

        foreach ($settings as $key => $value) {
            if (!in_array($key, $validKeys)) {
                continue; // Skip unknown keys
            }

            // Validate setting
            $validation = $this->validateSetting($category, $key, $value);
            if (!$validation['valid']) {
                $errors[$key] = $validation['message'];
                continue;
            }

            // Determine value type
            $valueType = $this->determineValueType($value);
            $serializedValue = $this->serializeValue($value, $valueType);

            // Upsert setting
            $stmt = $this->conn->prepare("
                INSERT INTO tenant_settings 
                    (tenant_id, setting_category, setting_key, setting_value, value_type, updated_by, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    setting_value = VALUES(setting_value),
                    value_type = VALUES(value_type),
                    updated_by = VALUES(updated_by),
                    updated_at = NOW()
            ");
            $stmt->bind_param("issssi", $this->tenant_id, $category, $key, $serializedValue, $valueType, $updated_by);

            if ($stmt->execute()) {
                $updated++;
            }
            $stmt->close();
        }

        // Log the change
        $this->logSettingsChange($category, $settings, $updated_by);

        return [
            'success' => empty($errors),
            'message' => empty($errors)
                ? "$updated setting(s) updated successfully"
                : "Some settings could not be updated",
            'updated_count' => $updated,
            'errors' => $errors
        ];
    }

    /**
     * Update a single setting
     */
    public function updateSetting(string $category, string $key, $value, int $updated_by): array
    {
        return $this->updateCategorySettings($category, [$key => $value], $updated_by);
    }

    /**
     * Reset category to defaults
     */
    public function resetCategoryToDefaults(string $category, int $updated_by): array
    {
        if (!isset($this->defaultSettings[$category])) {
            return ['success' => false, 'message' => 'Invalid category'];
        }

        $stmt = $this->conn->prepare("
            DELETE FROM tenant_settings
            WHERE tenant_id = ? AND setting_category = ?
        ");
        $stmt->bind_param("is", $this->tenant_id, $category);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        $this->logSettingsChange($category, ['action' => 'reset_to_defaults'], $updated_by);

        return [
            'success' => true,
            'message' => "Category '$category' reset to defaults",
            'deleted_count' => $affected
        ];
    }

    // ==========================================
    // VALIDATION
    // ==========================================

    /**
     * Validate a setting value
     */
    private function validateSetting(string $category, string $key, $value): array
    {
        // General validations
        switch ("$category.$key") {
            // General
            case 'general.company_email':
                if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return ['valid' => false, 'message' => 'Invalid email format'];
                }
                break;

            case 'general.timezone':
                if (!in_array($value, timezone_identifiers_list())) {
                    return ['valid' => false, 'message' => 'Invalid timezone'];
                }
                break;

            // Learning
            case 'learning.video_completion_threshold':
            case 'learning.quiz_passing_score':
                if (!is_numeric($value) || $value < 0 || $value > 100) {
                    return ['valid' => false, 'message' => 'Value must be between 0 and 100'];
                }
                break;

            case 'learning.max_quiz_attempts':
                if (!is_numeric($value) || $value < 1 || $value > 100) {
                    return ['valid' => false, 'message' => 'Value must be between 1 and 100'];
                }
                break;

            // Gamification
            case 'gamification.points_per_lesson':
            case 'gamification.points_per_course':
            case 'gamification.points_per_quiz_correct':
            case 'gamification.points_per_certificate':
                if (!is_numeric($value) || $value < 0) {
                    return ['valid' => false, 'message' => 'Value must be a positive number'];
                }
                break;

            // Security
            case 'security.password_min_length':
                if (!is_numeric($value) || $value < 6 || $value > 50) {
                    return ['valid' => false, 'message' => 'Password length must be between 6 and 50'];
                }
                break;

            case 'security.max_login_attempts':
                if (!is_numeric($value) || $value < 1 || $value > 20) {
                    return ['valid' => false, 'message' => 'Value must be between 1 and 20'];
                }
                break;

            case 'security.session_timeout_minutes':
                if (!is_numeric($value) || $value < 5 || $value > 1440) {
                    return ['valid' => false, 'message' => 'Session timeout must be between 5 and 1440 minutes'];
                }
                break;

            // Branding colors
            case 'branding.primary_color':
            case 'branding.secondary_color':
            case 'branding.accent_color':
                if (!empty($value) && !preg_match('/^#[a-fA-F0-9]{6}$/', $value)) {
                    return ['valid' => false, 'message' => 'Invalid color format (use #RRGGBB)'];
                }
                break;

            // Storage
            case 'storage.max_file_size_mb':
            case 'storage.video_max_size_mb':
            case 'storage.audio_max_size_mb':
            case 'storage.document_max_size_mb':
            case 'storage.scorm_max_size_mb':
                if (!is_numeric($value) || $value < 1 || $value > 10000) {
                    return ['valid' => false, 'message' => 'Size must be between 1 and 10000 MB'];
                }
                break;

            // Notifications
            case 'notifications.notification_from_email':
                if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return ['valid' => false, 'message' => 'Invalid email format'];
                }
                break;
        }

        return ['valid' => true, 'message' => ''];
    }

    // ==========================================
    // TYPE HANDLING
    // ==========================================

    /**
     * Determine the value type for storage
     */
    private function determineValueType($value): string
    {
        if (is_bool($value)) {
            return 'boolean';
        } elseif (is_int($value)) {
            return 'integer';
        } elseif (is_float($value)) {
            return 'float';
        } elseif (is_array($value)) {
            return 'json';
        } else {
            return 'string';
        }
    }

    /**
     * Serialize value for database storage
     */
    private function serializeValue($value, string $type): string
    {
        switch ($type) {
            case 'boolean':
                return $value ? '1' : '0';
            case 'json':
                return json_encode($value);
            default:
                return (string) $value;
        }
    }

    /**
     * Cast stored value back to original type
     */
    private function castValue(string $value, string $type)
    {
        switch ($type) {
            case 'boolean':
                return $value === '1' || $value === 'true';
            case 'integer':
                return (int) $value;
            case 'float':
                return (float) $value;
            case 'json':
                return json_decode($value, true) ?? [];
            default:
                return $value;
        }
    }

    // ==========================================
    // TENANT INFO
    // ==========================================

    /**
     * Get tenant basic info
     */
    public function getTenantInfo(): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT tenant_id, name, tenant_code, domain, logo_url, 
                   is_active, plan_id, subscription_expires_at,
                   created_at, updated_at
            FROM tenants
            WHERE tenant_id = ?
        ");
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $tenant = $result->fetch_assoc();
        $stmt->close();

        if ($tenant) {
            $tenant['is_active'] = (bool) $tenant['is_active'];
        }

        return $tenant;
    }

    /**
     * Update tenant basic info
     */
    public function updateTenantInfo(array $data, int $updated_by): array
    {
        $allowedFields = ['tenant_name', 'domain', 'logo_url'];
        $updates = [];
        $params = [];
        $types = '';

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
                $types .= 's';
            }
        }

        if (empty($updates)) {
            return ['success' => false, 'message' => 'No valid fields to update'];
        }

        $params[] = $this->tenant_id;
        $types .= 'i';

        $sql = "UPDATE tenants SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE tenant_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $success = $stmt->execute();
        $stmt->close();

        if ($success) {
            $this->logSettingsChange('tenant_info', $data, $updated_by);
        }

        return [
            'success' => $success,
            'message' => $success ? 'Tenant info updated' : 'Failed to update tenant info'
        ];
    }

    // ==========================================
    // USAGE STATISTICS
    // ==========================================

    /**
     * Get tenant usage statistics
     */
    public function getUsageStats(): array
    {
        $stats = [
            'users' => 0,
            'active_users' => 0,
            'courses' => 0,
            'published_courses' => 0,
            'enrollments' => 0,
            'completed_enrollments' => 0,
            'certificates_issued' => 0,
            'storage_used_bytes' => 0,
            'storage_limit_bytes' => 0
        ];

        // User counts
        $stmt = $this->conn->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active
            FROM users WHERE tenant_id = ?
        ");
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stats['users'] = (int) $result['total'];
        $stats['active_users'] = (int) $result['active'];
        $stmt->close();

        // Course counts
        $stmt = $this->conn->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) as published
            FROM courses WHERE tenant_id = ? AND is_active = 1
        ");
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stats['courses'] = (int) $result['total'];
        $stats['published_courses'] = (int) $result['published'];
        $stmt->close();

        // Enrollment counts
        $stmt = $this->conn->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
            FROM course_enrollments WHERE tenant_id = ? AND is_active = 1
        ");
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stats['enrollments'] = (int) $result['total'];
        $stats['completed_enrollments'] = (int) $result['completed'];
        $stmt->close();

        // Certificate count
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as total
            FROM certificates WHERE tenant_id = ?
        ");
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stats['certificates_issued'] = (int) ($result['total'] ?? 0);
        $stmt->close();

        // Storage usage from tenant_storage table
        $stmt = $this->conn->prepare("
            SELECT storage_limit, total_used
            FROM tenant_storage
            WHERE tenant_id = ?
        ");
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        if ($result) {
            $stats['storage_limit_bytes'] = (int) $result['storage_limit'];
            $stats['storage_used_bytes'] = (int) $result['total_used'];
        }
        $stmt->close();

        return $stats;
    }

    // ==========================================
    // SETTINGS HISTORY
    // ==========================================

    /**
     * Log settings change
     */
    private function logSettingsChange(string $category, array $changes, int $updated_by): void
    {
        // Check if audit table exists
        $checkTable = $this->conn->query("SHOW TABLES LIKE 'settings_audit_log'");
        if ($checkTable->num_rows === 0) {
            return;
        }

        $changesJson = json_encode($changes);
        $stmt = $this->conn->prepare("
            INSERT INTO settings_audit_log 
                (tenant_id, category, changes, changed_by, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("issi", $this->tenant_id, $category, $changesJson, $updated_by);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Get settings change history
     */
    public function getSettingsHistory(int $limit = 50): array
    {
        // Check if audit table exists
        $checkTable = $this->conn->query("SHOW TABLES LIKE 'settings_audit_log'");
        if ($checkTable->num_rows === 0) {
            return [];
        }

        $stmt = $this->conn->prepare("
            SELECT sal.*, u.first_name, u.last_name, u.email
            FROM settings_audit_log sal
            LEFT JOIN users u ON sal.changed_by = u.id
            WHERE sal.tenant_id = ?
            ORDER BY sal.created_at DESC
            LIMIT ?
        ");
        $stmt->bind_param("ii", $this->tenant_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $history = [];
        while ($row = $result->fetch_assoc()) {
            $row['changes'] = json_decode($row['changes'], true);
            $history[] = $row;
        }
        $stmt->close();

        return $history;
    }

    // ==========================================
    // EXPORT/IMPORT
    // ==========================================

    /**
     * Export all settings as JSON
     */
    public function exportSettings(): array
    {
        return [
            'tenant_id' => $this->tenant_id,
            'exported_at' => date('Y-m-d H:i:s'),
            'settings' => $this->getAllSettings()
        ];
    }

    /**
     * Import settings from JSON
     */
    public function importSettings(array $settings, int $imported_by): array
    {
        $results = [];

        foreach ($settings as $category => $categorySettings) {
            if (!isset($this->defaultSettings[$category]) || !is_array($categorySettings)) {
                continue;
            }

            $result = $this->updateCategorySettings($category, $categorySettings, $imported_by);
            $results[$category] = $result;
        }

        return [
            'success' => true,
            'message' => 'Settings imported',
            'results' => $results
        ];
    }
}