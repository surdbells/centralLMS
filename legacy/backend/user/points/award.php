<?php
include_once '../../config/Database.php';
include_once '../../class/UserPoint.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$points = new UserPoint($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id) && !empty($data->student_id) && isset($data->points)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized access.");
        exit;
    }

    $points->tenant_id = intval($data->tenant_id);
    $points->user_id = intval($data->student_id);
    $points->points = intval($data->points);
    $points->point_type = $database->sanitize_input($data->point_type ?? '');
    $points->description = $database->sanitize_input($data->description ?? '');
    $points->entity_type = $database->sanitize_input($data->entity_type ?? '');
    $points->entity_id = !empty($data->entity_id) ? intval($data->entity_id) : null;

    if ($points->create()) {
        http_response_code(200);
        $database->send_response(200, "success", [], "Points awarded successfully.");
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Unable to award points.");
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>