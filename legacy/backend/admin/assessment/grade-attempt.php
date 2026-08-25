<?php
include_once '../../config/Database.php';
include_once '../../class/assessment/AssessmentAttempt.php';

$database = new Database();
$db = $database->getConnection();

$attempt = new AssessmentAttempt($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->instructor_id) && !empty($data->attempt_id) && !empty($data->grades)) {

    $token_check = $database->verify_user($data->token, $data->instructor_id);

    if ($token_check == 100) {

        $attempt->id = $database->sanitize_input($data->attempt_id);

        foreach ($data->grades as $grade) {

            $attempt->setFinalScore(
                $grade->response_id,
                floatval($grade->score),
                $database->sanitize_input($grade->feedback)
            );
        }

        $attempt->gradeAttempt($data->instructor_id);

        $database->send_response(200, "success", [], "Attempt graded successfully");
    } else {
        $database->send_response(401, "failed", [], "Unauthorized request");
    }

} else {
    $database->send_response(400, "failed", [], "Invalid request");
}
