<?php
include_once '../../config/Database.php';
include_once '../../class/assessment/Assessment.php';
$database = new Database();
$db = $database->getConnection();
$assessment = new Assessment($db);
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) && !empty($data->user_id) && !empty($data->assessment_id)) {
    $token_verification = $database->verify_user($data->token, $data->user_id);
    if ($token_verification == 100) {
        $stats = $assessment->assessment_analytics($data->assessment_id);
        $database->send_response(200, "success", $stats, "Assessment analytics loaded");
    } else {
        $database->send_response(401, "failed", [], "Unauthorized");
    }
} else {
    $database->send_response(400, "failed", [], "Invalid request");
}
