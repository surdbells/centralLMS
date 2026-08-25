<?php
include_once '../../config/Database.php';
include_once '../../class/Badge.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$badge = new Badge($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id) && !empty($data->badge_name) && !empty($data->badge_type)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized access.");
        exit;
    }

    $badge->tenant_id = intval($data->tenant_id);
    $badge->badge_name = $database->sanitize_input($data->badge_name);
    $badge->description = $database->sanitize_input($data->description ?? '');
    $badge->badge_icon_url = $database->sanitize_input($data->badge_icon_url ?? '');
    $badge->badge_type = $database->sanitize_input($data->badge_type);
    $badge->points_value = intval($data->points_value ?? 0);
    $badge->criteria_type = $database->sanitize_input($data->criteria_type ?? '');
    $badge->criteria_value = $database->sanitize_input($data->criteria_value ?? '');

    if ($badge->create()) {
        http_response_code(200);
        $database->send_response(200, "success", [], "Badge created successfully.");
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Unable to create badge.");
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>