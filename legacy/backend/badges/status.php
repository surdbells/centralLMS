<?php
include_once '../../config/Database.php';
include_once '../../class/Badge.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$badge = new Badge($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id) && !empty($data->badge_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized access.");
        exit;
    }

    $badge->id = intval($data->badge_id);
    $badge->tenant_id = intval($data->tenant_id);
    $badge->is_active = intval($data->status);

    if ($badge->delete()) {
        http_response_code(200);
        $database->send_response(200, "success", [], "Badge status updated successfully.");
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Unable to update badge status.");
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>