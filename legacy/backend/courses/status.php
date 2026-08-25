<?php
include_once '../../config/Database.php';
include_once '../../class/Course.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$course = new Course($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id) && !empty($data->course_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized access.");
        exit;
    }

    $course->id = intval($data->course_id);
    $course->tenant_id = intval($data->tenant_id);
    $course->is_active = intval($data->status);

    if ($course->delete()) {
        http_response_code(200);
        $database->send_response(200, "success", [], "Course status updated successfully.");
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Unable to update course status.");
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>