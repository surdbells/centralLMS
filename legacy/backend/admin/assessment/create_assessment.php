<?php
include_once '../../config/Database.php';
include_once '../../class/assessment/Assessment.php';
$database = new Database();
$db = $database->getConnection();
$data = json_decode(file_get_contents("php://input"));
if (
    !empty($data->token) &&
    !empty($data->user_id) &&
    !empty($data->title)
) {
    $token_ok = $database->verify_user($data->token, $data->user_id);
    if ($token_ok == 100) {
        $assessment = new Assessment($db);
        $assessment->title = $database->sanitize_input($data->title);
        $assessment->description = $data->description ?? '';
        $assessment->total_marks = $data->total_marks ?? 0;
        $assessment->duration_minutes = $data->duration ?? 0;
        $assessment->attempts_allowed = $data->attempts ?? 1;
        $assessment->pass_percentage = $data->pass_percentage ?? 50;
        $assessment->created_by = $data->user_id;
        if ($assessment->create()) {
            $database->send_response(200, "success", [], "Assessment created successfully");
        } else {
            $database->send_response(200, "failed", [], "Unable to create assessment");
        }
    } else {
        $database->send_response(401, "failed", [], "Unauthorized request");
    }

} else {
    $database->send_response(400, "failed", [], "Invalid request");
}
