<?php
include_once '../../config/Database.php';
include_once '../../class/CourseEnrollment.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$enrollment = new CourseEnrollment($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id) && !empty($data->course_id) && !empty($data->student_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized access.");
        exit;
    }

    // Check if already enrolled
    $enrollment->tenant_id = intval($data->tenant_id);
    $enrollment->course_id = intval($data->course_id);
    $enrollment->user_id = intval($data->student_id);

    if ($enrollment->check_enrollment()) {
        http_response_code(400);
        $database->send_response(400, "failed", [], "Student already enrolled in this course.");
        exit;
    }

    $enrollment->status = $database->sanitize_input($data->status ?? 'active');

    if ($enrollment->create()) {
        http_response_code(200);
        $database->send_response(200, "success", [], "Enrollment created successfully.");
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Unable to create enrollment.");
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>