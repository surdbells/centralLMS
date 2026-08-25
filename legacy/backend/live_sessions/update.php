<?php
include_once '../../config/Database.php';
include_once '../../class/LiveSession.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$session = new LiveSession($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id) && !empty($data->session_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized access.");
        exit;
    }

    $session->id = intval($data->session_id);
    $session->tenant_id = intval($data->tenant_id);
    $session->title = $database->sanitize_input($data->title);
    $session->description = $database->sanitize_input($data->description);
    $session->scheduled_at = $database->sanitize_input($data->scheduled_at);
    $session->duration_minutes = intval($data->duration_minutes);
    $session->meeting_url = $database->sanitize_input($data->meeting_url);
    $session->meeting_id = $database->sanitize_input($data->meeting_id);
    $session->status = $database->sanitize_input($data->status);
    $session->max_participants = !empty($data->max_participants) ? intval($data->max_participants) : null;
    $session->recording_url = $database->sanitize_input($data->recording_url ?? '');
    $session->is_active = intval($data->is_active);

    if ($session->update()) {
        http_response_code(200);
        $database->send_response(200, "success", [], "Live session updated successfully.");
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Unable to update live session.");
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>