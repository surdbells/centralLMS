<?php
include_once '../../config/Database.php';
include_once '../../class/assessment/Assessment.php';

$database = new Database();
$db = $database->getConnection();

$data = json_decode(file_get_contents("php://input"));

if (
    !empty($data->token) &&
    !empty($data->user_id) &&
    !empty($data->section_id) &&
    !empty($data->question_id)
) {
    $token_ok = $database->verify_user($data->token, $data->user_id);
    if ($token_ok == 100) {
        $assessment = new Assessment($db);
        $ok = $assessment->addQuestionToSection(
            $data->section_id,
            $data->question_id,
            $data->points ?? 1
        );
        if ($ok) {
            $database->send_response(200, "success", [], "Question added to section");
        } else {
            $database->send_response(200, "failed", [], "Unable to add question");
        }

    } else {
        $database->send_response(401, "failed", [], "Unauthorized request");
    }

} else {
    $database->send_response(400, "failed", [], "Invalid request");
}
