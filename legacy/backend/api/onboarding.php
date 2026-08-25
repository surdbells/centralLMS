<?php
/**
 * PUBLIC Tenant Onboarding API
 *
 * This endpoint does NOT require authentication — it is the self-service
 * signup entry point for new tenants.
 *
 * Pattern: POST /api/onboarding.php
 * Body: { action: "<action>", ...params }
 *
 * Actions:
 *   validate-code      — Validate an onboarding code before proceeding
 *   plans              — List available subscription plans
 *   content-packages   — List available content packages
 *   check-subdomain    — Check subdomain availability
 *   check-email        — Check if email is already registered
 *   provision          — Full tenant provisioning (creates everything)
 */

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../class/tenants/TenantProvisioningService.php';

// ── CORS for public endpoint ──
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$db   = new Database();
$conn = $db->getConnection();
$svc  = new TenantProvisioningService($conn);

// ── Parse input ──
$method = $_SERVER['REQUEST_METHOD'];
$input  = [];

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?? [];
}

$action = $input['action'] ?? $_GET['action'] ?? '';

// ── Rate limiting (basic — per IP, per hour) ──
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateKey  = 'onboarding_' . md5($clientIp);

// In production, use Redis/Memcached for rate limiting.
// For now, use a simple DB check on provisioning_log.
// The provision action is the most expensive, so we rate-limit it.

// ═══════════════════════════════════════════
//  ACTION ROUTER
// ═══════════════════════════════════════════
switch ($action) {

    // ──────────── Validate Onboarding Code ────────────
    case 'validate-code':
        $code = trim($input['code'] ?? $_GET['code'] ?? '');
        $result = $svc->validateOnboardingCode($code);
        $db->send_response(200, $result['valid'] ? 'success' : 'error', $result,
            $result['valid'] ? 'Code is valid' : ($result['error'] ?? 'Invalid code'));
        break;

    // ──────────── List Plans ────────────
    case 'plans':
        $plans = $svc->getPlans();
        $db->send_response(200, 'success', $plans, 'Plans retrieved');
        break;

    // ──────────── List Content Packages ────────────
    case 'content-packages':
        $packages = $svc->getContentPackages();
        $db->send_response(200, 'success', $packages, 'Content packages retrieved');
        break;

    // ──────────── Check Subdomain ────────────
    case 'check-subdomain':
        $subdomain = trim($input['subdomain'] ?? $_GET['subdomain'] ?? '');
        if (!$subdomain) {
            $db->send_response(400, 'error', null, 'Subdomain is required');
            break;
        }
        $result = $svc->checkSubdomain($subdomain);
        $db->send_response(200, $result['available'] ? 'success' : 'error', $result,
            $result['available'] ? 'Subdomain is available' : ($result['error'] ?? 'Subdomain taken'));
        break;

    // ──────────── Check Email ────────────
    case 'check-email':
        $email = trim($input['email'] ?? $_GET['email'] ?? '');
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $db->send_response(400, 'error', ['available' => false], 'Valid email is required');
            break;
        }
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $db->send_response(200, $exists ? 'error' : 'success',
            ['available' => !$exists, 'email' => $email],
            $exists ? 'Email is already registered' : 'Email is available');
        break;

    // ──────────── Full Provisioning ────────────
    case 'provision':
        // Rate limit: max 5 provisions per IP per hour
        $stmt = $conn->prepare(
            "SELECT COUNT(*) as cnt FROM tenant_provisioning_log
             WHERE step = 'provisioning_complete' AND details LIKE ?
             AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $ipPattern = '%' . $clientIp . '%';
        $stmt->bind_param('s', $ipPattern);
        $stmt->execute();
        $rateCheck = $stmt->get_result()->fetch_assoc();
        if (($rateCheck['cnt'] ?? 0) >= 5) {
            $db->send_response(429, 'error', null, 'Too many provisioning requests. Please try again later.');
            break;
        }

        $result = $svc->provision($input);

        if ($result['success']) {
            $db->send_response(201, 'success', [
                'tenant_id'    => $result['tenant_id'],
                'subdomain'    => $result['subdomain'],
                'domain'       => $result['domain'],
                'admin_email'  => $result['admin_email'],
                'tenant_code'  => $result['tenant_code'],
            ], 'Tenant provisioned successfully');
        } else {
            $db->send_response(400, 'error', null, $result['error'] ?? 'Provisioning failed');
        }
        break;

    // ──────────── Default ────────────
    default:
        $db->send_response(400, 'error', null, "Unknown action: $action");
        break;
}