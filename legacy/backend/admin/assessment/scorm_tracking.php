<?php
include_once '../../config/Database.php';
include_once '../../class/assessment/Assessment.php';
$database = new Database();
$db = $database->getConnection();
$assessment = new Assessment($db);
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) && !empty($data->user_id) &&
    !empty($data->sco_id) && !empty($data->progress)
) {
    $token_verification = $database->verify_user($data->token, $data->user_id);
    if ($token_verification == 100) {
        $track = $assessment->track_scorm(
            $data->user_id,
            $data->sco_id,
            $data->progress,
            $data->status ?? null,
            $data->score ?? null
        );
        if ($track) {
            $database->send_response(200, "success", [], "SCORM tracking saved");
        } else {
            $database->send_response(500, "failed", [], "Failed to save SCORM data");
        }

    } else {
        $database->send_response(401, "failed", [], "Unauthorized");
    }
} else {
    $database->send_response(400, "failed", [], "Invalid request");
}
