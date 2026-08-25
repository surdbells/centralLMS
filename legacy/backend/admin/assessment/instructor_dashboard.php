<?php
include_once '../../config/Database.php';
include_once '../../class/assessment/Assessment.php';

$database = new Database();
$db = $database->getConnection();
$assessment = new Assessment($db);

$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id)) {

    $token_verification = $database->verify_user($data->token, $data->user_id);

    if ($token_verification == 100) {

        $dash = $assessment->instructor_dashboard($data->user_id);

        $database->send_response(200, "success", $dash, "Dashboard loaded");

    } else {
        $database->send_response(401, "failed", [], "Unauthorized");
    }

} else {
    $database->send_response(400, "failed", [], "Invalid request");
}
