<?php
include_once '../../config/Database.php';
include_once '../../class/Module.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$module = new Module($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id) && !empty($data->module_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized access.");
        exit;
    }

    $module->id = intval($data->module_id);
    $module->tenant_id = intval($data->tenant_id);
    $module->is_active = intval($data->status);

    if ($module->delete()) {
        http_response_code(200);
        $database->send_response(200, "success", [], "Module status updated successfully.");
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Unable to update module status.");
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>