<?php
include_once '../../config/Database.php';
include_once '../../class/CourseEnrollment.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$enrollment = new CourseEnrollment($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }

    $enrollment->tenant_id = intval($data->tenant_id);
    $enrollment->page = intval($data->page ?? 1);
    $enrollment->per_page = intval($data->per_page ?? 20);
    $enrollment->sort_by = $data->sort_by ?? 'enrolled_at';
    $enrollment->sort_dir = $data->sort_dir ?? 'desc';
    $enrollment->is_active = $data->is_active ?? 1;
    $enrollment->status = $data->status ?? '';
    $enrollment->course_id = $data->course_id ?? null;
    $enrollment->user_id = $data->student_id ?? null;

    $result = $enrollment->list();

    http_response_code(200);
    $database->send_response(200, "success", [
        "enrollments" => $result['data'],
        "meta" => $result['meta'],
        "links" => $user->buildPaginationLinks($result['meta']['page'], $result['meta']['per_page'], $result['meta']['total_pages'])
    ], "Enrollments retrieved successfully");
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>