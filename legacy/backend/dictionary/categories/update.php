<?php
include_once '../../config/Database.php';
include_once '../../class/Category.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$category = new Category($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->id) && !empty($data->tenant_id) && !empty($data->category_id)) {
    $token_check = $database->verify_user($data->token, $data->id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized access.");
        exit;
    }

    $category->id = intval($data->category_id);
    $category->tenant_id = intval($data->tenant_id);
    $category->name = $database->sanitize_input($data->name);
    $category->description = $database->sanitize_input($data->description);
    $category->parent_id = !empty($data->parent_id) ? intval($data->parent_id) : null;
    $category->sort_order = intval($data->sort_order);
    $category->is_active = intval($data->is_active);

    if ($category->update()) {
        http_response_code(200);
        $database->send_response(200, "success", [], "Category updated successfully.");
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Unable to update category.");
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>