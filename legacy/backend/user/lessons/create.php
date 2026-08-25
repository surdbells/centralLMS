<?php
include_once '../../config/Database.php';
include_once '../../class/Lesson.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$lesson = new Lesson($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id) && !empty($data->module_id) && !empty($data->title) && !empty($data->content_type)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized access.");
        exit;
    }

    $lesson->tenant_id = intval($data->tenant_id);
    $lesson->module_id = intval($data->module_id);
    $lesson->title = $database->sanitize_input($data->title);
    $lesson->content_type = $database->sanitize_input($data->content_type);
    $lesson->content_url = $database->sanitize_input($data->content_url ?? '');
    $lesson->content_text = $database->sanitize_input($data->content_text ?? '');
    $lesson->duration_minutes = !empty($data->duration_minutes) ? intval($data->duration_minutes) : null;
    $lesson->sort_order = intval($data->sort_order ?? 1);
    $lesson->is_preview = intval($data->is_preview ?? 0);

    if ($lesson->create()) {
        http_response_code(200);
        $database->send_response(200, "success", [], "Lesson created successfully.");
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Unable to create lesson.");
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>