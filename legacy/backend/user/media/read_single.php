<?php
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

    if (empty($data->media_id)) {
        http_response_code(400);
        $database->send_response(400, "failed", [], "media_id is required");
        exit;
    }

    $media->tenant_id = intval($data->tenant_id);
    $media->media_id = intval($data->media_id);

    $result = $media->read_single();

    if ($result) {
        // Format metadata if exists
        if (!empty($result['metadata_json'])) {
            $result['metadata'] = json_decode($result['metadata_json'], true);
        }

        http_response_code(200);
        $database->send_response(200, "success", ["media" => $result], "Media retrieved successfully");
    } else {
        http_response_code(404);
        $database->send_response(404, "failed", [], "Media not found");
    }

} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request. Token, user_id, and tenant_id are required");
}