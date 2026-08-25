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

    // Check if there's anything to update
    if (!isset($data->title) && !isset($data->description)) {
        http_response_code(400);
        $database->send_response(400, "failed", [], "No fields to update. Provide title or description");
        exit;
    }

    $media->tenant_id = intval($data->tenant_id);
    $media->media_id = intval($data->media_id);
    $media->title = $data->title ?? null;
    $media->description = $data->description ?? null;

    $result = $media->update();

    if ($result['success']) {
        http_response_code(200);
        $database->send_response(200, "success", ["media" => $result['data']], "Media updated successfully");
    } else {
        http_response_code(400);
        $database->send_response(400, "failed", [], $result['error']);
    }

} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request. Token, user_id, and tenant_id are required");
}