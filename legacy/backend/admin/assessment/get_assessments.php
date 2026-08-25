<?php
include_once '../../config/Database.php';
include_once '../../class/assessment/Assessment.php';

$database = new Database();
$db = $database->getConnection();
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id)) {

    $token_ok = $database->verify_user($data->token, $data->user_id);

    if ($token_ok == 100) {

        $assessment = new Assessment($db);
        $result = $assessment->all();

        $records = [];
        while ($row = $result->fetch_assoc()) {
            $records[] = $row;
        }

        $database->send_response(200, "success", $records, "Assessments retrieved");

    } else {
        $database->send_response(401, "failed", [], "Unauthorized request");
    }

} else {
    $database->send_response(400, "failed", [], "Invalid request");
}
