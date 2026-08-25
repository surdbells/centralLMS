<?php

/**
 * Video Integration OAuth Callback Handler
 * 
 * Handles OAuth callbacks from Zoom, Microsoft Teams, and Google Meet
 * 
 * @package LMS
 * @version 1.0.0
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../class/VideoIntegrations.php';

use LMS\VideoConferencing\VideoProviderFactory;

$db = new Database();
$conn = $db->getConnection();

try {
    // Get platform from URL path: /api/integrations/{platform}/callback
    $path = $_SERVER['REQUEST_URI'];
    preg_match('/\/integrations\/(\w+)\/callback/', $path, $matches);
    $platform = $matches[1] ?? $_GET['platform'] ?? '';
    
    if (!$platform) {
        throw new Exception('Platform not specified');
    }
    
    // Normalize platform name
    $platform_map = [
        'zoom' => 'zoom',
        'teams' => 'microsoft_teams',
        'microsoft' => 'microsoft_teams',
        'google' => 'google_meet',
        'meet' => 'google_meet'
    ];
    $platform = $platform_map[$platform] ?? $platform;
    
    // Get authorization code from callback
    $code = $_GET['code'] ?? '';
    $state = $_GET['state'] ?? '';
    $error = $_GET['error'] ?? '';
    
    if ($error) {
        throw new Exception("OAuth error: " . ($error['error_description'] ?? $error));
    }
    
    if (!$code) {
        throw new Exception('Authorization code not provided');
    }
    
    // Decode state to get tenant_id and user_id
    $state_data = json_decode(base64_decode($state), true);
    if (!$state_data || !isset($state_data['tenant_id']) || !isset($state_data['user_id'])) {
        throw new Exception('Invalid state parameter');
    }
    
    $tenant_id = (int) $state_data['tenant_id'];
    $user_id = (int) $state_data['user_id'];
    
    // Get existing integration config for client credentials
    $stmt = $conn->prepare("SELECT * FROM tenant_video_integrations WHERE tenant_id = ? AND platform = ?");
    $stmt->bind_param("is", $tenant_id, $platform);
    $stmt->execute();
    $integration = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$integration) {
        throw new Exception('Integration not found. Please configure client credentials first.');
    }
    
    $settings = json_decode($integration['settings'], true) ?? [];
    
    // Create provider and exchange code for tokens
    $provider = VideoProviderFactory::create($platform, [
        'client_id' => $integration['client_id'],
        'client_secret' => $integration['client_secret'],
        'tenant_id' => $settings['tenant_id'] ?? null
    ]);
    
    $tokens = $provider->authenticate($code);
    
    // Update integration with tokens
    $sql = "UPDATE tenant_video_integrations SET 
                access_token = ?,
                refresh_token = ?,
                token_expires_at = ?,
                is_enabled = 1,
                connected_by = ?,
                connected_at = NOW(),
                last_refreshed_at = NOW()
            WHERE integration_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "sssii",
        $tokens['access_token'],
        $tokens['refresh_token'],
        $tokens['token_expires_at'],
        $user_id,
        $integration['integration_id']
    );
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to save tokens');
    }
    $stmt->close();
    
    // Log the connection
    $log_sql = "INSERT INTO activity_logs (tenant_id, user_id, action, entity_type, entity_id, details, created_at)
                VALUES (?, ?, 'video_integration_connected', 'integration', ?, ?, NOW())";
    $log_details = json_encode(['platform' => $platform]);
    $stmt = $conn->prepare($log_sql);
    $stmt->bind_param("iiss", $tenant_id, $user_id, $integration['integration_id'], $log_details);
    $stmt->execute();
    $stmt->close();
    
    // Redirect back to settings page with success message
    $redirect_url = getenv('APP_URL') . '/settings/integrations?status=success&platform=' . urlencode($platform);
    header("Location: $redirect_url");
    exit;
    
} catch (Exception $e) {
    error_log('OAuth Callback Error: ' . $e->getMessage());
    
    // Redirect with error
    $redirect_url = getenv('APP_URL') . '/settings/integrations?status=error&message=' . urlencode($e->getMessage());
    header("Location: $redirect_url");
    exit;
}
