<?php
include_once '../../config/Database.php';
include_once '../../class/Lesson.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$lesson = new Lesson($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }

    $lesson->tenant_id = intval($data->tenant_id);
    $lesson->page = intval($data->page ?? 1);
    $lesson->per_page = intval($data->per_page ?? 20);
    $lesson->sort_by = $data->sort_by ?? 'sort_order';
    $lesson->sort_dir = $data->sort_dir ?? 'asc';
    $lesson->is_active = $data->is_active ?? 1;
    $lesson->module_id = $data->module_id ?? null;

    $result = $lesson->list();

    http_response_code(200);
    $database->send_response(200, "success", [
        "lessons" => $result['data'],
        "meta" => $result['meta'],
        "links" => $user->buildPaginationLinks($result['meta']['page'], $result['meta']['per_page'], $result['meta']['total_pages'])
    ], "Lessons retrieved successfully");
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>