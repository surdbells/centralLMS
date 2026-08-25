<?php

/**
 * CertificateLogger - Manages certificate verification and reprint logging
 * Used for certificate reports and compliance tracking
 */
class CertificateLogger {
    private mysqli $conn;
    private int $tenant_id;

    public function __construct(mysqli $db) {
        $this->conn = $db;
    }

    public function setTenant(int $tenant_id): self {
        $this->tenant_id = $tenant_id;
        return $this;
    }

    // ==========================================
    // CERTIFICATE VERIFICATION
    // ==========================================

    /**
     * Log external certificate verification attempt
     */
    public function logVerification(
        string $certificate_number,
        string $verification_status,
        ?string $verified_by_name = null,
        ?string $verified_by_email = null,
        ?string $verified_by_company = null
    ): array {
        $query = "INSERT INTO certificate_verification_log 
                  (tenant_id, certificate_number, verified_at, verified_by_name, 
                   verified_by_email, verified_by_company, ip_address, verification_status)
                  VALUES (?, ?, NOW(), ?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($query);
        $ip = $this->getClientIP();

        $stmt->bind_param("issssss",
            $this->tenant_id,
            $certificate_number,
            $verified_by_name,
            $verified_by_email,
            $verified_by_company,
            $ip,
            $verification_status
        );

        if ($stmt->execute()) {
            return [
                'success' => true,
                'verification_id' => $this->conn->insert_id
            ];
        }

        return ['success' => false, 'message' => 'Failed to log verification'];
    }

    /**
     * Verify a certificate and log the attempt
     */
    public function verifyCertificate(
        string $certificate_number,
        ?string $verified_by_name = null,
        ?string $verified_by_email = null,
        ?string $verified_by_company = null
    ): array {
        // Look up certificate
        $query = "SELECT c.*, 
                         CONCAT(u.first_name, ' ', u.last_name) as holder_name,
                         co.title as course_title
                  FROM certificates c
                  JOIN users u ON c.user_id = u.id
                  JOIN courses co ON c.course_id = co.course_id
                  WHERE c.certificate_number = ? AND c.tenant_id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("si", $certificate_number, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $certificate = $result->fetch_assoc();

        if (!$certificate) {
            $this->logVerification($certificate_number, 'invalid', $verified_by_name, $verified_by_email, $verified_by_company);
            return [
                'valid' => false,
                'status' => 'invalid',
                'message' => 'Certificate not found'
            ];
        }

        // Check expiry
        $status = 'valid';
        if ($certificate['expiry_date'] && strtotime($certificate['expiry_date']) < time()) {
            $status = 'expired';
        }

        $this->logVerification($certificate_number, $status, $verified_by_name, $verified_by_email, $verified_by_company);

        return [
            'valid' => $status === 'valid',
            'status' => $status,
            'certificate' => [
                'certificate_number' => $certificate['certificate_number'],
                'holder_name' => $certificate['holder_name'],
                'course_title' => $certificate['course_title'],
                'issued_at' => $certificate['issued_at'],
                'expiry_date' => $certificate['expiry_date']
            ]
        ];
    }

    /**
     * Get verification history for a certificate
     */
    public function getCertificateVerificationHistory(string $certificate_number): array {
        $query = "SELECT * FROM certificate_verification_log 
                  WHERE certificate_number = ? AND tenant_id = ?
                  ORDER BY verified_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("si", $certificate_number, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get recent verification attempts (for monitoring)
     */
    public function getRecentVerifications(int $days = 30, int $limit = 100): array {
        $query = "SELECT cvl.*, 
                         CASE WHEN c.certificate_id IS NOT NULL 
                              THEN CONCAT(u.first_name, ' ', u.last_name) 
                              ELSE NULL END as holder_name,
                         co.title as course_title
                  FROM certificate_verification_log cvl
                  LEFT JOIN certificates c ON cvl.certificate_number = c.certificate_number
                  LEFT JOIN users u ON c.user_id = u.id
                  LEFT JOIN courses co ON c.course_id = co.course_id
                  WHERE cvl.tenant_id = ? AND cvl.verified_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  ORDER BY cvl.verified_at DESC
                  LIMIT ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iii", $this->tenant_id, $days, $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // ==========================================
    // CERTIFICATE REPRINTS
    // ==========================================

    /**
     * Log certificate reprint
     */
    public function logReprint(int $certificate_id, int $reprinted_by, ?string $reason = null): array {
        $query = "INSERT INTO certificate_reprint_history 
                  (certificate_id, reprinted_at, reprinted_by, reason)
                  VALUES (?, NOW(), ?, ?)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iis", $certificate_id, $reprinted_by, $reason);

        if ($stmt->execute()) {
            return [
                'success' => true,
                'reprint_id' => $this->conn->insert_id
            ];
        }

        return ['success' => false, 'message' => 'Failed to log reprint'];
    }

    /**
     * Get reprint history for a certificate
     */
    public function getCertificateReprintHistory(int $certificate_id): array {
        $query = "SELECT crh.*, 
                         CONCAT(u.first_name, ' ', u.last_name) as reprinted_by_name
                  FROM certificate_reprint_history crh
                  JOIN users u ON crh.reprinted_by = u.id
                  WHERE crh.certificate_id = ?
                  ORDER BY crh.reprinted_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $certificate_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get all reprints for reporting
     */
    public function getAllReprints(?string $date_from = null, ?string $date_to = null): array {
        $query = "SELECT crh.*, 
                         c.certificate_number,
                         CONCAT(holder.first_name, ' ', holder.last_name) as holder_name,
                         co.title as course_title,
                         CONCAT(admin.first_name, ' ', admin.last_name) as reprinted_by_name
                  FROM certificate_reprint_history crh
                  JOIN certificates c ON crh.certificate_id = c.certificate_id
                  JOIN users holder ON c.user_id = holder.id
                  JOIN courses co ON c.course_id = co.course_id
                  JOIN users admin ON crh.reprinted_by = admin.id
                  WHERE c.tenant_id = ?";
        
        $params = [$this->tenant_id];
        $types = "i";

        if ($date_from) {
            $query .= " AND crh.reprinted_at >= ?";
            $params[] = $date_from;
            $types .= "s";
        }
        if ($date_to) {
            $query .= " AND crh.reprinted_at <= ?";
            $params[] = $date_to . ' 23:59:59';
            $types .= "s";
        }

        $query .= " ORDER BY crh.reprinted_at DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // ==========================================
    // STATISTICS
    // ==========================================

    /**
     * Get verification statistics
     */
    public function getVerificationStats(int $days = 30): array {
        $query = "SELECT 
                    COUNT(*) as total_verifications,
                    SUM(CASE WHEN verification_status = 'valid' THEN 1 ELSE 0 END) as valid_count,
                    SUM(CASE WHEN verification_status = 'invalid' THEN 1 ELSE 0 END) as invalid_count,
                    SUM(CASE WHEN verification_status = 'expired' THEN 1 ELSE 0 END) as expired_count,
                    COUNT(DISTINCT ip_address) as unique_verifiers,
                    COUNT(DISTINCT verified_by_company) as unique_companies
                  FROM certificate_verification_log 
                  WHERE tenant_id = ? AND verified_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->tenant_id, $days);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Get top verifying companies
     */
    public function getTopVerifiers(int $limit = 10): array {
        $query = "SELECT verified_by_company, COUNT(*) as verification_count
                  FROM certificate_verification_log 
                  WHERE tenant_id = ? AND verified_by_company IS NOT NULL
                  GROUP BY verified_by_company
                  ORDER BY verification_count DESC
                  LIMIT ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->tenant_id, $limit);
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
}
