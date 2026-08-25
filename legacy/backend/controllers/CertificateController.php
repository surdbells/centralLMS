<?php
/**
 * Certificate Controller
 * Handles certificate templates and issuance
 */

namespace App\Controllers\ProctoredAssessment;

use App\Services\ProctoredAssessment\CertificateService;

class CertificateController extends BaseController
{
    private $certificateService;

    public function __construct()
    {
        parent::__construct();
        $this->certificateService = new CertificateService($this->db);
    }

    // ==================== TEMPLATES ====================

    public function getTemplates()
    {
        try {
            $status = $this->input('status');
            
            $sql = "SELECT * FROM proc_certificate_templates WHERE tenant_id = :tenant_id";
            $params = ['tenant_id' => $this->tenantId];
            
            if ($status) {
                $sql .= " AND status = :status";
                $params['status'] = $status;
            }
            
            $sql .= " ORDER BY is_default DESC, name ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $this->success($stmt->fetchAll(\PDO::FETCH_ASSOC));
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function getTemplate($id)
    {
        try {
            $sql = "SELECT * FROM proc_certificate_templates WHERE id = :id AND tenant_id = :tenant_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id, 'tenant_id' => $this->tenantId]);
            $template = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$template) {
                return $this->notFound('Template not found');
            }
            
            // Decode JSON fields
            $template['layout'] = json_decode($template['layout'], true);
            $template['custom_fields'] = json_decode($template['custom_fields'], true);
            
            return $this->success($template);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function createTemplate()
    {
        try {
            $this->requireAdmin();
            $data = $this->getJsonInput();
            
            $this->validate($data, [
                'name' => 'required|max:255'
            ]);
            
            $sql = "INSERT INTO proc_certificate_templates (
                        tenant_id, name, description, orientation, paper_size,
                        background_image, background_color, layout, custom_fields,
                        signature_1_image, signature_1_name, signature_1_title,
                        signature_2_image, signature_2_name, signature_2_title,
                        seal_image, logo_image, border_style, include_qr_code,
                        qr_position, footer_text, status, created_by
                    ) VALUES (
                        :tenant_id, :name, :description, :orientation, :paper_size,
                        :background_image, :background_color, :layout, :custom_fields,
                        :signature_1_image, :signature_1_name, :signature_1_title,
                        :signature_2_image, :signature_2_name, :signature_2_title,
                        :seal_image, :logo_image, :border_style, :include_qr_code,
                        :qr_position, :footer_text, 'active', :created_by
                    )";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'tenant_id' => $this->tenantId,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'orientation' => $data['orientation'] ?? 'landscape',
                'paper_size' => $data['paper_size'] ?? 'A4',
                'background_image' => $data['background_image'] ?? null,
                'background_color' => $data['background_color'] ?? '#ffffff',
                'layout' => isset($data['layout']) ? json_encode($data['layout']) : null,
                'custom_fields' => isset($data['custom_fields']) ? json_encode($data['custom_fields']) : null,
                'signature_1_image' => $data['signature_1_image'] ?? null,
                'signature_1_name' => $data['signature_1_name'] ?? null,
                'signature_1_title' => $data['signature_1_title'] ?? null,
                'signature_2_image' => $data['signature_2_image'] ?? null,
                'signature_2_name' => $data['signature_2_name'] ?? null,
                'signature_2_title' => $data['signature_2_title'] ?? null,
                'seal_image' => $data['seal_image'] ?? null,
                'logo_image' => $data['logo_image'] ?? null,
                'border_style' => $data['border_style'] ?? 'none',
                'include_qr_code' => $data['include_qr_code'] ?? 1,
                'qr_position' => $data['qr_position'] ?? 'bottom-right',
                'footer_text' => $data['footer_text'] ?? null,
                'created_by' => $this->userId
            ]);
            
            $templateId = $this->db->lastInsertId();
            $this->logAction('create', 'certificate_template', $templateId, null, $data);
            
            return $this->success(['id' => $templateId], 'Template created successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function updateTemplate($id)
    {
        try {
            $this->requireAdmin();
            $data = $this->getJsonInput();
            
            $sql = "UPDATE proc_certificate_templates SET
                        name = :name,
                        description = :description,
                        orientation = :orientation,
                        paper_size = :paper_size,
                        background_image = :background_image,
                        background_color = :background_color,
                        layout = :layout,
                        custom_fields = :custom_fields,
                        signature_1_image = :signature_1_image,
                        signature_1_name = :signature_1_name,
                        signature_1_title = :signature_1_title,
                        signature_2_image = :signature_2_image,
                        signature_2_name = :signature_2_name,
                        signature_2_title = :signature_2_title,
                        seal_image = :seal_image,
                        logo_image = :logo_image,
                        border_style = :border_style,
                        include_qr_code = :include_qr_code,
                        qr_position = :qr_position,
                        footer_text = :footer_text,
                        updated_at = NOW()
                    WHERE id = :id AND tenant_id = :tenant_id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'id' => $id,
                'tenant_id' => $this->tenantId,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'orientation' => $data['orientation'] ?? 'landscape',
                'paper_size' => $data['paper_size'] ?? 'A4',
                'background_image' => $data['background_image'] ?? null,
                'background_color' => $data['background_color'] ?? '#ffffff',
                'layout' => isset($data['layout']) ? json_encode($data['layout']) : null,
                'custom_fields' => isset($data['custom_fields']) ? json_encode($data['custom_fields']) : null,
                'signature_1_image' => $data['signature_1_image'] ?? null,
                'signature_1_name' => $data['signature_1_name'] ?? null,
                'signature_1_title' => $data['signature_1_title'] ?? null,
                'signature_2_image' => $data['signature_2_image'] ?? null,
                'signature_2_name' => $data['signature_2_name'] ?? null,
                'signature_2_title' => $data['signature_2_title'] ?? null,
                'seal_image' => $data['seal_image'] ?? null,
                'logo_image' => $data['logo_image'] ?? null,
                'border_style' => $data['border_style'] ?? 'none',
                'include_qr_code' => $data['include_qr_code'] ?? 1,
                'qr_position' => $data['qr_position'] ?? 'bottom-right',
                'footer_text' => $data['footer_text'] ?? null
            ]);
            
            $this->logAction('update', 'certificate_template', $id);
            
            return $this->success(null, 'Template updated successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function deleteTemplate($id)
    {
        try {
            $this->requireAdmin();
            
            // Check if template is in use
            $sql = "SELECT COUNT(*) FROM proc_certificates_issued WHERE template_id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id]);
            
            if ($stmt->fetchColumn() > 0) {
                return $this->error('Cannot delete template that has issued certificates');
            }
            
            $sql = "UPDATE proc_certificate_templates SET status = 'archived' WHERE id = :id AND tenant_id = :tenant_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id, 'tenant_id' => $this->tenantId]);
            
            $this->logAction('delete', 'certificate_template', $id);
            
            return $this->success(null, 'Template deleted successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function cloneTemplate($id)
    {
        try {
            $this->requireAdmin();
            
            $sql = "SELECT * FROM proc_certificate_templates WHERE id = :id AND tenant_id = :tenant_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id, 'tenant_id' => $this->tenantId]);
            $template = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$template) {
                return $this->notFound('Template not found');
            }
            
            unset($template['id'], $template['created_at'], $template['updated_at']);
            $template['name'] = $template['name'] . ' (Copy)';
            $template['is_default'] = 0;
            $template['created_by'] = $this->userId;
            
            $columns = array_keys($template);
            $placeholders = array_map(fn($c) => ":$c", $columns);
            
            $sql = "INSERT INTO proc_certificate_templates (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($template);
            
            return $this->success(['id' => $this->db->lastInsertId()], 'Template cloned successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function setDefaultTemplate($id)
    {
        try {
            $this->requireAdmin();
            
            // Remove default from all
            $sql = "UPDATE proc_certificate_templates SET is_default = 0 WHERE tenant_id = :tenant_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['tenant_id' => $this->tenantId]);
            
            // Set new default
            $sql = "UPDATE proc_certificate_templates SET is_default = 1 WHERE id = :id AND tenant_id = :tenant_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id, 'tenant_id' => $this->tenantId]);
            
            return $this->success(null, 'Default template set');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function uploadTemplateAsset($id)
    {
        try {
            $this->requireAdmin();
            
            $file = $this->getFile('file');
            $type = $this->input('type');
            
            if (!$file) {
                return $this->error('No file uploaded');
            }
            
            $allowedTypes = ['background', 'signature_1', 'signature_2', 'seal', 'logo'];
            if (!in_array($type, $allowedTypes)) {
                return $this->error('Invalid asset type');
            }
            
            $path = $this->uploadFile($file, 'certificate', ['jpg', 'jpeg', 'png', 'gif']);
            
            $column = $type . '_image';
            if ($type === 'background') $column = 'background_image';
            
            $sql = "UPDATE proc_certificate_templates SET $column = :path WHERE id = :id AND tenant_id = :tenant_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['path' => $path, 'id' => $id, 'tenant_id' => $this->tenantId]);
            
            return $this->success(['path' => $path], 'Asset uploaded successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function previewTemplate($id)
    {
        try {
            $data = $this->getJsonInput();
            $sampleData = $data['sample_data'] ?? $this->getSampleData();
            
            $pdf = $this->certificateService->generatePreview($id, $this->tenantId, $sampleData);
            
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="preview.pdf"');
            echo $pdf;
            exit;
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== ISSUED CERTIFICATES ====================

    public function getIssuedCertificates()
    {
        try {
            $filters = [
                'user_id' => $this->input('user_id'),
                'assessment_id' => $this->input('assessment_id'),
                'status' => $this->input('status'),
                'search' => $this->input('search'),
                'date_from' => $this->input('date_from'),
                'date_to' => $this->input('date_to')
            ];
            $page = (int)($this->input('page') ?? 1);
            $limit = (int)($this->input('limit') ?? 20);
            
            $where = ["c.tenant_id = :tenant_id"];
            $params = ['tenant_id' => $this->tenantId];
            
            if ($filters['user_id']) {
                $where[] = "c.user_id = :user_id";
                $params['user_id'] = $filters['user_id'];
            }
            if ($filters['assessment_id']) {
                $where[] = "c.assessment_id = :assessment_id";
                $params['assessment_id'] = $filters['assessment_id'];
            }
            if ($filters['status']) {
                $where[] = "c.status = :status";
                $params['status'] = $filters['status'];
            }
            if ($filters['search']) {
                $where[] = "(c.certificate_number LIKE :search OR u.first_name LIKE :search OR u.last_name LIKE :search)";
                $params['search'] = '%' . $filters['search'] . '%';
            }
            if ($filters['date_from']) {
                $where[] = "c.issued_at >= :date_from";
                $params['date_from'] = $filters['date_from'];
            }
            if ($filters['date_to']) {
                $where[] = "c.issued_at <= :date_to";
                $params['date_to'] = $filters['date_to'];
            }
            
            $whereClause = implode(' AND ', $where);
            
            $sql = "SELECT c.*, 
                           u.first_name, u.last_name, u.email,
                           t.name as template_name,
                           a.title as assessment_title
                    FROM proc_certificates_issued c
                    INNER JOIN users u ON c.user_id = u.id
                    INNER JOIN proc_certificate_templates t ON c.template_id = t.id
                    LEFT JOIN proc_assessments a ON c.assessment_id = a.id
                    WHERE $whereClause
                    ORDER BY c.issued_at DESC";
            
            return $this->json($this->paginate($sql, $params, $page, $limit));
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function getCertificate($id)
    {
        try {
            $sql = "SELECT c.*, 
                           u.first_name, u.last_name, u.email,
                           t.name as template_name,
                           a.title as assessment_title
                    FROM proc_certificates_issued c
                    INNER JOIN users u ON c.user_id = u.id
                    INNER JOIN proc_certificate_templates t ON c.template_id = t.id
                    LEFT JOIN proc_assessments a ON c.assessment_id = a.id
                    WHERE c.course_id = :id AND c.tenant_id = :tenant_id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id, 'tenant_id' => $this->tenantId]);
            $cert = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$cert) {
                return $this->notFound('Certificate not found');
            }
            
            $cert['custom_fields'] = json_decode($cert['custom_fields'], true);
            
            return $this->success($cert);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function issueCertificate()
    {
        try {
            $this->requireAdmin();
            $data = $this->getJsonInput();
            
            $this->validate($data, [
                'template_id' => 'required|integer',
                'user_id' => 'required|integer'
            ]);
            
            $result = $this->certificateService->issue(
                $this->tenantId,
                $data['template_id'],
                $data['user_id'],
                [
                    'assessment_id' => $data['assessment_id'] ?? null,
                    'attempt_id' => $data['attempt_id'] ?? null,
                    'course_id' => $data['course_id'] ?? null,
                    'live_training_id' => $data['live_training_id'] ?? null,
                    'custom_fields' => $data['custom_fields'] ?? null,
                    'expiry_date' => $data['expiry_date'] ?? null
                ],
                $this->userId
            );
            
            if ($data['send_email'] ?? false) {
                $this->certificateService->sendEmail($result['id']);
            }
            
            return $this->success($result, 'Certificate issued successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function bulkIssueCertificates()
    {
        try {
            $this->requireAdmin();
            $data = $this->getJsonInput();
            
            $results = [
                'issued_count' => 0,
                'failed_count' => 0,
                'certificates' => []
            ];
            
            foreach ($data['recipients'] as $recipient) {
                try {
                    $result = $this->certificateService->issue(
                        $this->tenantId,
                        $data['template_id'],
                        $recipient['user_id'],
                        [
                            'assessment_id' => $recipient['assessment_id'] ?? null,
                            'attempt_id' => $recipient['attempt_id'] ?? null,
                            'custom_fields' => $recipient['custom_fields'] ?? null
                        ],
                        $this->userId
                    );
                    
                    $results['issued_count']++;
                    $results['certificates'][] = [
                        'user_id' => $recipient['user_id'],
                        'certificate_id' => $result['id']
                    ];
                    
                    if ($data['send_email'] ?? false) {
                        $this->certificateService->sendEmail($result['id']);
                    }
                } catch (\Exception $e) {
                    $results['failed_count']++;
                    $results['certificates'][] = [
                        'user_id' => $recipient['user_id'],
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            return $this->success($results);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function revokeCertificate($id)
    {
        try {
            $this->requireAdmin();
            $data = $this->getJsonInput();
            
            $sql = "UPDATE proc_certificates_issued SET 
                        status = 'revoked',
                        revoked_at = NOW(),
                        revoked_by = :revoked_by,
                        revocation_reason = :reason
                    WHERE id = :id AND tenant_id = :tenant_id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'id' => $id,
                'tenant_id' => $this->tenantId,
                'revoked_by' => $this->userId,
                'reason' => $data['reason'] ?? null
            ]);
            
            $this->logAction('revoke', 'certificate', $id);
            
            return $this->success(null, 'Certificate revoked');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function reinstateCertificate($id)
    {
        try {
            $this->requireAdmin();
            
            $sql = "UPDATE proc_certificates_issued SET 
                        status = 'valid',
                        revoked_at = NULL,
                        revoked_by = NULL,
                        revocation_reason = NULL
                    WHERE id = :id AND tenant_id = :tenant_id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id, 'tenant_id' => $this->tenantId]);
            
            $this->logAction('reinstate', 'certificate', $id);
            
            return $this->success(null, 'Certificate reinstated');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function downloadCertificate($id)
    {
        try {
            $sql = "SELECT * FROM proc_certificates_issued WHERE id = :id AND tenant_id = :tenant_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id, 'tenant_id' => $this->tenantId]);
            $cert = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$cert) {
                return $this->notFound('Certificate not found');
            }
            
            $filePath = $_SERVER['DOCUMENT_ROOT'] . '/' . $cert['pdf_path'];
            
            if (!file_exists($filePath)) {
                // Regenerate if not exists
                $this->certificateService->regeneratePdf($id);
                $stmt->execute(['id' => $id, 'tenant_id' => $this->tenantId]);
                $cert = $stmt->fetch(\PDO::FETCH_ASSOC);
                $filePath = $_SERVER['DOCUMENT_ROOT'] . '/' . $cert['pdf_path'];
            }
            
            // Update download count
            $sql = "UPDATE proc_certificates_issued SET download_count = download_count + 1 WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id]);
            
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="Certificate_' . $cert['certificate_number'] . '.pdf"');
            readfile($filePath);
            exit;
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function sendCertificateEmail($id)
    {
        try {
            $email = $this->input('email');
            $this->certificateService->sendEmail($id, $email);
            return $this->success(null, 'Email sent successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== VERIFICATION ====================

    public function verifyCertificate($code)
    {
        try {
            $sql = "SELECT c.*, 
                           u.first_name, u.last_name,
                           a.title as assessment_title
                    FROM proc_certificates_issued c
                    INNER JOIN users u ON c.user_id = u.id
                    LEFT JOIN proc_assessments a ON c.assessment_id = a.id
                    WHERE c.verification_code = :code";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['code' => $code]);
            $cert = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$cert) {
                return $this->success(['valid' => false, 'message' => 'Certificate not found']);
            }
            
            // Check expiry
            if ($cert['expires_at'] && strtotime($cert['expires_at']) < time()) {
                $cert['status'] = 'expired';
            }
            
            return $this->success([
                'valid' => $cert['status'] === 'valid',
                'certificate' => $cert
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function publicVerify($code)
    {
        try {
            $sql = "SELECT c.certificate_number, c.issued_at, c.expires_at, c.status,
                           u.first_name, u.last_name,
                           a.title as assessment_title,
                           t.name as issuer
                    FROM proc_certificates_issued c
                    INNER JOIN users u ON c.user_id = u.id
                    INNER JOIN proc_certificate_templates t ON c.template_id = t.id
                    LEFT JOIN proc_assessments a ON c.assessment_id = a.id
                    WHERE c.verification_code = :code";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['code' => $code]);
            $cert = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$cert) {
                return $this->success(['valid' => false]);
            }
            
            $status = $cert['status'];
            if ($cert['expires_at'] && strtotime($cert['expires_at']) < time()) {
                $status = 'expired';
            }
            
            return $this->success([
                'valid' => $status === 'valid',
                'learner_name' => $cert['first_name'] . ' ' . $cert['last_name'],
                'certificate_number' => $cert['certificate_number'],
                'title' => $cert['assessment_title'],
                'issue_date' => $cert['issued_at'],
                'expiry_date' => $cert['expires_at'],
                'status' => $status,
                'issuer' => $cert['issuer']
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== LEARNER CERTIFICATES ====================

    public function getMyCertificates()
    {
        try {
            $status = $this->input('status');
            $page = (int)($this->input('page') ?? 1);
            $limit = (int)($this->input('limit') ?? 20);
            
            $where = ["c.user_id = :user_id", "c.tenant_id = :tenant_id"];
            $params = ['user_id' => $this->userId, 'tenant_id' => $this->tenantId];
            
            if ($status === 'valid') {
                $where[] = "(c.status = 'valid' AND (c.expires_at IS NULL OR c.expires_at > NOW()))";
            } elseif ($status === 'expired') {
                $where[] = "(c.status = 'expired' OR (c.expires_at IS NOT NULL AND c.expires_at <= NOW()))";
            }
            
            $whereClause = implode(' AND ', $where);
            
            $sql = "SELECT c.*, 
                           t.name as template_name,
                           a.title as assessment_title
                    FROM proc_certificates_issued c
                    INNER JOIN proc_certificate_templates t ON c.template_id = t.id
                    LEFT JOIN proc_assessments a ON c.assessment_id = a.id
                    WHERE $whereClause
                    ORDER BY c.issued_at DESC";
            
            return $this->json($this->paginate($sql, $params, $page, $limit));
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function downloadMyCertificate($id)
    {
        try {
            $sql = "SELECT * FROM proc_certificates_issued WHERE id = :id AND user_id = :user_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id, 'user_id' => $this->userId]);
            $cert = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$cert) {
                return $this->notFound('Certificate not found');
            }
            
            return $this->downloadCertificate($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== STATISTICS ====================

    public function getCertificateStats()
    {
        try {
            $stats = [];
            
            // Total counts
            $sql = "SELECT 
                        COUNT(*) as total_issued,
                        SUM(CASE WHEN status = 'valid' AND (expires_at IS NULL OR expires_at > NOW()) THEN 1 ELSE 0 END) as valid,
                        SUM(CASE WHEN expires_at IS NOT NULL AND expires_at <= NOW() THEN 1 ELSE 0 END) as expired,
                        SUM(CASE WHEN status = 'revoked' THEN 1 ELSE 0 END) as revoked,
                        SUM(CASE WHEN issued_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as issued_this_month,
                        SUM(CASE WHEN expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as expiring_soon
                    FROM proc_certificates_issued
                    WHERE tenant_id = :tenant_id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['tenant_id' => $this->tenantId]);
            $stats = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            // By template
            $sql = "SELECT t.template_id as template_id, t.name as template_name, COUNT(c.id) as count
                    FROM proc_certificate_templates t
                    LEFT JOIN proc_assessment_certificates c ON t.template_id = c.template_id
                    WHERE t.tenant_id = :tenant_id
                    GROUP BY t.template_id, t.name
                    ORDER BY count DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['tenant_id' => $this->tenantId]);
            $stats['by_template'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // By assessment
            $sql = "SELECT a.assessment_id as assessment_id, a.title as assessment_title, COUNT(c.id) as count
                    FROM proc_assessments a
                    LEFT JOIN proc_certificates_issued c ON a.id = c.assessment_id
                    WHERE a.tenant_id = :tenant_id AND a.issue_certificate = 1
                    GROUP BY a.id, a.title
                    ORDER BY count DESC
                    LIMIT 10";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['tenant_id' => $this->tenantId]);
            $stats['by_assessment'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            return $this->success($stats);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== PLACEHOLDERS ====================

    public function getAvailablePlaceholders()
    {
        return $this->success([
            [
                'category' => 'Learner',
                'placeholders' => [
                    ['key' => '{{learner_name}}', 'label' => 'Full Name', 'example' => 'John Doe'],
                    ['key' => '{{learner_first_name}}', 'label' => 'First Name', 'example' => 'John'],
                    ['key' => '{{learner_last_name}}', 'label' => 'Last Name', 'example' => 'Doe'],
                    ['key' => '{{learner_email}}', 'label' => 'Email', 'example' => 'john@example.com']
                ]
            ],
            [
                'category' => 'Assessment',
                'placeholders' => [
                    ['key' => '{{assessment_title}}', 'label' => 'Assessment Title', 'example' => 'Final Exam'],
                    ['key' => '{{score}}', 'label' => 'Score', 'example' => '85'],
                    ['key' => '{{percentage}}', 'label' => 'Percentage', 'example' => '85%'],
                    ['key' => '{{grade}}', 'label' => 'Grade', 'example' => 'A']
                ]
            ],
            [
                'category' => 'Certificate',
                'placeholders' => [
                    ['key' => '{{certificate_number}}', 'label' => 'Certificate Number', 'example' => 'CERT-2024-001'],
                    ['key' => '{{issue_date}}', 'label' => 'Issue Date', 'example' => 'January 15, 2024'],
                    ['key' => '{{expiry_date}}', 'label' => 'Expiry Date', 'example' => 'January 15, 2025'],
                    ['key' => '{{verification_url}}', 'label' => 'Verification URL', 'example' => 'https://...']
                ]
            ],
            [
                'category' => 'Course',
                'placeholders' => [
                    ['key' => '{{course_title}}', 'label' => 'Course Title', 'example' => 'Web Development'],
                    ['key' => '{{course_hours}}', 'label' => 'Course Hours', 'example' => '40']
                ]
            ],
            [
                'category' => 'Organization',
                'placeholders' => [
                    ['key' => '{{org_name}}', 'label' => 'Organization Name', 'example' => 'Acme Corp'],
                    ['key' => '{{org_logo}}', 'label' => 'Organization Logo', 'example' => '[Image]']
                ]
            ]
        ]);
    }

    private function getSampleData()
    {
        return [
            'learner_name' => 'John Doe',
            'learner_first_name' => 'John',
            'learner_last_name' => 'Doe',
            'learner_email' => 'john.doe@example.com',
            'assessment_title' => 'Sample Assessment',
            'score' => 85,
            'percentage' => '85%',
            'grade' => 'A',
            'certificate_number' => 'CERT-SAMPLE-001',
            'issue_date' => date('F j, Y'),
            'expiry_date' => date('F j, Y', strtotime('+1 year')),
            'course_title' => 'Sample Course',
            'course_hours' => 40
        ];
    }
}
