<?php
/**
 * Certificate Service
 * Handles certificate generation, issuance, and verification
 */

namespace App\Services\ProctoredAssessment;

class CertificateService
{
    private $db;
    private $uploadPath;

    public function __construct($db)
    {
        $this->db = $db;
        $this->uploadPath = UPLOADS_PATH . '/certificates';
    }

    /**
     * Issue a certificate for a user
     */
    public function issueCertificate($data)
    {
        // Get template
        $sql = "SELECT * FROM proc_certificate_templates WHERE id = :id AND (tenant_id = :tenant_id OR tenant_id = 0)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $data['template_id'], 'tenant_id' => $data['tenant_id']]);
        $template = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$template) {
            throw new \Exception('Certificate template not found');
        }

        // Get user info
        $sql = "SELECT first_name, last_name, email FROM users WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $data['user_id']]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            throw new \Exception('User not found');
        }

        // Generate certificate number
        $certificateNumber = $this->generateCertificateNumber($data['tenant_id']);

        // Generate verification code
        $verificationCode = $this->generateVerificationCode();

        // Build certificate data
        $certificateData = [
            'learner_name' => $user['first_name'] . ' ' . $user['last_name'],
            'learner_email' => $user['email'],
            'certificate_number' => $certificateNumber,
            'verification_code' => $verificationCode,
            'issue_date' => date('Y-m-d'),
            'expiry_date' => isset($data['expiry_date']) ? $data['expiry_date'] : null
        ];

        // Add assessment/course info if provided
        if (!empty($data['assessment_id'])) {
            $sql = "SELECT title FROM proc_assessments WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $data['assessment_id']]);
            $assessment = $stmt->fetch(\PDO::FETCH_ASSOC);
            $certificateData['assessment_title'] = $assessment['title'] ?? '';
        }

        if (!empty($data['course_id'])) {
            $sql = "SELECT title FROM courses WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $data['course_id']]);
            $course = $stmt->fetch(\PDO::FETCH_ASSOC);
            $certificateData['course_title'] = $course['title'] ?? '';
        }

        // Add attempt data if provided
        if (!empty($data['attempt_id'])) {
            $sql = "SELECT percentage_score, grade FROM proc_assessment_attempts WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $data['attempt_id']]);
            $attempt = $stmt->fetch(\PDO::FETCH_ASSOC);
            $certificateData['score'] = $attempt['percentage_score'];
            $certificateData['grade'] = $attempt['grade'];
        }

        // Merge custom fields
        if (!empty($data['custom_fields'])) {
            $certificateData = array_merge($certificateData, $data['custom_fields']);
        }

        // Get tenant info
        $sql = "SELECT name as tenant_name, logo as tenant_logo FROM tenants WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $data['tenant_id']]);
        $tenant = $stmt->fetch(\PDO::FETCH_ASSOC);
        $certificateData['tenant_name'] = $tenant['tenant_name'] ?? '';
        $certificateData['tenant_logo'] = $tenant['tenant_logo'] ?? '';

        // Generate verification URL
        $verificationUrl = getenv('APP_URL') . '/certificates/verify/' . $verificationCode;
        $certificateData['verification_url'] = $verificationUrl;

        // Insert certificate record
        $sql = "INSERT INTO proc_certificates_issued (
                    tenant_id, certificate_number, template_id, user_id,
                    learner_name, learner_email, assessment_id, attempt_id,
                    course_id, live_training_id, certificate_data, custom_fields,
                    score, percentage, grade, completion_date,
                    issued_at, expiry_date, verification_code, verification_url,
                    issued_by
                ) VALUES (
                    :tenant_id, :certificate_number, :template_id, :user_id,
                    :learner_name, :learner_email, :assessment_id, :attempt_id,
                    :course_id, :live_training_id, :certificate_data, :custom_fields,
                    :score, :percentage, :grade, :completion_date,
                    NOW(), :expiry_date, :verification_code, :verification_url,
                    :issued_by
                )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'tenant_id' => $data['tenant_id'],
            'certificate_number' => $certificateNumber,
            'template_id' => $data['template_id'],
            'user_id' => $data['user_id'],
            'learner_name' => $certificateData['learner_name'],
            'learner_email' => $certificateData['learner_email'],
            'assessment_id' => $data['assessment_id'] ?? null,
            'attempt_id' => $data['attempt_id'] ?? null,
            'course_id' => $data['course_id'] ?? null,
            'live_training_id' => $data['live_training_id'] ?? null,
            'certificate_data' => json_encode($certificateData),
            'custom_fields' => !empty($data['custom_fields']) ? json_encode($data['custom_fields']) : null,
            'score' => $certificateData['score'] ?? null,
            'percentage' => $certificateData['score'] ?? null,
            'grade' => $certificateData['grade'] ?? null,
            'completion_date' => date('Y-m-d'),
            'expiry_date' => $data['expiry_date'] ?? null,
            'verification_code' => $verificationCode,
            'verification_url' => $verificationUrl,
            'issued_by' => $data['issued_by'] ?? null
        ]);

        $certificateId = $this->db->lastInsertId();

        // Generate PDF
        $pdfPath = $this->generatePdf($certificateId, $template, $certificateData);

        // Update record with PDF path
        $sql = "UPDATE proc_certificates_issued SET pdf_path = :pdf_path WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $certificateId, 'pdf_path' => $pdfPath]);

        // Generate QR code
        $qrPath = $this->generateQRCode($certificateId, $verificationCode);
        if ($qrPath) {
            $sql = "UPDATE proc_certificates_issued SET qr_code_path = :qr_path WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $certificateId, 'qr_path' => $qrPath]);
        }

        // Send email if requested
        if (!empty($data['send_email'])) {
            $this->sendCertificateEmail($certificateId);
        }

        // Update attempt if linked
        if (!empty($data['attempt_id'])) {
            $sql = "UPDATE proc_assessment_attempts SET 
                        certificate_issued = 1, certificate_id = :cert_id 
                    WHERE id = :attempt_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['cert_id' => $certificateId, 'attempt_id' => $data['attempt_id']]);
        }

        return [
            'id' => $certificateId,
            'certificate_number' => $certificateNumber,
            'pdf_path' => $pdfPath,
            'verification_code' => $verificationCode
        ];
    }

    /**
     * Generate certificate PDF
     */
    private function generatePdf($certificateId, $template, $data)
    {
        // Replace placeholders in template HTML
        $html = $template['template_html'];
        
        foreach ($data as $key => $value) {
            $html = str_replace('{{' . $key . '}}', htmlspecialchars($value ?? ''), $html);
            $html = str_replace('{' . $key . '}', htmlspecialchars($value ?? ''), $html);
        }

        // Add CSS
        if (!empty($template['template_css'])) {
            $html = '<style>' . $template['template_css'] . '</style>' . $html;
        }

        // Configure PDF settings
        $orientation = $template['orientation'] ?? 'landscape';
        $pageSize = $template['page_size'] ?? 'A4';

        // Create directory if needed
        $directory = $this->uploadPath . '/' . date('Y/m');
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filename = 'cert_' . $certificateId . '_' . time() . '.pdf';
        $filepath = $directory . '/' . $filename;

        // Use DOMPDF or similar library
        // This is a placeholder - actual implementation would use a PDF library
        try {
            // Example with DOMPDF (would need to be installed)
            // $dompdf = new \Dompdf\Dompdf();
            // $dompdf->loadHtml($html);
            // $dompdf->setPaper($pageSize, $orientation);
            // $dompdf->render();
            // file_put_contents($filepath, $dompdf->output());
            
            // For now, just save HTML
            file_put_contents(str_replace('.pdf', '.html', $filepath), $html);
            
            return 'certificates/' . date('Y/m') . '/' . $filename;
        } catch (\Exception $e) {
            throw new \Exception('Failed to generate PDF: ' . $e->getMessage());
        }
    }

    /**
     * Generate QR code for verification
     */
    private function generateQRCode($certificateId, $verificationCode)
    {
        $verificationUrl = getenv('APP_URL') . '/certificates/verify/' . $verificationCode;
        
        $directory = $this->uploadPath . '/qr/' . date('Y/m');
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filename = 'qr_' . $certificateId . '.png';
        $filepath = $directory . '/' . $filename;

        // Would use a QR code library like phpqrcode or chillerlan/php-qrcode
        // Placeholder implementation
        try {
            // Example: QRcode::png($verificationUrl, $filepath, QR_ECLEVEL_M, 10);
            return 'certificates/qr/' . date('Y/m') . '/' . $filename;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Generate unique certificate number
     */
    private function generateCertificateNumber($tenantId)
    {
        // Get tenant settings
        $sql = "SELECT certificate_prefix, certificate_next_number, 
                       certificate_number_format, certificate_number_padding
                FROM proc_tenant_settings WHERE tenant_id = :tenant_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId]);
        $settings = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$settings) {
            $settings = [
                'certificate_prefix' => 'CERT',
                'certificate_next_number' => 1,
                'certificate_number_format' => '{PREFIX}-{YEAR}-{NUMBER}',
                'certificate_number_padding' => 6
            ];
        }

        $prefix = $settings['certificate_prefix'];
        $number = $settings['certificate_next_number'];
        $format = $settings['certificate_number_format'];
        $padding = $settings['certificate_number_padding'];

        // Build certificate number
        $certNumber = str_replace(
            ['{PREFIX}', '{YEAR}', '{MONTH}', '{NUMBER}'],
            [
                $prefix,
                date('Y'),
                date('m'),
                str_pad($number, $padding, '0', STR_PAD_LEFT)
            ],
            $format
        );

        // Increment counter
        $sql = "UPDATE proc_tenant_settings SET certificate_next_number = certificate_next_number + 1 
                WHERE tenant_id = :tenant_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId]);

        // If no settings exist, create them
        if ($stmt->rowCount() == 0) {
            $sql = "INSERT INTO proc_tenant_settings (tenant_id, certificate_next_number) VALUES (:tenant_id, 2)
                    ON DUPLICATE KEY UPDATE certificate_next_number = certificate_next_number + 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['tenant_id' => $tenantId]);
        }

        return $certNumber;
    }

    /**
     * Generate unique verification code
     */
    private function generateVerificationCode()
    {
        do {
            $code = bin2hex(random_bytes(16));
            
            $sql = "SELECT COUNT(*) FROM proc_certificates_issued WHERE verification_code = :code";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['code' => $code]);
            $exists = $stmt->fetchColumn() > 0;
        } while ($exists);

        return $code;
    }

    /**
     * Verify a certificate
     */
    public function verifyCertificate($verificationCode)
    {
        $sql = "SELECT ci.*, ct.name as template_name,
                       a.title as assessment_title, c.title as course_title
                FROM proc_assessment_certificates ci
                LEFT JOIN proc_certificate_templates ct ON ci.template_id = ct.template_id
                LEFT JOIN proc_assessments a ON ci.assessment_id = a.assessment_id
                LEFT JOIN courses c ON ci.course_id = c.course_id
                WHERE ci.verification_code = :code";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['code' => $verificationCode]);
        $certificate = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$certificate) {
            return ['valid' => false, 'message' => 'Certificate not found'];
        }

        // Update verification count
        $sql = "UPDATE proc_certificates_issued SET 
                    verification_count = verification_count + 1,
                    last_verified_at = NOW()
                WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $certificate['id']]);

        // Check status
        if ($certificate['status'] === 'revoked') {
            return [
                'valid' => false,
                'message' => 'This certificate has been revoked',
                'revoked_at' => $certificate['revoked_at'],
                'revocation_reason' => $certificate['revocation_reason']
            ];
        }

        if ($certificate['status'] === 'expired' || 
            ($certificate['expiry_date'] && strtotime($certificate['expiry_date']) < time())) {
            return [
                'valid' => false,
                'message' => 'This certificate has expired',
                'expiry_date' => $certificate['expiry_date']
            ];
        }

        return [
            'valid' => true,
            'certificate' => [
                'certificate_number' => $certificate['certificate_number'],
                'learner_name' => $certificate['learner_name'],
                'assessment_title' => $certificate['assessment_title'],
                'course_title' => $certificate['course_title'],
                'score' => $certificate['score'],
                'grade' => $certificate['grade'],
                'issued_at' => $certificate['issued_at'],
                'expiry_date' => $certificate['expiry_date'],
                'status' => $certificate['status']
            ]
        ];
    }

    /**
     * Revoke a certificate
     */
    public function revokeCertificate($certificateId, $reason, $revokedBy)
    {
        $sql = "UPDATE proc_certificates_issued SET 
                    status = 'revoked',
                    revoked_at = NOW(),
                    revoked_by = :revoked_by,
                    revocation_reason = :reason
                WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $certificateId,
            'revoked_by' => $revokedBy,
            'reason' => $reason
        ]);

        return true;
    }

    /**
     * Reinstate a revoked certificate
     */
    public function reinstateCertificate($certificateId)
    {
        $sql = "UPDATE proc_certificates_issued SET 
                    status = 'valid',
                    revoked_at = NULL,
                    revoked_by = NULL,
                    revocation_reason = NULL
                WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $certificateId]);

        return true;
    }

    /**
     * Send certificate email
     */
    public function sendCertificateEmail($certificateId)
    {
        $sql = "SELECT * FROM proc_certificates_issued WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $certificateId]);
        $certificate = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$certificate) {
            return false;
        }

        // Email sending logic would go here
        // Using mail service, queue, etc.

        // Update email sent status
        $sql = "UPDATE proc_certificates_issued SET 
                    email_sent = 1, email_sent_at = NOW() 
                WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $certificateId]);

        return true;
    }

    /**
     * Get certificate statistics
     */
    public function getStatistics($tenantId)
    {
        $stats = [];

        // Total counts by status
        $sql = "SELECT status, COUNT(*) as count 
                FROM proc_certificates_issued 
                WHERE tenant_id = :tenant_id 
                GROUP BY status";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId]);
        $statusCounts = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

        $stats['total_issued'] = array_sum($statusCounts);
        $stats['valid'] = $statusCounts['valid'] ?? 0;
        $stats['expired'] = $statusCounts['expired'] ?? 0;
        $stats['revoked'] = $statusCounts['revoked'] ?? 0;

        // Issued this month
        $sql = "SELECT COUNT(*) FROM proc_certificates_issued 
                WHERE tenant_id = :tenant_id 
                AND issued_at >= DATE_FORMAT(NOW(), '%Y-%m-01')";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId]);
        $stats['issued_this_month'] = $stmt->fetchColumn();

        // By template
        $sql = "SELECT ct.id as template_id, ct.name as template_name, COUNT(ci.id) as count
                FROM proc_certificate_templates ct
                LEFT JOIN proc_certificates_issued ci ON ct.id = ci.template_id
                WHERE ct.tenant_id = :tenant_id
                GROUP BY ct.id, ct.name
                ORDER BY count DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId]);
        $stats['issued_by_template'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // By month (last 12 months)
        $sql = "SELECT DATE_FORMAT(issued_at, '%Y-%m') as month, COUNT(*) as count
                FROM proc_certificates_issued
                WHERE tenant_id = :tenant_id
                AND issued_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY DATE_FORMAT(issued_at, '%Y-%m')
                ORDER BY month";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId]);
        $stats['issued_by_month'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $stats;
    }

    /**
     * Get available placeholders for templates
     */
    public function getAvailablePlaceholders()
    {
        return [
            ['key' => 'learner_name', 'description' => "Learner's full name", 'example' => 'John Doe'],
            ['key' => 'learner_email', 'description' => "Learner's email address", 'example' => 'john@example.com'],
            ['key' => 'certificate_number', 'description' => 'Unique certificate number', 'example' => 'CERT-2024-000001'],
            ['key' => 'verification_code', 'description' => 'Certificate verification code', 'example' => 'abc123...'],
            ['key' => 'verification_url', 'description' => 'Certificate verification URL', 'example' => 'https://...'],
            ['key' => 'issue_date', 'description' => 'Date certificate was issued', 'example' => '2024-01-15'],
            ['key' => 'expiry_date', 'description' => 'Certificate expiry date', 'example' => '2025-01-15'],
            ['key' => 'assessment_title', 'description' => 'Title of the assessment', 'example' => 'Final Exam'],
            ['key' => 'course_title', 'description' => 'Title of the course', 'example' => 'Web Development 101'],
            ['key' => 'score', 'description' => "Learner's score percentage", 'example' => '85'],
            ['key' => 'grade', 'description' => "Learner's grade", 'example' => 'A'],
            ['key' => 'tenant_name', 'description' => 'Organization name', 'example' => 'Acme Inc.'],
            ['key' => 'tenant_logo', 'description' => 'Organization logo URL', 'example' => 'https://...']
        ];
    }
}
