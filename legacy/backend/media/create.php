<?php
include_once '../config/Database.php';
include_once '../class/Media.php';
include_once '../class/User.php';

$database = new Database();
$db = $database->getConnection();
$media = new Media($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id) && !empty($data->title) && !empty($data->media_type) && !empty($data->file_name) && !empty($data->file_path)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized access.");
        exit;
    }

    $media->tenant_id = intval($data->tenant_id);
    $media->uploaded_by = intval($data->user_id);
    $media->title = $database->sanitize_input($data->title);
    $media->description = $database->sanitize_input($data->description ?? '');
    $media->media_type = $database->sanitize_input($data->media_type);
    $media->file_name = $database->sanitize_input($data->file_name);
    $media->file_path = $database->sanitize_input($data->file_path);
    $media->file_size_bytes = !empty($data->file_size_bytes) ? intval($data->file_size_bytes) : null;
    $media->mime_type = $database->sanitize_input($data->mime_type ?? '');
    $media->duration_seconds = !empty($data->duration_seconds) ? intval($data->duration_seconds) : null;
    $media->thumbnail_url = $database->sanitize_input($data->thumbnail_url ?? '');
    $media->scorm_version = $database->sanitize_input($data->scorm_version ?? '');
    $media->version_number = intval($data->version_number ?? 1);
    $media->parent_media_id = !empty($data->parent_media_id) ? intval($data->parent_media_id) : null;
    $media->is_latest_version = intval($data->is_latest_version ?? 1);

    if ($media->create()) {
        http_response_code(200);
        $database->send_response(200, "success", ["media_id" => $media->id], "Media created successfully.");
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Unable to create media.");
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>