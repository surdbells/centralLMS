<?php
/**
 * Tenant Storage API Endpoint
 * Get storage usage for a tenant
 *
 * POST /user/media/storage.php
 *
 * JSON Body:
 *   - token: Authentication token (required)
 *   - user_id: User ID (required)
 *   - tenant_id: Tenant ID (required)
 *   - action: "get" | "recalculate" (optional, default: get)
 */

include_once '../../config/Database.php';
include_once '../../class/Media.php';

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'code' => 405,
        'status' => 'failed',
        'data' => [],
        'message' => 'Method not allowed'
    ]);
    exit;
}

$database = new Database();
$db = $database->getConnection();
$media = new Media($db);

$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }

    $media->tenant_id = intval($data->tenant_id);
    $action = $data->action ?? 'get';

    switch ($action) {
        case 'recalculate':
            // Recalculate storage from media table
            $result = $media->recalculateStorage();
            if ($result['success']) {
                http_response_code(200);
                $database->send_response(200, "success", ["storage" => $result['data']], "Storage recalculated successfully");
            } else {
                http_response_code(500);
                $database->send_response(500, "failed", [], $result['error']);
            }
            break;

        case 'get':
        default:
            $result = $media->getStorageUsage();
            if ($result) {
                http_response_code(200);
                $database->send_response(200, "success", ["storage" => $result], "Storage usage retrieved successfully");
            } else {
                // Return default if no storage record exists
                http_response_code(200);
                $database->send_response(200, "success", [
                    "storage" => [
                        "tenant_id" => $media->tenant_id,
                        "storage_limit" => 0,
                        "storage_limit_formatted" => "Unlimited",
                        "total_used" => 0,
                        "total_used_formatted" => "0 B",
                        "total_files" => 0,
                        "usage_percentage" => 0,
                        "breakdown" => [
                            "video" => ["used" => 0, "used_formatted" => "0 B", "count" => 0],
                            "audio" => ["used" => 0, "used_formatted" => "0 B", "count" => 0],
                            "pdf" => ["used" => 0, "used_formatted" => "0 B", "count" => 0],
                            "scorm" => ["used" => 0, "used_formatted" => "0 B", "count" => 0]
                        ]
                    ]
                ], "Storage usage retrieved successfully");
            }
            break;
    }

} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request. Token, user_id, and tenant_id are required");
}