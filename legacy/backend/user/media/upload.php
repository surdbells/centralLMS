<?php
include_once '../../config/Database.php';
include_once '../../class/Media.php';
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
$token = $_POST['token'] ?? null;
$user_id = $_POST['user_id'] ?? null;
$tenant_id = $_POST['tenant_id'] ?? null;
if (!empty($token) && !empty($user_id) && !empty($tenant_id)) {
    // Verify user token
    $token_check = $database->verify_user($token, $user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }
    if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
        http_response_code(400);
        $database->send_response(400, "failed", [], "No file provided. Use form field 'file'");
        exit;
    }
    $media->tenant_id = intval($tenant_id);
    $media->uploaded_by = intval($user_id);
    $media->title = $_POST['title'] ?? null;
    $media->description = $_POST['description'] ?? null;
    $media->parent_media_id = isset($_POST['parent_media_id']) ? intval($_POST['parent_media_id']) : null;
    $media->version_label = $_POST['version_label'] ?? null;
    $media->version_notes = $_POST['version_notes'] ?? null;
    $result = $media->upload($_FILES['file']);
    if ($result['success']) {
        http_response_code(201);
        $database->send_response(201, "success", ["media" => $result['data']], "Media uploaded successfully");
    } else {
        http_response_code(400);
        $database->send_response(400, "failed", [], $result['error']);
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request. Token, user_id, and tenant_id are required");
}