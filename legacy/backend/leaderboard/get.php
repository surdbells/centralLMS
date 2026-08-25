<?php
include_once '../../config/Database.php';
include_once '../../class/UserPoints.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$points = new UserPoints($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }

    $points->tenant_id = intval($data->tenant_id);
    $period = $database->sanitize_input($data->period ?? 'all_time');
    $limit = intval($data->limit ?? 10);

    $leaderboard = $points->get_leaderboard($period, $limit);

    http_response_code(200);
    $database->send_response(200, "success", [
        "leaderboard" => $leaderboard,
        "period" => $period
    ], "Leaderboard retrieved successfully");
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>