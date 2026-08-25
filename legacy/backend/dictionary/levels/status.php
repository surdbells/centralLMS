<?php
include_once '../../config/Database.php';
include_once '../../class/Levels.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$levels = new Levels($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) && !empty($data->id) && !empty($data->tenant_id) && !empty($data->level_id)) {
    $token_check = $database->verify_user($data->token, $data->id);
    if ($token_check != 100 || !$user->is_admin($data->id)) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized access.");
        exit;
    }
    $levels->id = intval($data->level_id);
    $levels->is_active = intval($data->status);
    if ($levels->delete()) {
        http_response_code(200);
        $database->send_response(200, "success", [], "Level updated successfully.");
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Unable to update level.");
    }

} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
