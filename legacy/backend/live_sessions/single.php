<?php
include_once '../../config/Database.php';
include_once '../../class/LiveSession.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$session = new LiveSession($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id) && !empty($data->session_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }

    $session->id = $data->session_id;
    $session->tenant_id = $data->tenant_id;
    $record = $session->read_single();

    if (is_array($record)) {
        http_response_code(200);
        $database->send_response(200, "success", $record, "Live session retrieved successfully.");
    } else {
        http_response_code(404);
        $database->send_response(404, "failed", [], $record);
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>