<?php
include_once '../../config/Database.php';
include_once '../../class/Category.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$category = new Category($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->id) && !empty($data->tenant_id)) {
    $token_check = $database->verify_user($data->token, $data->id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }

    $category->tenant_id = intval($data->tenant_id);
    $category->page = intval($data->page ?? 1);
    $category->per_page = intval($data->per_page ?? 20);
    $category->sort_by = $data->sort_by ?? 'sort_order';
    $category->sort_dir = $data->sort_dir ?? 'asc';
    $category->is_active = $data->is_active ?? 1;
    $category->parent_id = $data->parent_id ?? null;
    $result = $category->list();
    http_response_code(200);
    $database->send_response(200, "success", [
        "categories" => $result['data'],
        "meta" => $result['meta'],
        "links" => $user->buildPaginationLinks($result['meta']['page'], $result['meta']['per_page'], $result['meta']['total_pages'])
    ], "Categories retrieved successfully");
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>