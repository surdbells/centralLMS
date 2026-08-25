<?php
/**
 * Certificate API Endpoint
 * Multi-Tenant LMS - Admin & Learner Portal
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../class/Certificate.php';

$db = new Database();
$conn = $db->getConnection();

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) { $db->send_response(400, 'error', null, 'Invalid JSON'); exit; }

    $action = $input['action'] ?? '';

    // Public verification endpoint (no auth required)
    if ($action === 'verify' || $action === 'public-verify') {
        $code = $input['code'] ?? '';
        if (empty($code)) { $db->send_response(400, 'error', null, 'Verification code required'); exit; }
        
        $certificate = new Certificate($conn);
        $certificate->setTenant(0); // Will get tenant from certificate
        $result = $certificate->verifyCertificate($code, [
            'method' => $input['method'] ?? 'api',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'name' => $input['verifier_name'] ?? null,
            'email' => $input['verifier_email'] ?? null,
            'organization' => $input['verifier_organization'] ?? null
        ]);
        $db->send_response(200, $result['is_valid'] ? 'success' : 'error', $result, $result['message']);
        exit;
    }

    // Authenticated endpoints
    $token = $input['token'] ?? null;
    $user_id = (int) ($input['user_id'] ?? 0);
    $tenant_id = (int) ($input['tenant_id'] ?? 0);

    if (!$token || !$tenant_id || !$user_id) { $db->send_response(401, 'error', null, 'Auth required'); exit; }
    if ($db->verify_user($token, $user_id) != 100) { $db->send_response(401, 'error', null, 'Invalid token'); exit; }

    $certificate = new Certificate($conn);
    $certificate->setTenant($tenant_id);
    $certificate->setUser($user_id);

    switch ($action) {
        // TEMPLATES
        case 'create-template':
            $result = $certificate->createTemplate($input);
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', $result['success'] ? ['template_id' => $result['template_id']] : null, $result['message']);
            break;
        case 'update-template':
            $result = $certificate->updateTemplate((int) ($input['template_id'] ?? 0), $input);
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', null, $result['message']);
            break;
        case 'delete-template':
            $result = $certificate->deleteTemplate((int) ($input['template_id'] ?? 0));
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', null, $result['message']);
            break;
        case 'get-template':
            $template = $certificate->getTemplate((int) ($input['template_id'] ?? 0));
            $template ? $db->send_response(200, 'success', $template, 'Retrieved') : $db->send_response(404, 'error', null, 'Not found');
            break;
        case 'get-templates':
            $templates = $certificate->getTemplates($input);
            $db->send_response(200, 'success', $templates, 'Retrieved');
            break;
        case 'clone-template':
            $result = $certificate->cloneTemplate((int) ($input['template_id'] ?? 0));
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', $result['success'] ? ['template_id' => $result['template_id']] : null, $result['message']);
            break;

        // FIELDS
        case 'create-field':
            $result = $certificate->createField($input);
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', $result['success'] ? ['field_id' => $result['field_id']] : null, $result['message'] ?? '');
            break;
        case 'get-template-fields':
            $fields = $certificate->getTemplateFields((int) ($input['template_id'] ?? 0));
            $db->send_response(200, 'success', $fields, 'Retrieved');
            break;
        case 'delete-field':
            $result = $certificate->deleteField((int) ($input['field_id'] ?? 0));
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', null, $result['message'] ?? 'Deleted');
            break;

        // ASSESSMENT SETTINGS
        case 'set-assessment-certificate':
            $result = $certificate->setAssessmentCertificate((int) ($input['assessment_id'] ?? 0), $input);
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', null, $result['message']);
            break;
        case 'get-assessment-certificate':
            $settings = $certificate->getAssessmentCertificateSettings((int) ($input['assessment_id'] ?? 0));
            $db->send_response(200, 'success', $settings, 'Retrieved');
            break;

        // ISSUANCE
        case 'issue-certificate':
            $result = $certificate->issueCertificate((int) ($input['attempt_id'] ?? 0), $input['custom_data'] ?? null);
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', $result['success'] ? ['certificate_id' => $result['certificate_id'], 'certificate_number' => $result['certificate_number'], 'verification_code' => $result['verification_code']] : null, $result['message']);
            break;
        case 'bulk-issue':
            $result = $certificate->bulkIssueCertificates((int) ($input['assessment_id'] ?? 0));
            $db->send_response(200, 'success', $result, "Issued: {$result['issued']}, Failed: {$result['failed']}");
            break;

        // RETRIEVAL
        case 'get-certificate':
            $cert = $certificate->getCertificate((int) ($input['certificate_id'] ?? 0));
            $cert ? $db->send_response(200, 'success', $cert, 'Retrieved') : $db->send_response(404, 'error', null, 'Not found');
            break;
        case 'get-certificates':
            $certs = $certificate->getCertificates($input);
            $db->send_response(200, 'success', $certs, 'Retrieved');
            break;
        case 'get-my-certificates':
            $certs = $certificate->getMyCertificates();
            $db->send_response(200, 'success', $certs, 'Retrieved');
            break;

        // REVOCATION & RENEWAL
        case 'revoke-certificate':
            $result = $certificate->revokeCertificate((int) ($input['certificate_id'] ?? 0), $input['reason'] ?? 'No reason provided');
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', null, $result['message']);
            break;
        case 'renew-certificate':
            $result = $certificate->renewCertificate((int) ($input['certificate_id'] ?? 0), $input['reason'] ?? 'expiration');
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', $result['success'] ? ['certificate_id' => $result['certificate_id'], 'certificate_number' => $result['certificate_number']] : null, $result['message']);
            break;

        // DOWNLOAD
        case 'track-download':
            $certificate->trackDownload((int) ($input['certificate_id'] ?? 0));
            $db->send_response(200, 'success', null, 'Tracked');
            break;

        // STATS
        case 'get-certificate-stats':
            $stats = $certificate->getCertificateStats($input['assessment_id'] ?? null);
            $db->send_response(200, 'success', $stats, 'Retrieved');
            break;

        default:
            $db->send_response(400, 'error', null, 'Invalid action');
    }
} catch (Exception $e) {
    error_log('Certificate API Error: ' . $e->getMessage());
    $db->send_response(500, 'error', null, 'Server error: ' . $e->getMessage());
}
