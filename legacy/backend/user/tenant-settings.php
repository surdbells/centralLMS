<?php

/**
 * Tenant Settings API Endpoint
 * Admin endpoint for managing tenant configuration
 *
 * All requests are POST with JSON body containing auth + action + params
 *
 * Actions:
 * - get-all              - Get all settings
 * - get-category         - Get settings for a category
 * - get-setting          - Get a single setting
 * - update-category      - Update settings for a category
 * - update-setting       - Update a single setting
 * - reset-category       - Reset category to defaults
 * - get-tenant-info      - Get tenant basic info
 * - update-tenant-info   - Update tenant basic info
 * - get-usage-stats      - Get tenant usage statistics
 * - get-history          - Get settings change history
 * - export               - Export all settings
 * - import               - Import settings
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Token, X-User-ID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../class/TenantSettings.php';
require_once __DIR__ . '/../class/User.php';

$input = json_decode(file_get_contents('php://input'), true);
// Validate required fields
$required = ['token', 'user_id', 'tenant_id'];
foreach ($required as $field) {
    if (!isset($input[$field])) {
        echo json_encode([
            'code' => 400,
            'status' => 'error',
            'message' => "Missing required field: {$field}"
        ]);
        exit();
    }
}
$db = new Database();
$conn = $db->getConnection();
$user = new User($conn);

$token = $input['token'];
$userId = (int) $input['user_id'];
$tenant_id = (int) $input['tenant_id'];
$token_check = $db->verify_user($token, $userId);
if ($token_check != 100 || !$user->is_admin($userId)) {
    http_response_code(401);
    $db->send_response(401, "failed", [], "Unauthorized request");
    exit;
}
// Initialize settings manager
$settingsManager = new TenantSettings($conn);
$settingsManager->setTenant($tenant_id);

// Get action from input
$action = $input['action'] ?? '';

try {
    switch ($action) {
        // ==========================================
        // GET SETTINGS
        // ==========================================

        case 'get-all':
            $settings = $settingsManager->getAllSettings();
            $db->send_response(200, 'success', $settings, 'Settings retrieved');
            break;

        case 'get-category':
            $category = $input['category'] ?? '';
            if (empty($category)) {
                $db->send_response(400, 'error', null, 'category is required');
                exit;
            }

            $settings = $settingsManager->getCategorySettings($category);
            if (empty($settings)) {
                $db->send_response(404, 'error', null, 'Category not found');
                exit;
            }

            $db->send_response(200, 'success', $settings, 'Category settings retrieved');
            break;

        case 'get-setting':
            $category = $input['category'] ?? '';
            $key = $input['key'] ?? '';

            if (empty($category) || empty($key)) {
                $db->send_response(400, 'error', null, 'category and key are required');
                exit;
            }

            $value = $settingsManager->getSetting($category, $key);
            $db->send_response(200, 'success', ['value' => $value], 'Setting retrieved');
            break;

        // ==========================================
        // UPDATE SETTINGS
        // ==========================================

        case 'update-category':
            $category = $input['category'] ?? '';
            $settings = $input['settings'] ?? [];

            if (empty($category)) {
                $db->send_response(400, 'error', null, 'category is required');
                exit;
            }

            if (empty($settings) || !is_array($settings)) {
                $db->send_response(400, 'error', null, 'settings object is required');
                exit;
            }

            $result = $settingsManager->updateCategorySettings($category, $settings, $userId);
            $db->send_response(200, $result['success'] ? 'success' : 'error', $result, $result['message']);
            break;

        case 'update-setting':
            $category = $input['category'] ?? '';
            $key = $input['key'] ?? '';
            $value = $input['value'] ?? null;

            if (empty($category) || empty($key)) {
                $db->send_response(400, 'error', null, 'category and key are required');
                exit;
            }

            $result = $settingsManager->updateSetting($category, $key, $value, $userId);
            $db->send_response(200, $result['success'] ? 'success' : 'error', $result, $result['message']);
            break;

        case 'reset-category':
            $category = $input['category'] ?? '';

            if (empty($category)) {
                $db->send_response(400, 'error', null, 'category is required');
                exit;
            }

            $result = $settingsManager->resetCategoryToDefaults($category, $userId);
            $db->send_response(200, $result['success'] ? 'success' : 'error', $result, $result['message']);
            break;

        // ==========================================
        // TENANT INFO
        // ==========================================

        case 'get-tenant-info':
            $tenantInfo = $settingsManager->getTenantInfo();
            if (!$tenantInfo) {
                $db->send_response(404, 'error', null, 'Tenant not found');
                exit;
            }
            $db->send_response(200, 'success', $tenantInfo, 'Tenant info retrieved');
            break;

        case 'update-tenant-info':
            $data = $input['data'] ?? [];

            if (empty($data)) {
                $db->send_response(400, 'error', null, 'data object is required');
                exit;
            }

            $result = $settingsManager->updateTenantInfo($data, $userId);
            $db->send_response(200, $result['success'] ? 'success' : 'error', $result, $result['message']);
            break;

        // ==========================================
        // STATISTICS
        // ==========================================

        case 'get-usage-stats':
            $stats = $settingsManager->getUsageStats();
            $db->send_response(200, 'success', $stats, 'Usage stats retrieved');
            break;

        // ==========================================
        // HISTORY
        // ==========================================

        case 'get-history':
            $limit = (int) ($input['limit'] ?? 50);
            $history = $settingsManager->getSettingsHistory($limit);
            $db->send_response(200, 'success', $history, 'Settings history retrieved');
            break;

        // ==========================================
        // EXPORT/IMPORT
        // ==========================================

        case 'export':
            $export = $settingsManager->exportSettings();
            $db->send_response(200, 'success', $export, 'Settings exported');
            break;

        case 'import':
            $settings = $input['settings'] ?? [];

            if (empty($settings)) {
                $db->send_response(400, 'error', null, 'settings object is required');
                exit;
            }

            $result = $settingsManager->importSettings($settings, $userId);
            $db->send_response(200, $result['success'] ? 'success' : 'error', $result, $result['message']);
            break;

        default:
            $db->send_response(400, 'error', null,
                'Invalid action. Available: get-all, get-category, get-setting, ' .
                'update-category, update-setting, reset-category, get-tenant-info, ' .
                'update-tenant-info, get-usage-stats, get-history, export, import');
    }

} catch (Exception $e) {
    error_log('Tenant Settings API Error: ' . $e->getMessage());
    $db->send_response(500, 'error', null, 'Server error: ' . $e->getMessage());
}