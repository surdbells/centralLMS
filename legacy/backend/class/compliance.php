<?php

/**
 * Admin API Endpoint
 * Handles audit logs, security events, errors, and GDPR compliance
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../class/AuditLogger.php';
require_once __DIR__ . '/../class/SecurityLogger.php';
require_once __DIR__ . '/../class/ErrorLogger.php';
require_once __DIR__ . '/../class/GDPRManager.php';
require_once __DIR__ . '/../class/CertificateLogger.php';

// Authenticate request
$auth = authenticate();
if (!$auth['success']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user = $auth['user'];
$tenant_id = $user['tenant_id'];
$user_id = $user['id'];
$user_role = $user['role'];

// Most endpoints require admin access
$admin_roles = ['admin', 'superadmin'];
$hr_roles = ['admin', 'superadmin', 'hr'];

// Initialize database
$db = new Database();
$conn = $db->getConnection();

// Get request method and resource
$method = $_SERVER['REQUEST_METHOD'];
$resource = $_GET['resource'] ?? '';
$action = $_GET['action'] ?? '';

try {
    switch ($resource) {
        // ==========================================
        // AUDIT LOGS
        // ==========================================
        case 'audit':
            requireRole($user_role, $admin_roles);
            $auditLogger = new AuditLogger($conn);
            $auditLogger->setContext($tenant_id, $user_id);

            switch ($action) {
                case 'list':
                    $filters = [
                        'user_id' => $_GET['user_id'] ?? null,
                        'action_type' => $_GET['action_type'] ?? null,
                        'action_category' => $_GET['action_category'] ?? null,
                        'entity_type' => $_GET['entity_type'] ?? null,
                        'date_from' => $_GET['date_from'] ?? null,
                        'date_to' => $_GET['date_to'] ?? null
                    ];
                    $page = (int) ($_GET['page'] ?? 1);
                    $per_page = (int) ($_GET['per_page'] ?? 50);
                    $logs = $auditLogger->getAuditLog(array_filter($filters), $page, $per_page);
                    respond(['success' => true, 'data' => $logs]);
                    break;

                case 'entity-history':
                    $entity_type = $_GET['entity_type'] ?? '';
                    $entity_id = $_GET['entity_id'] ?? '';
                    if (!$entity_type || !$entity_id) {
                        respond(['success' => false, 'message' => 'entity_type and entity_id required'], 400);
                    }
                    $history = $auditLogger->getEntityHistory($entity_type, $entity_id);
                    respond(['success' => true, 'data' => $history]);
                    break;

                case 'admin-activity':
                    // This would query admin_activity_log table
                    respond(['success' => true, 'data' => []]);
                    break;

                case 'data-access':
                    // This would query data_access_log table
                    respond(['success' => true, 'data' => []]);
                    break;

                default:
                    respond(['success' => false, 'message' => 'Invalid audit action'], 400);
            }
            break;

        // ==========================================
        // SECURITY
        // ==========================================
        case 'security':
            requireRole($user_role, $admin_roles);
            $securityLogger = new SecurityLogger($conn);
            $securityLogger->setTenant($tenant_id);

            switch ($action) {
                case 'alerts':
                    $severity = $_GET['severity'] ?? null;
                    $alerts = $securityLogger->getUnresolvedAlerts($severity);
                    respond(['success' => true, 'data' => $alerts]);
                    break;

                case 'resolve-alert':
                    if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required'], 405);
                    $data = getJsonInput();
                    $result = $securityLogger->resolveAlert($data['alert_id'], $user_id);
                    respond(['success' => $result]);
                    break;

                case 'login-attempts':
                    $email = $_GET['email'] ?? '';
                    if (!$email) {
                        respond(['success' => false, 'message' => 'email required'], 400);
                    }
                    $attempts = $securityLogger->getLoginAttempts($email);
                    respond(['success' => true, 'data' => $attempts]);
                    break;

                case 'user-login-history':
                    $target_user_id = (int) ($_GET['user_id'] ?? 0);
                    if (!$target_user_id) {
                        respond(['success' => false, 'message' => 'user_id required'], 400);
                    }
                    $history = $securityLogger->getUserLoginHistory($target_user_id);
                    respond(['success' => true, 'data' => $history]);
                    break;

                case 'password-history':
                    $target_user_id = (int) ($_GET['user_id'] ?? 0);
                    if (!$target_user_id) {
                        respond(['success' => false, 'message' => 'user_id required'], 400);
                    }
                    $history = $securityLogger->getPasswordHistory($target_user_id);
                    respond(['success' => true, 'data' => $history]);
                    break;

                default:
                    respond(['success' => false, 'message' => 'Invalid security action'], 400);
            }
            break;

        // ==========================================
        // ERRORS
        // ==========================================
        case 'errors':
            requireRole($user_role, $admin_roles);
            $errorLogger = new ErrorLogger($conn);
            $errorLogger->setTenant($tenant_id);

            switch ($action) {
                case 'list':
                    $filters = [
                        'error_type' => $_GET['error_type'] ?? null,
                        'is_resolved' => isset($_GET['is_resolved']) ? (bool) $_GET['is_resolved'] : null,
                        'date_from' => $_GET['date_from'] ?? null,
                        'date_to' => $_GET['date_to'] ?? null,
                        'search' => $_GET['search'] ?? null
                    ];
                    $page = (int) ($_GET['page'] ?? 1);
                    $per_page = (int) ($_GET['per_page'] ?? 50);
                    $errors = $errorLogger->getErrors(array_filter($filters, fn($v) => $v !== null), $page, $per_page);
                    respond(['success' => true, 'data' => $errors]);
                    break;

                case 'stats':
                    $days = (int) ($_GET['days'] ?? 30);
                    $stats = $errorLogger->getErrorStats($days);
                    respond(['success' => true, 'data' => $stats]);
                    break;

                case 'trends':
                    $days = (int) ($_GET['days'] ?? 30);
                    $trends = $errorLogger->getErrorTrends($days);
                    respond(['success' => true, 'data' => $trends]);
                    break;

                case 'resolve':
                    if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required'], 405);
                    $data = getJsonInput();
                    $result = $errorLogger->resolveError($data['error_id'], $user_id, $data['notes'] ?? null);
                    respond(['success' => $result]);
                    break;

                default:
                    respond(['success' => false, 'message' => 'Invalid errors action'], 400);
            }
            break;

        // ==========================================
        // GDPR / PRIVACY
        // ==========================================
        case 'gdpr':
            $gdprManager = new GDPRManager($conn);
            $gdprManager->setTenant($tenant_id);

            switch ($action) {
                // User-accessible endpoints
                case 'my-requests':
                    $requests = $gdprManager->getUserRequests($user_id);
                    respond(['success' => true, 'data' => $requests]);
                    break;

                case 'create-request':
                    if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required'], 405);
                    $data = getJsonInput();
                    $result = $gdprManager->createRequest($user_id, $data['request_type'], $data['description'] ?? null);
                    respond($result);
                    break;

                case 'my-consent':
                    $consent = $gdprManager->getUserConsent($user_id);
                    respond(['success' => true, 'data' => $consent]);
                    break;

                case 'update-consent':
                    if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required'], 405);
                    $data = getJsonInput();
                    $result = $gdprManager->updateConsent($user_id, $data);
                    respond(['success' => $result]);
                    break;

                case 'export-my-data':
                    $data = $gdprManager->exportUserData($user_id);
                    respond(['success' => true, 'data' => $data]);
                    break;

                // Admin endpoints
                case 'all-requests':
                    requireRole($user_role, $hr_roles);
                    $status = $_GET['status'] ?? null;
                    $requests = $gdprManager->getAllRequests($status);
                    respond(['success' => true, 'data' => $requests]);
                    break;

                case 'update-request':
                    requireRole($user_role, $hr_roles);
                    if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required'], 405);
                    $data = getJsonInput();
                    $result = $gdprManager->updateRequestStatus(
                        $data['request_id'],
                        $data['status'],
                        $user_id,
                        $data['notes'] ?? null
                    );
                    respond(['success' => $result]);
                    break;

                case 'consent-stats':
                    requireRole($user_role, $hr_roles);
                    $stats = $gdprManager->getConsentStats();
                    respond(['success' => true, 'data' => $stats]);
                    break;

                case 'retention-stats':
                    requireRole($user_role, $admin_roles);
                    $stats = $gdprManager->getRetentionStats();
                    respond(['success' => true, 'data' => $stats]);
                    break;

                case 'export-user-data':
                    requireRole($user_role, $hr_roles);
                    $target_user_id = (int) $_GET['user_id'];
                    $data = $gdprManager->exportUserData($target_user_id);
                    respond(['success' => true, 'data' => $data]);
                    break;

                case 'anonymize-user':
                    requireRole($user_role, $admin_roles);
                    if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required'], 405);
                    $data = getJsonInput();
                    $result = $gdprManager->anonymizeUser($data['user_id'], $user_id);
                    respond($result);
                    break;

                default:
                    respond(['success' => false, 'message' => 'Invalid GDPR action'], 400);
            }
            break;

        // ==========================================
        // CERTIFICATES
        // ==========================================
        case 'certificate':
            $certLogger = new CertificateLogger($conn);
            $certLogger->setTenant($tenant_id);

            switch ($action) {
                // Public verification endpoint
                case 'verify':
                    $data = $method === 'POST' ? getJsonInput() : $_GET;
                    $result = $certLogger->verifyCertificate(
                        $data['certificate_number'],
                        $data['verified_by_name'] ?? null,
                        $data['verified_by_email'] ?? null,
                        $data['verified_by_company'] ?? null
                    );
                    respond($result);
                    break;

                // Admin endpoints
                case 'verification-history':
                    requireRole($user_role, $hr_roles);
                    $cert_number = $_GET['certificate_number'] ?? '';
                    if (!$cert_number) {
                        respond(['success' => false, 'message' => 'certificate_number required'], 400);
                    }
                    $history = $certLogger->getCertificateVerificationHistory($cert_number);
                    respond(['success' => true, 'data' => $history]);
                    break;

                case 'recent-verifications':
                    requireRole($user_role, $hr_roles);
                    $days = (int) ($_GET['days'] ?? 30);
                    $verifications = $certLogger->getRecentVerifications($days);
                    respond(['success' => true, 'data' => $verifications]);
                    break;

                case 'verification-stats':
                    requireRole($user_role, $hr_roles);
                    $days = (int) ($_GET['days'] ?? 30);
                    $stats = $certLogger->getVerificationStats($days);
                    respond(['success' => true, 'data' => $stats]);
                    break;

                case 'log-reprint':
                    requireRole($user_role, $hr_roles);
                    if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required'], 405);
                    $data = getJsonInput();
                    $result = $certLogger->logReprint($data['certificate_id'], $user_id, $data['reason'] ?? null);
                    respond($result);
                    break;

                case 'reprint-history':
                    requireRole($user_role, $hr_roles);
                    $cert_id = (int) $_GET['certificate_id'];
                    if (!$cert_id) {
                        respond(['success' => false, 'message' => 'certificate_id required'], 400);
                    }
                    $history = $certLogger->getCertificateReprintHistory($cert_id);
                    respond(['success' => true, 'data' => $history]);
                    break;

                case 'all-reprints':
                    requireRole($user_role, $hr_roles);
                    $reprints = $certLogger->getAllReprints($_GET['date_from'] ?? null, $_GET['date_to'] ?? null);
                    respond(['success' => true, 'data' => $reprints]);
                    break;

                case 'top-verifiers':
                    requireRole($user_role, $admin_roles);
                    $limit = (int) ($_GET['limit'] ?? 10);
                    $verifiers = $certLogger->getTopVerifiers($limit);
                    respond(['success' => true, 'data' => $verifiers]);
                    break;

                default:
                    respond(['success' => false, 'message' => 'Invalid certificate action'], 400);
            }
            break;

        default:
            respond(['success' => false, 'message' => 'Invalid resource'], 400);
    }
} catch (Exception $e) {
    error_log('Admin API Error: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()], 500);
}

// ==========================================
// HELPER FUNCTIONS
// ==========================================

function respond(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function getJsonInput(): array {
    $input = file_get_contents('php://input');
    return json_decode($input, true) ?? [];
}

function requireRole(string $current_role, array $allowed_roles): void {
    if (!in_array($current_role, $allowed_roles)) {
        respond(['success' => false, 'message' => 'Access denied'], 403);
    }
}
