<?php

/**
 * GDPRManager - Manages GDPR/data privacy compliance
 * Handles data subject requests, consent tracking, and data retention
 */
class GDPRManager {
    private mysqli $conn;
    private int $tenant_id;

    // Request types
    const REQUEST_ACCESS = 'access';
    const REQUEST_RECTIFICATION = 'rectification';
    const REQUEST_ERASURE = 'erasure';
    const REQUEST_PORTABILITY = 'portability';
    const REQUEST_RESTRICTION = 'restriction';

    // Request statuses
    const STATUS_PENDING = 'pending';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_REJECTED = 'rejected';

    public function __construct(mysqli $db) {
        $this->conn = $db;
    }

    public function setTenant(int $tenant_id): self {
        $this->tenant_id = $tenant_id;
        return $this;
    }

    // ==========================================
    // DATA SUBJECT REQUESTS
    // ==========================================

    /**
     * Create a data subject request
     */
    public function createRequest(int $user_id, string $request_type, ?string $description = null): array {
        $valid_types = [self::REQUEST_ACCESS, self::REQUEST_RECTIFICATION, 
                        self::REQUEST_ERASURE, self::REQUEST_PORTABILITY, self::REQUEST_RESTRICTION];
        
        if (!in_array($request_type, $valid_types)) {
            return ['success' => false, 'message' => 'Invalid request type'];
        }

        $query = "INSERT INTO data_subject_requests 
                  (tenant_id, user_id, request_type, status, description, requested_at)
                  VALUES (?, ?, ?, 'pending', ?, NOW())";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iiss", $this->tenant_id, $user_id, $request_type, $description);

        if ($stmt->execute()) {
            return [
                'success' => true,
                'request_id' => $this->conn->insert_id,
                'message' => 'Request submitted successfully. We will process it within 30 days.'
            ];
        }

