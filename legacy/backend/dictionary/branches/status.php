<?php
include_once '../../config/Database.php';
include_once '../../class/Branches.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$branches = new Branches($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) && !empty($data->id) && !empty($data->tenant_id) && !empty($data->branch_id)) {
    $token_check = $database->verify_user($data->token, $data->id);
    if ($token_check != 100 || !$user->is_admin($data->id)) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized access.");
        exit;
    }
    $branches->id = intval($data->branch_id);
    $branches->is_active = intval($data->status);
    if ($branches->delete()) {
        http_response_code(200);
        $database->send_response(200, "success", [], "Branch status updated successfully.");
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Unable to update branch.");
    }

} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
