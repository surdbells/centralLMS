<?php
include_once '../../config/Database.php';
include_once '../../class/Course.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$course = new Course($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->id) && !empty($data->tenant_id) && !empty($data->title)) {
    $token_check = $database->verify_user($data->token, $data->id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized access.");
        exit;
    }

    $course->tenant_id = intval($data->tenant_id);
    $course->title = $database->sanitize_input($data->title);
    $course->objectives = $database->sanitize_input($data->objectives);
    $course->description = $database->sanitize_input($data->description ?? '');
    $course->summary = $database->sanitize_input($data->summary ?? '');
    $course->category_id = !empty($data->category) ? intval($data->category) : null;
    $course->instructor_id = intval($data->instructor);
    $course->certificate_template_id = !empty($data->certificate) ? intval($data->certificate) : null;
    $course->status = $database->sanitize_input($data->status ?? 'draft');
    $course->level = $database->sanitize_input($data->level ?? '');
    $course->duration_minutes = floatval($data->duration_minutes ?? 0);
    $course->pass_percentage = floatval($data->pass_percentage ?? 0);
    $course->selfEnroll = $data->selfEnroll;
    $course->enrollment_limit = !empty($data->enrollment_limit) ? intval($data->enrollment_limit) : null;
    $course->start_date = $data->start_date ?? null;
    $course->end_date = $data->end_date ?? null;

    if ($course->create()) {
        http_response_code(200);
        $database->send_response(200, "success", [], "Course created successfully.");
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Unable to create course.");
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}