        return ['success' => false, 'message' => 'Failed to create request'];
    }

    /**
     * Update request status
     */
    public function updateRequestStatus(int $request_id, string $status, int $admin_id, ?string $notes = null): bool {
        $query = "UPDATE data_subject_requests 
                  SET status = ?, response_notes = ?";
        
        if ($status === self::STATUS_COMPLETED) {
            $query .= ", completed_at = NOW(), completed_by = ?";
        }
        
        $query .= " WHERE id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($query);
        
        if ($status === self::STATUS_COMPLETED) {
            $stmt->bind_param("ssiii", $status, $notes, $admin_id, $request_id, $this->tenant_id);
        } else {
            $stmt->bind_param("ssii", $status, $notes, $request_id, $this->tenant_id);
        }

        return $stmt->execute();
    }

    /**
     * Get requests for user
     */
    public function getUserRequests(int $user_id): array {
        $query = "SELECT * FROM data_subject_requests 
                  WHERE user_id = ? AND tenant_id = ?
                  ORDER BY requested_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get all requests (admin)
     */
    public function getAllRequests(?string $status = null): array {
        $query = "SELECT dsr.*, 
                         CONCAT(u.first_name, ' ', u.last_name) as user_name,
                         u.email,
                         CONCAT(a.first_name, ' ', a.last_name) as completed_by_name
                  FROM data_subject_requests dsr
                  JOIN users u ON dsr.user_id = u.id
                  LEFT JOIN users a ON dsr.completed_by = a.id
                  WHERE dsr.tenant_id = ?";
        
        $params = [$this->tenant_id];
        $types = "i";

        if ($status) {
            $query .= " AND dsr.status = ?";
            $params[] = $status;
            $types .= "s";
        }

        $query .= " ORDER BY dsr.requested_at DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // ==========================================
    // CONSENT MANAGEMENT
    // ==========================================

    /**
     * Update user consent
     */
    public function updateConsent(int $user_id, array $consents): bool {
        $query = "UPDATE users SET 
                    privacy_consent = ?,
                    marketing_consent = ?,
                    data_processing_consent = ?
                  WHERE id = ? AND tenant_id = ?";
        
        $stmt = $this->conn->prepare($query);
        
        $privacy = $consents['privacy'] ?? 0;
        $marketing = $consents['marketing'] ?? 0;
        $data_processing = $consents['data_processing'] ?? 0;

        $stmt->bind_param("iiiii", $privacy, $marketing, $data_processing, $user_id, $this->tenant_id);
        return $stmt->execute();
    }

    /**
     * Get user consent status
     */
    public function getUserConsent(int $user_id): array {
        $query = "SELECT privacy_consent, marketing_consent, data_processing_consent 
                  FROM users WHERE id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc() ?? [];
    }

    /**
     * Get consent statistics
     */
    public function getConsentStats(): array {
        $query = "SELECT 
                    COUNT(*) as total_users,
                    SUM(CASE WHEN privacy_consent = 1 THEN 1 ELSE 0 END) as privacy_consented,
                    SUM(CASE WHEN marketing_consent = 1 THEN 1 ELSE 0 END) as marketing_consented,
                    SUM(CASE WHEN data_processing_consent = 1 THEN 1 ELSE 0 END) as data_processing_consented
                  FROM users 
                  WHERE tenant_id = ? AND is_active = 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    // ==========================================
    // DATA EXPORT (Right to Portability)
    // ==========================================

    /**
     * Export all user data
     */
    public function exportUserData(int $user_id): array {
        $data = [];

        // User profile
        $query = "SELECT id, email, first_name, last_name, phone, department, 
                         job_title, created, last_login_at
                  FROM users WHERE id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $data['profile'] = $stmt->get_result()->fetch_assoc();

        // Enrollments
        $query = "SELECT ce.*, c.title as course_title
                  FROM course_enrollments ce
                  JOIN courses c ON ce.course_id = c.course_id
                  WHERE ce.user_id = ? AND ce.tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $data['enrollments'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Assessment results
        $query = "SELECT ar.*, a.title as assessment_title
                  FROM assessment_attempts ar
                  JOIN assessments a ON ar.assessment_id = a.assessment_id
                  WHERE ar.user_id = ? AND ar.tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $data['assessment_results'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Certificates
        $query = "SELECT c.*, co.title as course_title
                  FROM certificates c
                  JOIN courses co ON c.course_id = co.course_id
                  WHERE c.user_id = ? AND c.tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $data['certificates'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Learning sessions
        $query = "SELECT * FROM learning_sessions WHERE user_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $data['learning_sessions'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Points and badges
        $query = "SELECT * FROM user_points WHERE user_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $data['points'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $query = "SELECT ub.*, b.badge_name, b.description
                  FROM user_badges ub
                  JOIN badges b ON ub.badge_id = b.badge_id
                  WHERE ub.user_id = ? AND ub.tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $data['badges'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Login history
        $query = "SELECT * FROM user_login_history WHERE user_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $data['login_history'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        return $data;
    }

    // ==========================================
    // DATA ERASURE (Right to be Forgotten)
    // ==========================================

    /**
     * Anonymize user data (soft delete)
     */
    public function anonymizeUser(int $user_id, int $admin_id): array {
        $this->conn->begin_transaction();

        try {
            // Generate anonymous identifier
            $anonymous_id = 'DELETED_' . $user_id . '_' . time();

            // Anonymize user record
            $query = "UPDATE users SET 
                        email = ?, 
                        first_name = 'Deleted',
                        last_name = 'User',
                        phone = NULL,
                        avatar = NULL,
                        password = '',
                        is_active = 0,
                        deleted_at = NOW()
                      WHERE id = ? AND tenant_id = ?";
            $stmt = $this->conn->prepare($query);
            $anonymousEmail = $anonymous_id . '@deleted.local';
            $stmt->bind_param("sii", $anonymousEmail, $user_id, $this->tenant_id);
            $stmt->execute();

            // Delete personal notes
            $query = "DELETE FROM notes WHERE user_id = ? AND tenant_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("ii", $user_id, $this->tenant_id);
            $stmt->execute();

            // Delete login history
            $query = "DELETE FROM user_login_history WHERE user_id = ? AND tenant_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("ii", $user_id, $this->tenant_id);
            $stmt->execute();

            // Keep enrollments, certificates, and completion records for business records
            // but remove PII associations

            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'User data has been anonymized',
                'anonymous_id' => $anonymous_id
            ];

        } catch (Exception $e) {
            $this->conn->rollback();
            return [
                'success' => false,
                'message' => 'Failed to anonymize user: ' . $e->getMessage()
            ];
        }
    }

    // ==========================================
    // DATA RETENTION
    // ==========================================

    /**
     * Get data retention statistics
     */
    public function getRetentionStats(): array {
        $stats = [];

        // Users by creation date
        $query = "SELECT 
                    SUM(CASE WHEN created < DATE_SUB(NOW(), INTERVAL 3 YEAR) THEN 1 ELSE 0 END) as older_than_3_years,
                    SUM(CASE WHEN created < DATE_SUB(NOW(), INTERVAL 1 YEAR) AND created >= DATE_SUB(NOW(), INTERVAL 3 YEAR) THEN 1 ELSE 0 END) as one_to_three_years,
                    SUM(CASE WHEN created >= DATE_SUB(NOW(), INTERVAL 1 YEAR) THEN 1 ELSE 0 END) as less_than_1_year
                  FROM users WHERE tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $stats['users_by_age'] = $stmt->get_result()->fetch_assoc();

        // Inactive users
        $query = "SELECT COUNT(*) as count FROM users 
                  WHERE tenant_id = ? AND is_active = 1 
                  AND (last_login_at IS NULL OR last_login_at < DATE_SUB(NOW(), INTERVAL 1 YEAR))";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $stats['inactive_users'] = $stmt->get_result()->fetch_assoc()['count'];

        // Data subject requests
        $query = "SELECT request_type, status, COUNT(*) as count
                  FROM data_subject_requests
                  WHERE tenant_id = ?
                  GROUP BY request_type, status";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $stats['dsr_summary'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        return $stats;
    }
}
