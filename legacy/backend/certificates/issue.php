<?php
include_once '../../config/Database.php';
include_once '../class/Certificate.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$certificate = new Certificate($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id) && !empty($data->course_id) && !empty($data->student_id) && !empty($data->template_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized access.");
        exit;
    }

    // Check if certificate already exists
    $certificate->tenant_id = intval($data->tenant_id);
    $certificate->course_id = intval($data->course_id);
    $certificate->user_id = intval($data->student_id);

    if ($certificate->check_exists()) {
        http_response_code(400);
        $database->send_response(400, "failed", [], "Certificate already issued for this student.");
        exit;
    }

    $certificate->template_id = intval($data->template_id);
    $certificate->course_title = $database->sanitize_input($data->course_title);
    $certificate->learner_first_name = $database->sanitize_input($data->learner_first_name);
    $certificate->learner_last_name = $database->sanitize_input($data->learner_last_name);

    if ($certificate->create()) {
        http_response_code(200);
        $database->send_response(200, "success", [
            "certificate_id" => $certificate->id,
            "certificate_number" => $certificate->certificate_number,
            "verification_code" => $certificate->verification_code
        ], "Certificate issued successfully.");
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Unable to issue certificate.");
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>