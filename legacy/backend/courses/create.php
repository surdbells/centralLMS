<?php
include_once '../../config/Database.php';
include_once '../../class/Course.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$course = new Course($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id) && !empty($data->title) && !empty($data->slug)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized access.");
        exit;
    }

    $course->tenant_id = intval($data->tenant_id);
    $course->title = $database->sanitize_input($data->title);
    $course->slug = $database->sanitize_input($data->slug);
    $course->description = $database->sanitize_input($data->description ?? '');
    $course->thumbnail_url = $database->sanitize_input($data->thumbnail_url ?? '');
    $course->category_id = !empty($data->category_id) ? intval($data->category_id) : null;
    $course->instructor_id = intval($data->instructor_id ?? $data->user_id);
    $course->certificate_template_id = !empty($data->certificate_template_id) ? intval($data->certificate_template_id) : null;
    $course->status = $database->sanitize_input($data->status ?? 'draft');
    $course->level = $database->sanitize_input($data->level ?? '');
    $course->duration_hours = floatval($data->duration_hours ?? 0);
    $course->is_featured = intval($data->is_featured ?? 0);
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
?>