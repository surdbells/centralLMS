<?php
/**
 * Certificate Manager - Templates, Issuance, Verification
 * Multi-Tenant LMS - Admin & Learner Portal
 */

class Certificate
{
    public mysqli $conn;
    public int $tenant_id;
    public int $user_id;
    public string $status = '';
    public bool $is_active = true;
    public int|null $course_id;
    public string $upload_path = '/uploads/certificates/';
    public string $verification_base_url = '';
    public int $page = 1;
    public int $per_page = 20;
    public string $sort_by = 'tenant_id';
    public string $sort_dir = 'asc';
    public function __construct(mysqli $conn) { $this->conn = $conn; }
    public function setTenant(int $tenant_id): void { $this->tenant_id = $tenant_id; }
    public function setUser(int $user_id): void { $this->user_id = $user_id; }
    public function setUploadPath(string $path): void { $this->upload_path = $path; }
    public function setVerificationUrl(string $url): void { $this->verification_base_url = $url; }

    // ==========================================
    // TEMPLATES
    // ==========================================

    public function createTemplate(array $data): array
    {
        $name = trim($data['name'] ?? '');
        if (empty($name)) return ['success' => false, 'message' => 'Name is required'];

        $description = $data['description'] ?? null;
        $template_type = $data['template_type'] ?? 'completion';
        $orientation = $data['orientation'] ?? 'landscape';
        $paper_size = $data['paper_size'] ?? 'a4';
        $width_mm = (int) ($data['width_mm'] ?? 297);
        $height_mm = (int) ($data['height_mm'] ?? 210);
        $background_type = $data['background_type'] ?? 'color';
        $background_color = $data['background_color'] ?? '#FFFFFF';
        $background_image_path = $data['background_image_path'] ?? null;
        $border_enabled = isset($data['border_enabled']) ? (int) $data['border_enabled'] : 1;
        $border_style = $data['border_style'] ?? 'solid';
        $border_color = $data['border_color'] ?? '#D4AF37';
        $border_width = (int) ($data['border_width'] ?? 2);
        $border_margin = (int) ($data['border_margin'] ?? 15);
        $content_template = $data['content_template'] ?? '';
        $css_styles = $data['css_styles'] ?? null;
        $logo_enabled = isset($data['logo_enabled']) ? (int) $data['logo_enabled'] : 1;
        $logo_path = $data['logo_path'] ?? null;
        $logo_position = $data['logo_position'] ?? 'top-center';
        $logo_width = (int) ($data['logo_width'] ?? 80);
        $signature_enabled = isset($data['signature_enabled']) ? (int) $data['signature_enabled'] : 1;
        $signature_image_path = $data['signature_image_path'] ?? null;
        $signature_name = $data['signature_name'] ?? null;
        $signature_title = $data['signature_title'] ?? null;
        $qr_enabled = isset($data['qr_enabled']) ? (int) $data['qr_enabled'] : 1;
        $qr_position = $data['qr_position'] ?? 'bottom-right';
        $qr_size = (int) ($data['qr_size'] ?? 60);
        $validity_type = $data['validity_type'] ?? 'lifetime';
        $validity_period_months = !empty($data['validity_period_months']) ? (int) $data['validity_period_months'] : null;
        $validity_end_date = $data['validity_end_date'] ?? null;
        $status = $data['status'] ?? 'draft';

        $stmt = $this->conn->prepare("INSERT INTO proc_certificate_templates (tenant_id, name, description, template_type, orientation, paper_size, width_mm, height_mm, background_type, background_color, background_image_path, border_enabled, border_style, border_color, border_width, border_margin, content_template, css_styles, logo_enabled, logo_path, logo_position, logo_width, signature_enabled, signature_image_path, signature_name, signature_title, qr_enabled, qr_position, qr_size, validity_type, validity_period_months, validity_end_date, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("isssssiisssississiissisisssisissi", $this->tenant_id, $name, $description, $template_type, $orientation, $paper_size, $width_mm, $height_mm, $background_type, $background_color, $background_image_path, $border_enabled, $border_style, $border_color, $border_width, $border_margin, $content_template, $css_styles, $logo_enabled, $logo_path, $logo_position, $logo_width, $signature_enabled, $signature_image_path, $signature_name, $signature_title, $qr_enabled, $qr_position, $qr_size, $validity_type, $validity_period_months, $validity_end_date, $status, $this->user_id);

        if ($stmt->execute()) {
            $template_id = $stmt->insert_id;
            $stmt->close();
            if (!empty($data['fields'])) {
                foreach ($data['fields'] as $i => $field) {
                    $field['template_id'] = $template_id;
                    $field['sort_order'] = $i;
                    $this->createField($field);
                }
            }
            return ['success' => true, 'template_id' => $template_id, 'message' => 'Template created'];
        }
        $stmt->close();
        return ['success' => false, 'message' => 'Failed to create template'];
    }

    public function updateTemplate(int $template_id, array $data): array
    {
        $fields = []; $params = []; $types = "";
        $stringFields = ['name', 'description', 'template_type', 'orientation', 'paper_size', 'background_type', 'background_color', 'background_image_path', 'border_style', 'border_color', 'content_template', 'css_styles', 'logo_path', 'logo_position', 'signature_image_path', 'signature_name', 'signature_title', 'qr_position', 'validity_type', 'validity_end_date', 'status'];
        $intFields = ['width_mm', 'height_mm', 'border_enabled', 'border_width', 'border_margin', 'logo_enabled', 'logo_width', 'signature_enabled', 'qr_enabled', 'qr_size', 'validity_period_months', 'is_default'];

        foreach ($stringFields as $f) {
            if (isset($data[$f])) { $fields[] = "$f = ?"; $params[] = $data[$f]; $types .= "s"; }
        }
        foreach ($intFields as $f) {
            if (isset($data[$f])) { $fields[] = "$f = ?"; $params[] = (int) $data[$f]; $types .= "i"; }
        }
        if (empty($fields)) return ['success' => false, 'message' => 'No fields to update'];

        $params[] = $template_id; $params[] = $this->tenant_id; $types .= "ii";
        $stmt = $this->conn->prepare("UPDATE proc_certificate_templates SET " . implode(", ", $fields) . " WHERE template_id = ? AND tenant_id = ?");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
        return ['success' => true, 'message' => 'Template updated'];
    }

    public function deleteTemplate(int $template_id): array
    {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as cnt FROM proc_certificates WHERE template_id = ?");
        $stmt->bind_param("i", $template_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ((int) $result['cnt'] > 0) return ['success' => false, 'message' => 'Template is in use'];

        $stmt = $this->conn->prepare("DELETE FROM proc_certificate_templates WHERE template_id = ? AND tenant_id = ?");
        $stmt->bind_param("ii", $template_id, $this->tenant_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected > 0 ? ['success' => true, 'message' => 'Template deleted'] : ['success' => false, 'message' => 'Not found'];
    }

    public function getTemplate(int $template_id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM proc_certificate_templates WHERE template_id = ? AND (tenant_id = ? OR tenant_id = 0)");
        $stmt->bind_param("ii", $template_id, $this->tenant_id);
        $stmt->execute();
        $template = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$template) return null;
        $template['template_id'] = (int) $template['template_id'];
        $template['border_enabled'] = (bool) $template['border_enabled'];
        $template['logo_enabled'] = (bool) $template['logo_enabled'];
        $template['signature_enabled'] = (bool) $template['signature_enabled'];
        $template['qr_enabled'] = (bool) $template['qr_enabled'];
        $template['is_default'] = (bool) $template['is_default'];
        $template['fields'] = $this->getTemplateFields($template_id);
        return $template;
    }

    public function getTemplates(array $filters = []): array
    {
        $sql = "SELECT * FROM proc_certificate_templates WHERE (tenant_id = ? OR tenant_id = 0)";
        $params = [$this->tenant_id]; $types = "i";
        if (!empty($filters['status'])) { $sql .= " AND status = ?"; $params[] = $filters['status']; $types .= "s"; }
        if (!empty($filters['type'])) { $sql .= " AND template_type = ?"; $params[] = $filters['type']; $types .= "s"; }
        $sql .= " ORDER BY is_default DESC, name ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $templates = [];
        while ($row = $result->fetch_assoc()) {
            $row['template_id'] = (int) $row['template_id'];
            $row['is_default'] = (bool) $row['is_default'];
            $templates[] = $row;
        }
        $stmt->close();
        return $templates;
    }

    public function cloneTemplate(int $template_id): array
    {
        $original = $this->getTemplate($template_id);
        if (!$original) return ['success' => false, 'message' => 'Not found'];
        unset($original['template_id'], $original['created_at'], $original['updated_at'], $original['usage_count']);
        $original['name'] = $original['name'] . ' (Copy)';
        $original['status'] = 'draft';
        $original['is_default'] = 0;
        return $this->createTemplate($original);
    }

    // ==========================================
    // TEMPLATE FIELDS
    // ==========================================

    public function createField(array $data): array
    {
        $template_id = (int) ($data['template_id'] ?? 0);
        $field_name = trim($data['field_name'] ?? '');
        $field_label = trim($data['field_label'] ?? $field_name);
        if (!$template_id || empty($field_name)) return ['success' => false, 'message' => 'Template ID and field name required'];

        $field_type = $data['field_type'] ?? 'text';
        $data_source = $data['data_source'] ?? 'static';
        $data_field = $data['data_field'] ?? null;
        $static_value = $data['static_value'] ?? null;
        $format_pattern = $data['format_pattern'] ?? null;
        $position_x = (int) ($data['position_x'] ?? 0);
        $position_y = (int) ($data['position_y'] ?? 0);
        $width = isset($data['width']) ? (int) $data['width'] : null;
        $height = isset($data['height']) ? (int) $data['height'] : null;
        $alignment = $data['alignment'] ?? 'center';
        $font_family = $data['font_family'] ?? 'Arial';
        $font_size = (int) ($data['font_size'] ?? 14);
        $font_weight = $data['font_weight'] ?? 'normal';
        $font_style = $data['font_style'] ?? 'normal';
        $font_color = $data['font_color'] ?? '#000000';
        $text_transform = $data['text_transform'] ?? 'none';
        $sort_order = (int) ($data['sort_order'] ?? 0);
        $is_required = isset($data['is_required']) ? (int) $data['is_required'] : 1;

        $stmt = $this->conn->prepare("INSERT INTO proc_certificate_fields (template_id, tenant_id, field_name, field_label, field_type, data_source, data_field, static_value, format_pattern, position_x, position_y, width, height, alignment, font_family, font_size, font_weight, font_style, font_color, text_transform, sort_order, is_required) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iisssssssiiiisisssssii", $template_id, $this->tenant_id, $field_name, $field_label, $field_type, $data_source, $data_field, $static_value, $format_pattern, $position_x, $position_y, $width, $height, $alignment, $font_family, $font_size, $font_weight, $font_style, $font_color, $text_transform, $sort_order, $is_required);
        if ($stmt->execute()) { $id = $stmt->insert_id; $stmt->close(); return ['success' => true, 'field_id' => $id]; }
        $stmt->close();
        return ['success' => false, 'message' => 'Failed'];
    }

    public function getTemplateFields(int $template_id): array
    {
        $stmt = $this->conn->prepare("SELECT * FROM proc_certificate_fields WHERE template_id = ? ORDER BY sort_order ASC");
        $stmt->bind_param("i", $template_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $fields = [];
        while ($row = $result->fetch_assoc()) { $row['field_id'] = (int) $row['field_id']; $row['is_required'] = (bool) $row['is_required']; $fields[] = $row; }
        $stmt->close();
        return $fields;
    }

    public function deleteField(int $field_id): array
    {
        $stmt = $this->conn->prepare("DELETE FROM proc_certificate_fields WHERE field_id = ? AND tenant_id = ?");
        $stmt->bind_param("ii", $field_id, $this->tenant_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected > 0 ? ['success' => true] : ['success' => false, 'message' => 'Not found'];
    }

    // ==========================================
    // ASSESSMENT CERTIFICATE SETTINGS
    // ==========================================

    public function setAssessmentCertificate(int $assessment_id, array $data): array
    {
        $template_id = (int) ($data['template_id'] ?? 0);
        if (!$template_id) return ['success' => false, 'message' => 'Template ID required'];

        $auto_issue = isset($data['auto_issue']) ? (int) $data['auto_issue'] : 1;
        $min_score = isset($data['min_score']) ? (float) $data['min_score'] : null;
        $require_pass = isset($data['require_pass']) ? (int) $data['require_pass'] : 1;
        $custom_title = $data['custom_title'] ?? null;
        $custom_subtitle = $data['custom_subtitle'] ?? null;
        $custom_description = $data['custom_description'] ?? null;
        $notify_learner = isset($data['notify_learner']) ? (int) $data['notify_learner'] : 1;

        $stmt = $this->conn->prepare("INSERT INTO proc_assessment_certificates (assessment_id, template_id, tenant_id, auto_issue, min_score, require_pass, custom_title, custom_subtitle, custom_description, notify_learner, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE template_id = VALUES(template_id), auto_issue = VALUES(auto_issue), min_score = VALUES(min_score), require_pass = VALUES(require_pass), custom_title = VALUES(custom_title), custom_subtitle = VALUES(custom_subtitle), custom_description = VALUES(custom_description), notify_learner = VALUES(notify_learner)");
        $stmt->bind_param("iiiiidsssi", $assessment_id, $template_id, $this->tenant_id, $auto_issue, $min_score, $require_pass, $custom_title, $custom_subtitle, $custom_description, $notify_learner);
        if ($stmt->execute()) { $stmt->close(); return ['success' => true, 'message' => 'Settings saved']; }
        $stmt->close();
        return ['success' => false, 'message' => 'Failed'];
    }

    public function getAssessmentCertificateSettings(int $assessment_id): ?array
    {
        $stmt = $this->conn->prepare("SELECT ac.*, t.name as template_name FROM proc_assessment_certificates ac JOIN proc_certificate_templates t ON ac.template_id = t.template_id WHERE ac.assessment_id = ? AND ac.tenant_id = ?");
        $stmt->bind_param("ii", $assessment_id, $this->tenant_id);
        $stmt->execute();
        $settings = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($settings) { $settings['auto_issue'] = (bool) $settings['auto_issue']; $settings['require_pass'] = (bool) $settings['require_pass']; }
        return $settings;
    }

    // ==========================================
    // CERTIFICATE ISSUANCE
    // ==========================================

    public function issueCertificate(int $attempt_id, ?array $custom_data = null): array
    {
        // Get attempt with assessment and user data
        $stmt = $this->conn->prepare("SELECT a.*, ass.title as assessment_title, ass.assessment_id, u.first_name, u.last_name, u.email FROM proc_attempts a JOIN proc_assessments ass ON a.assessment_id = ass.assessment_id JOIN users u ON a.user_id = u.id WHERE a.attempt_id = ? AND a.tenant_id = ?");
        $stmt->bind_param("ii", $attempt_id, $this->tenant_id);
        $stmt->execute();
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$attempt) return ['success' => false, 'message' => 'Attempt not found'];

        // Check if already issued
        $stmt = $this->conn->prepare("SELECT certificate_id FROM proc_certificates WHERE attempt_id = ?");
        $stmt->bind_param("i", $attempt_id);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) { $stmt->close(); return ['success' => false, 'message' => 'Certificate already issued']; }
        $stmt->close();

        // Get certificate settings
        $settings = $this->getAssessmentCertificateSettings((int) $attempt['assessment_id']);
        if (!$settings) return ['success' => false, 'message' => 'No certificate configured for this assessment'];

        // Get template
        $template = $this->getTemplate((int) $settings['template_id']);
        if (!$template) return ['success' => false, 'message' => 'Template not found'];

        // Generate unique identifiers
        $certificate_number = $this->generateCertificateNumber();
        $verification_code = $this->generateVerificationCode();
        $verification_hash = hash('sha256', $certificate_number . $verification_code . time());

        // Calculate validity
        $valid_from = date('Y-m-d');
        $valid_until = null;
        if ($template['validity_type'] === 'fixed_period' && $template['validity_period_months']) {
            $valid_until = date('Y-m-d', strtotime("+{$template['validity_period_months']} months"));
        } elseif ($template['validity_type'] === 'fixed_date' && $template['validity_end_date']) {
            $valid_until = $template['validity_end_date'];
        }

        $learner_name = trim($attempt['first_name'] . ' ' . $attempt['last_name']);
        $custom_data_json = $custom_data ? json_encode($custom_data) : null;

        $stmt = $this->conn->prepare("INSERT INTO proc_certificates (tenant_id, certificate_number, verification_code, verification_hash, template_id, assessment_id, attempt_id, user_id, learner_name, learner_email, assessment_title, score, percentage, grade, custom_data, valid_from, valid_until, status, created_by, created_at, issued_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW(), NOW())");
        $stmt->bind_param("isssiiiisssddssssi", $this->tenant_id, $certificate_number, $verification_code, $verification_hash, $settings['template_id'], $attempt['assessment_id'], $attempt_id, $attempt['user_id'], $learner_name, $attempt['email'], $attempt['assessment_title'], $attempt['raw_score'], $attempt['percentage_score'], $attempt['letter_grade'], $custom_data_json, $valid_from, $valid_until, $this->user_id);

        if ($stmt->execute()) {
            $certificate_id = $stmt->insert_id;
            $stmt->close();

            // Update learner_assessments
            $this->conn->query("UPDATE proc_learner_assessments SET certificate_issued = 1, certificate_id = $certificate_id WHERE user_id = {$attempt['user_id']} AND assessment_id = {$attempt['assessment_id']}");

            return [
                'success' => true,
                'certificate_id' => $certificate_id,
                'certificate_number' => $certificate_number,
                'verification_code' => $verification_code,
                'message' => 'Certificate issued'
            ];
        }
        $stmt->close();
        return ['success' => false, 'message' => 'Failed to issue certificate'];
    }

    public function bulkIssueCertificates(int $assessment_id): array
    {
        $stmt = $this->conn->prepare("SELECT a.attempt_id FROM proc_attempts a LEFT JOIN proc_certificates c ON a.attempt_id = c.attempt_id WHERE a.assessment_id = ? AND a.tenant_id = ? AND a.status = 'graded' AND a.passed = 1 AND c.certificate_id IS NULL");
        $stmt->bind_param("ii", $assessment_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $issued = 0; $failed = 0;
        while ($row = $result->fetch_assoc()) {
            $res = $this->issueCertificate((int) $row['attempt_id']);
            $res['success'] ? $issued++ : $failed++;
        }
        $stmt->close();
        return ['success' => true, 'issued' => $issued, 'failed' => $failed];
    }

    private function generateCertificateNumber(): string
    {
        $prefix = 'CERT';
        $year = date('Y');
        $random = strtoupper(bin2hex(random_bytes(4)));
        return "{$prefix}-{$year}-{$random}";
    }

    private function generateVerificationCode(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) { $code .= $chars[random_int(0, strlen($chars) - 1)]; }
        return $code;
    }

    // ==========================================
    // CERTIFICATE RETRIEVAL
    // ==========================================

    public function getCertificate(int $certificate_id): ?array
    {
        $stmt = $this->conn->prepare("SELECT c.*, t.name as template_name FROM proc_certificates c JOIN proc_certificate_templates t ON c.template_id = t.template_id WHERE c.certificate_id = ? AND c.tenant_id = ?");
        $stmt->bind_param("ii", $certificate_id, $this->tenant_id);
        $stmt->execute();
        $cert = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($cert) {
            $cert['certificate_id'] = (int) $cert['certificate_id'];
            $cert['expired'] = (bool) $cert['expired'];
            $cert['custom_data'] = $cert['custom_data'] ? json_decode($cert['custom_data'], true) : null;
            $cert['verification_url'] = $this->verification_base_url . '/verify/' . $cert['verification_hash'];
        }
        return $cert;
    }

    public function getCertificateByCode(string $code): ?array
    {
        $stmt = $this->conn->prepare("SELECT c.*, t.name as template_name FROM proc_certificates c JOIN proc_certificate_templates t ON c.template_id = t.template_id WHERE c.verification_code = ? OR c.verification_hash = ? OR c.certificate_number = ?");
        $stmt->bind_param("sss", $code, $code, $code);
        $stmt->execute();
        $cert = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($cert) { $cert['certificate_id'] = (int) $cert['certificate_id']; $cert['expired'] = (bool) $cert['expired']; }
        return $cert;
    }

    public function getCertificates(array $filters = []): array
    {
        $sql = "SELECT c.*, t.name as template_name FROM proc_certificates c JOIN proc_certificate_templates t ON c.template_id = t.template_id WHERE c.tenant_id = ?";
        $params = [$this->tenant_id]; $types = "i";
        if (!empty($filters['user_id'])) { $sql .= " AND c.user_id = ?"; $params[] = (int) $filters['user_id']; $types .= "i"; }
        if (!empty($filters['assessment_id'])) { $sql .= " AND c.assessment_id = ?"; $params[] = (int) $filters['assessment_id']; $types .= "i"; }
        if (!empty($filters['status'])) { $sql .= " AND c.status = ?"; $params[] = $filters['status']; $types .= "s"; }
        $sql .= " ORDER BY c.issued_at DESC";
        if (!empty($filters['limit'])) { $sql .= " LIMIT " . (int) $filters['limit']; }

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $certs = [];
        while ($row = $result->fetch_assoc()) { $row['certificate_id'] = (int) $row['certificate_id']; $certs[] = $row; }
        $stmt->close();
        return $certs;
    }

    public function getMyCertificates(): array
    {
        return $this->getCertificates(['user_id' => $this->user_id]);
    }

    // ==========================================
    // VERIFICATION
    // ==========================================

    public function verifyCertificate(string $code, array $verifier_info = []): array
    {
        $cert = $this->getCertificateByCode($code);
        $is_valid = false;
        $message = '';
        $status_at_verification = '';

        if (!$cert) {
            $message = 'Certificate not found';
        } else {
            $status_at_verification = $cert['status'];
            if ($cert['status'] === 'revoked') {
                $message = 'Certificate has been revoked';
            } elseif ($cert['status'] === 'expired' || ($cert['valid_until'] && $cert['valid_until'] < date('Y-m-d'))) {
                $message = 'Certificate has expired';
            } elseif ($cert['status'] === 'active') {
                $is_valid = true;
                $message = 'Certificate is valid';
            } else {
                $message = 'Certificate status: ' . $cert['status'];
            }
        }

        // Log verification
        if ($cert) {
            $method = $verifier_info['method'] ?? 'url';
            $ip = $verifier_info['ip'] ?? null;
            $ua = $verifier_info['user_agent'] ?? null;
            $name = $verifier_info['name'] ?? null;
            $email = $verifier_info['email'] ?? null;
            $org = $verifier_info['organization'] ?? null;

            $stmt = $this->conn->prepare("INSERT INTO proc_certificate_verifications (certificate_id, tenant_id, verification_method, verification_code_used, is_valid, status_at_verification, message, verifier_ip, verifier_user_agent, verifier_name, verifier_email, verifier_organization) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iisssissssss", $cert['certificate_id'], $cert['tenant_id'], $method, $code, $is_valid, $status_at_verification, $message, $ip, $ua, $name, $email, $org);
            $stmt->execute();
            $stmt->close();

            $this->conn->query("UPDATE proc_certificates SET verification_count = verification_count + 1, last_verified_at = NOW() WHERE certificate_id = {$cert['certificate_id']}");
        }

        return [
            'is_valid' => $is_valid,
            'message' => $message,
            'certificate' => $is_valid ? [
                'certificate_number' => $cert['certificate_number'],
                'learner_name' => $cert['learner_name'],
                'assessment_title' => $cert['assessment_title'],
                'percentage' => $cert['percentage'],
                'grade' => $cert['grade'],
                'issued_at' => $cert['issued_at'],
                'valid_until' => $cert['valid_until'],
                'status' => $cert['status']
            ] : null
        ];
    }

    // ==========================================
    // REVOCATION & RENEWAL
    // ==========================================

    public function revokeCertificate(int $certificate_id, string $reason): array
    {
        $stmt = $this->conn->prepare("UPDATE proc_certificates SET status = 'revoked', revoked_at = NOW(), revoked_by = ?, revocation_reason = ? WHERE certificate_id = ? AND tenant_id = ? AND status = 'active'");
        $stmt->bind_param("isii", $this->user_id, $reason, $certificate_id, $this->tenant_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected > 0 ? ['success' => true, 'message' => 'Certificate revoked'] : ['success' => false, 'message' => 'Not found or already revoked'];
    }

    public function renewCertificate(int $original_certificate_id, string $reason = 'expiration'): array
    {
        $original = $this->getCertificate($original_certificate_id);
        if (!$original) return ['success' => false, 'message' => 'Original certificate not found'];

        // Mark original as superseded
        $this->conn->query("UPDATE proc_certificates SET status = 'superseded' WHERE certificate_id = $original_certificate_id");

        // Issue new certificate
        $result = $this->issueCertificate((int) $original['attempt_id']);
        if (!$result['success']) return $result;

        // Log renewal
        $stmt = $this->conn->prepare("INSERT INTO proc_certificate_renewals (original_certificate_id, new_certificate_id, tenant_id, renewal_reason, renewed_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iiisi", $original_certificate_id, $result['certificate_id'], $this->tenant_id, $reason, $this->user_id);
        $stmt->execute();
        $stmt->close();

        return $result;
    }

    // ==========================================
    // DOWNLOAD TRACKING
    // ==========================================

    public function trackDownload(int $certificate_id): void
    {
        $this->conn->query("UPDATE proc_certificates SET download_count = download_count + 1, last_downloaded_at = NOW() WHERE certificate_id = $certificate_id");
    }

    // ==========================================
    // STATISTICS
    // ==========================================

    public function getCertificateStats(?int $assessment_id = null): array
    {
        $where = "WHERE tenant_id = ?";
        $params = [$this->tenant_id]; $types = "i";
        if ($assessment_id) { $where .= " AND assessment_id = ?"; $params[] = $assessment_id; $types .= "i"; }

        $stmt = $this->conn->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active, SUM(CASE WHEN status = 'revoked' THEN 1 ELSE 0 END) as revoked, SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired, SUM(download_count) as total_downloads, SUM(verification_count) as total_verifications FROM proc_certificates $where");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return [
            'total' => (int) $stats['total'],
            'active' => (int) $stats['active'],
            'revoked' => (int) $stats['revoked'],
            'expired' => (int) $stats['expired'],
            'total_downloads' => (int) $stats['total_downloads'],
            'total_verifications' => (int) $stats['total_verifications']
        ];
    }

    public function list(): array {
        $page = max(1, $this->page);
        $perPage = max(1, min(100, $this->per_page));
        $offset = ($page - 1) * $perPage;
        $where = "WHERE 1=1";
        $params = [];
        $types = "";
        $allowedColumns = ['tenant_id','created_at'];
        $allowedDirections = ['asc', 'desc'];
        $sortBy = in_array($this->sort_by, $allowedColumns) ? $this->sort_by : 'tenant_id';
        $sortDir = in_array(strtolower($this->sort_dir), $allowedDirections) ? strtoupper($this->sort_dir) : 'ASC';
        $countQuery = "SELECT COUNT(*) AS total FROM proc_assessment_certificates $where";
        $countStmt = $this->conn->prepare($countQuery);
        if ($types) $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $query = "SELECT * FROM proc_assessment_certificates $where ORDER BY $sortBy $sortDir LIMIT ? OFFSET ?";
        $stmt = $this->conn->prepare($query);
        $types2 = $types . "ii";
        $params2 = array_merge($params, [$perPage, $offset]);
        $stmt->bind_param($types2, ...$params2);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        return ["data" => $rows, "meta" => ["total" => $total, "page" => $page, "per_page" => $perPage, "total_pages" => $totalPages, "sort_by" => $sortBy, "sort_dir" => $sortDir]];
    }
}
