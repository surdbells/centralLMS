<?php
include_once '../config/Database.php';
include_once '../class/Course.php';
include_once '../class/User.php';
$database = new Database();
$db = $database->getConnection();
$course = new Course($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }
    $course->tenant_id = intval($data->tenant_id);
    $course->page = intval($data->page ?? 1);
    $course->per_page = intval($data->per_page ?? 20);
    $course->sort_by = $data->sort_by ?? 'course_id';
    $course->sort_dir = $data->sort_dir ?? 'desc';
    $course->is_active = $data->is_active ?? 1;
    $course->status = $data->status ?? '';
    $course->category_id = $data->category_id ?? null;

    $result = $course->list();

    http_response_code(200);
    $database->send_response(200, "success", [
        "courses" => $result['data'],
        "meta" => $result['meta'],
        "links" => $user->buildPaginationLinks($result['meta']['page'], $result['meta']['per_page'], $result['meta']['total_pages'])
    ], "Courses retrieved successfully");
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>