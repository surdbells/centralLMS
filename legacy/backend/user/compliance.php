<?php

/**
 * Admin Compliance API Endpoint
 * Handles audit logs, security events, errors, GDPR, and certificates
 * Uses existing Database class pattern
 */

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../class/AuditLogger.php';
require_once __DIR__ . '/../class/SecurityLogger.php';
require_once __DIR__ . '/../class/ErrorLogger.php';
require_once __DIR__ . '/../class/GDPRManager.php';
require_once __DIR__ . '/../class/CertificateLogger.php';

$db = new Database();
$conn = $db->getConnection();

// Authenticate
$auth = authenticate();
if (!$auth['success']) {
    $db->send_response(401, 'error', null, $auth['message']);
    exit;
}

$user = $auth['user'];
$tenant_id = $user['tenant_id'];
$user_id = $user['id'];
$user_role = $user['role'];

// Role arrays
$admin_roles = ['admin', 'superadmin'];
$hr_roles = ['admin', 'superadmin', 'hr'];

// Get request parameters
$resource = $_GET['resource'] ?? $_POST['resource'] ?? '';
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = getJsonInput();

try {
    switch ($resource) {
        // ==========================================
        // AUDIT LOGS
        // ==========================================
        case 'audit':
            if (!hasRole($user, $admin_roles)) {
                $db->send_response(403, 'error', null, 'Admin access required');
                exit;
            }

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
                    $db->send_response(200, 'success', $logs, 'Audit logs retrieved');
                    break;

                case 'entity-history':
                    $entity_type = $_GET['entity_type'] ?? '';
                    $entity_id = $_GET['entity_id'] ?? '';
                    if (!$entity_type || !$entity_id) {
                        $db->send_response(400, 'error', null, 'entity_type and entity_id required');
                        exit;
                    }
                    $history = $auditLogger->getEntityHistory($entity_type, $entity_id);
                    $db->send_response(200, 'success', $history, 'Entity history retrieved');
                    break;

                default:
                    $db->send_response(400, 'error', null, 'Invalid audit action');
            }
            break;

        // ==========================================
        // SECURITY
        // ==========================================
        case 'security':
            if (!hasRole($user, $admin_roles)) {
                $db->send_response(403, 'error', null, 'Admin access required');
                exit;
            }

            $securityLogger = new SecurityLogger($conn);
            $securityLogger->setTenant($tenant_id);

            switch ($action) {
                case 'alerts':
                    $severity = $_GET['severity'] ?? null;
                    $alerts = $securityLogger->getUnresolvedAlerts($severity);
                    $db->send_response(200, 'success', $alerts, 'Alerts retrieved');
                    break;

                case 'resolve-alert':
                    $result = $securityLogger->resolveAlert($input['alert_id'], $user_id);
                    $db->send_response(200, $result ? 'success' : 'error', null,
                        $result ? 'Alert resolved' : 'Failed to resolve alert');
                    break;

                case 'login-attempts':
                    $email = $_GET['email'] ?? '';
                    if (!$email) {
                        $db->send_response(400, 'error', null, 'email required');
                        exit;
                    }
                    $attempts = $securityLogger->getLoginAttempts($email);
                    $db->send_response(200, 'success', $attempts, 'Login attempts retrieved');
                    break;

                case 'user-login-history':
                    $target_user_id = (int) ($_GET['user_id'] ?? 0);
                    if (!$target_user_id) {
                        $db->send_response(400, 'error', null, 'user_id required');
                        exit;
                    }
                    $history = $securityLogger->getUserLoginHistory($target_user_id);
                    $db->send_response(200, 'success', $history, 'Login history retrieved');
                    break;

                case 'password-history':
                    $target_user_id = (int) ($_GET['user_id'] ?? 0);
                    if (!$target_user_id) {
                        $db->send_response(400, 'error', null, 'user_id required');
                        exit;
                    }
                    $history = $securityLogger->getPasswordHistory($target_user_id);
                    $db->send_response(200, 'success', $history, 'Password history retrieved');
                    break;

                default:
                    $db->send_response(400, 'error', null, 'Invalid security action');
            }
            break;

        // ==========================================
        // ERRORS
        // ==========================================
        case 'errors':
            if (!hasRole($user, $admin_roles)) {
                $db->send_response(403, 'error', null, 'Admin access required');
                exit;
            }

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
                    $db->send_response(200, 'success', $errors, 'Errors retrieved');
                    break;

                case 'stats':
                    $days = (int) ($_GET['days'] ?? 30);
                    $stats = $errorLogger->getErrorStats($days);
                    $db->send_response(200, 'success', $stats, 'Error stats retrieved');
                    break;

                case 'trends':
                    $days = (int) ($_GET['days'] ?? 30);
                    $trends = $errorLogger->getErrorTrends($days);
                    $db->send_response(200, 'success', $trends, 'Error trends retrieved');
                    break;

                case 'resolve':
                    $result = $errorLogger->resolveError($input['error_id'], $user_id, $input['notes'] ?? null);
                    $db->send_response(200, $result ? 'success' : 'error', null,
                        $result ? 'Error resolved' : 'Failed to resolve error');
                    break;

                default:
                    $db->send_response(400, 'error', null, 'Invalid errors action');
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
                    $db->send_response(200, 'success', $requests, 'Requests retrieved');
                    break;

                case 'create-request':
                    $result = $gdprManager->createRequest($user_id, $input['request_type'], $input['description'] ?? null);
                    $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                        $result['success'] ? 'Request created' : $result['message']);
                    break;

                case 'my-consent':
                    $consent = $gdprManager->getUserConsent($user_id);
                    $db->send_response(200, 'success', $consent, 'Consent retrieved');
                    break;

                case 'update-consent':
                    $result = $gdprManager->updateConsent($user_id, $input);
                    $db->send_response(200, $result ? 'success' : 'error', null,
                        $result ? 'Consent updated' : 'Failed to update consent');
                    break;

                case 'export-my-data':
                    $data = $gdprManager->exportUserData($user_id);
                    $db->send_response(200, 'success', $data, 'Data exported');
                    break;

                // Admin endpoints
                case 'all-requests':
                    if (!hasRole($user, $hr_roles)) {
                        $db->send_response(403, 'error', null, 'HR access required');
                        exit;
                    }
                    $status = $_GET['status'] ?? null;
                    $requests = $gdprManager->getAllRequests($status);
                    $db->send_response(200, 'success', $requests, 'All requests retrieved');
                    break;

                case 'update-request':
                    if (!hasRole($user, $hr_roles)) {
                        $db->send_response(403, 'error', null, 'HR access required');
                        exit;
                    }
                    $result = $gdprManager->updateRequestStatus(
                        $input['request_id'],
                        $input['status'],
                        $user_id,
                        $input['notes'] ?? null
                    );
                    $db->send_response(200, $result ? 'success' : 'error', null,
                        $result ? 'Request updated' : 'Failed to update request');
                    break;

                case 'consent-stats':
                    if (!hasRole($user, $hr_roles)) {
                        $db->send_response(403, 'error', null, 'HR access required');
                        exit;
                    }
                    $stats = $gdprManager->getConsentStats();
                    $db->send_response(200, 'success', $stats, 'Consent stats retrieved');
                    break;

                case 'retention-stats':
                    if (!hasRole($user, $admin_roles)) {
                        $db->send_response(403, 'error', null, 'Admin access required');
                        exit;
                    }
                    $stats = $gdprManager->getRetentionStats();
                    $db->send_response(200, 'success', $stats, 'Retention stats retrieved');
                    break;

                case 'export-user-data':
                    if (!hasRole($user, $hr_roles)) {
                        $db->send_response(403, 'error', null, 'HR access required');
                        exit;
                    }
                    $target_user_id = (int) $_GET['user_id'];
                    $data = $gdprManager->exportUserData($target_user_id);
                    $db->send_response(200, 'success', $data, 'User data exported');
                    break;

                case 'anonymize-user':
                    if (!hasRole($user, $admin_roles)) {
                        $db->send_response(403, 'error', null, 'Admin access required');
                        exit;
                    }
                    $result = $gdprManager->anonymizeUser($input['user_id'], $user_id);
                    $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                        $result['success'] ? 'User anonymized' : $result['message']);
                    break;

                default:
                    $db->send_response(400, 'error', null, 'Invalid GDPR action');
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
                    $cert_number = $input['certificate_number'] ?? $_GET['certificate_number'] ?? '';
                    $result = $certLogger->verifyCertificate(
                        $cert_number,
                        $input['verified_by_name'] ?? $_GET['verified_by_name'] ?? null,
                        $input['verified_by_email'] ?? $_GET['verified_by_email'] ?? null,
                        $input['verified_by_company'] ?? $_GET['verified_by_company'] ?? null
                    );
                    $db->send_response(200, $result['valid'] ? 'success' : 'error', $result,
                        $result['valid'] ? 'Certificate valid' : $result['message']);
                    break;

                // Admin endpoints
                case 'verification-history':
                    if (!hasRole($user, $hr_roles)) {
                        $db->send_response(403, 'error', null, 'HR access required');
                        exit;
                    }
                    $cert_number = $_GET['certificate_number'] ?? '';
                    if (!$cert_number) {
                        $db->send_response(400, 'error', null, 'certificate_number required');
                        exit;
                    }
                    $history = $certLogger->getCertificateVerificationHistory($cert_number);
                    $db->send_response(200, 'success', $history, 'Verification history retrieved');
                    break;

                case 'recent-verifications':
                    if (!hasRole($user, $hr_roles)) {
                        $db->send_response(403, 'error', null, 'HR access required');
                        exit;
                    }
                    $days = (int) ($_GET['days'] ?? 30);
                    $verifications = $certLogger->getRecentVerifications($days);
                    $db->send_response(200, 'success', $verifications, 'Recent verifications retrieved');
                    break;

                case 'verification-stats':
                    if (!hasRole($user, $hr_roles)) {
                        $db->send_response(403, 'error', null, 'HR access required');
                        exit;
                    }
                    $days = (int) ($_GET['days'] ?? 30);
                    $stats = $certLogger->getVerificationStats($days);
                    $db->send_response(200, 'success', $stats, 'Verification stats retrieved');
                    break;

                case 'log-reprint':
                    if (!hasRole($user, $hr_roles)) {
                        $db->send_response(403, 'error', null, 'HR access required');
                        exit;
                    }
                    $result = $certLogger->logReprint($input['certificate_id'], $user_id, $input['reason'] ?? null);
                    $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                        $result['success'] ? 'Reprint logged' : $result['message']);
                    break;

                case 'reprint-history':
                    if (!hasRole($user, $hr_roles)) {
                        $db->send_response(403, 'error', null, 'HR access required');
                        exit;
                    }
                    $cert_id = (int) $_GET['certificate_id'];
                    if (!$cert_id) {
                        $db->send_response(400, 'error', null, 'certificate_id required');
                        exit;
                    }
                    $history = $certLogger->getCertificateReprintHistory($cert_id);
                    $db->send_response(200, 'success', $history, 'Reprint history retrieved');
                    break;

                case 'all-reprints':
                    if (!hasRole($user, $hr_roles)) {
                        $db->send_response(403, 'error', null, 'HR access required');
                        exit;
                    }
                    $reprints = $certLogger->getAllReprints($_GET['date_from'] ?? null, $_GET['date_to'] ?? null);
                    $db->send_response(200, 'success', $reprints, 'All reprints retrieved');
                    break;

                case 'top-verifiers':
                    if (!hasRole($user, $admin_roles)) {
                        $db->send_response(403, 'error', null, 'Admin access required');
                        exit;
                    }
                    $limit = (int) ($_GET['limit'] ?? 10);
                    $verifiers = $certLogger->getTopVerifiers($limit);
                    $db->send_response(200, 'success', $verifiers, 'Top verifiers retrieved');
                    break;

                default:
                    $db->send_response(400, 'error', null, 'Invalid certificate action');
            }
            break;

        default:
            $db->send_response(400, 'error', null, 'Invalid resource. Use: audit, security, errors, gdpr, certificate');
    }
} catch (Exception $e) {
    error_log('Admin API Error: ' . $e->getMessage());
    $db->send_response(500, 'error', null, 'Server error: ' . $e->getMessage());
}