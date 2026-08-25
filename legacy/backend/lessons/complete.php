<?php
include_once '../../config/Database.php';
include_once '../../class/LessonCompletion.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$completion = new LessonCompletion($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id) && !empty($data->lesson_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized access.");
        exit;
    }

    // Check if already completed
    $completion->tenant_id = intval($data->tenant_id);
    $completion->lesson_id = intval($data->lesson_id);
    $completion->user_id = intval($data->user_id);

    if ($completion->check_completed()) {
        http_response_code(400);
        $database->send_response(400, "failed", [], "Lesson already completed.");
        exit;
    }

    $completion->time_spent_seconds = intval($data->time_spent_seconds ?? 0);

    if ($completion->create()) {
        http_response_code(200);
        $database->send_response(200, "success", [], "Lesson completed successfully.");
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Unable to complete lesson.");
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>