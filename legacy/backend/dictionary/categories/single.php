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
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }

    $category->id = $data->category_id;
    $category->tenant_id = $data->tenant_id;
    $record = $category->read_single();

    if (is_array($record)) {
        http_response_code(200);
        $database->send_response(200, "success", $record, "Category retrieved successfully.");
    } else {
        http_response_code(404);
        $database->send_response(404, "failed", [], $record);
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>