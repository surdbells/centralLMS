<?php
include_once '../../config/Database.php';
include_once '../../class/Module.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$module = new Module($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }

    $module->tenant_id = intval($data->tenant_id);
    $module->page = intval($data->page ?? 1);
    $module->per_page = intval($data->per_page ?? 20);
    $module->sort_by = $data->sort_by ?? 'sort_order';
    $module->sort_dir = $data->sort_dir ?? 'asc';
    $module->is_active = $data->is_active ?? 1;
    $module->course_id = $data->course_id ?? null;

    $result = $module->list();

    http_response_code(200);
    $database->send_response(200, "success", [
        "modules" => $result['data'],
        "meta" => $result['meta'],
        "links" => $user->buildPaginationLinks($result['meta']['page'], $result['meta']['per_page'], $result['meta']['total_pages'])
    ], "Modules retrieved successfully");
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>