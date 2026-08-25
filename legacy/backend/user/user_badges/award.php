<?php
include_once '../../config/Database.php';
include_once '../../class/UserBadge.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$userBadge = new UserBadge($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id) && !empty($data->badge_id) && !empty($data->student_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized access.");
        exit;
    }

    // Check if badge already earned
    $userBadge->tenant_id = intval($data->tenant_id);
    $userBadge->user_id = intval($data->student_id);
    $userBadge->badge_id = intval($data->badge_id);

    if ($userBadge->check_badge_earned()) {
        http_response_code(400);
        $database->send_response(400, "failed", [], "Badge already awarded to this user.");
        exit;
    }

    $userBadge->entity_type = $database->sanitize_input($data->entity_type ?? '');
    $userBadge->entity_id = !empty($data->entity_id) ? intval($data->entity_id) : null;

    if ($userBadge->create()) {
        http_response_code(200);
        $database->send_response(200, "success", [], "Badge awarded successfully.");
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Unable to award badge.");
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>