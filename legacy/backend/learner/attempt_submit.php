<?php
include_once '../../config/Database.php';
include_once '../../class/Assessment.php';

$database = new Database();
$db = $database->getConnection();
$assessment = new Assessment($db);

$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->attempt_id) && !empty($data->answers)) {

    $token_verification = $database->verify_user($data->token, $data->user_id);

    if ($token_verification == 100) {

        $submit = $assessment->submit_attempt(
            $data->attempt_id,
            $data->answers
        );

        if ($submit) {
            $database->send_response(200, "success", [], "Attempt submitted successfully");
        } else {
            $database->send_response(500, "failed", [], "Failed to submit attempt");
        }

    } else {
        $database->send_response(401, "failed", [], "Unauthorized request");
    }

} else {
    $database->send_response(400, "failed", [], "Invalid request");
}
