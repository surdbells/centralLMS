<?php
include_once '../../config/Database.php';
include_once '../../class/assessment/Assessment.php';

$database = new Database();
$db = $database->getConnection();

$data = json_decode(file_get_contents("php://input"));

if (
    !empty($data->token) &&
    !empty($data->user_id) &&
    !empty($data->assessment_id) &&
    !empty($data->section_id)
) {

    $token_ok = $database->verify_user($data->token, $data->user_id);

    if ($token_ok == 100) {

        $assessment = new Assessment($db);
        $assessment->id = $data->assessment_id;

        if ($assessment->deleteSection($data->section_id)) {
            $database->send_response(200, "success", [], "Section deleted successfully");
        } else {
            $database->send_response(200, "failed", [], "Unable to delete section");
        }

    } else {
        $database->send_response(401, "failed", [], "Unauthorized");
    }
} else {
    $database->send_response(400, "failed", [], "Invalid request");
}
