<?php
include_once '../../config/Database.php';
include_once '../../class/Teams.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$teams = new Teams($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) && !empty($data->id) && !empty($data->tenant_id)  && !empty($data->team_name) && !empty($data->description)) {
    $token_check = $database->verify_user($data->token, $data->id);
    if ($token_check != 100 || !$user->is_admin($data->id)) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized access.");
        exit;
    }
    $teams->team_code = "T-".$database->getInitials($data->team_name);
    $teams->team_name = $database->sanitize_input($data->team_name);
    $teams->description = $database->sanitize_input($data->description);
    if ($teams->create()) {
        http_response_code(200);
        $database->send_response(200, "success", [], "Team created successfully.");
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Unable to create team.");
    }

} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
