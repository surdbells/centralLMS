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
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }
    $levels->id = $data->level_id;
    $record = $levels->read_single();
    if (is_array($record)) {
        $list = array(
            "level_id" => $record["level_id"] ?? null,
            "level_code" => $record["level_code"] ?? null,
            "level_name" => $record["level_name"] ?? null,
            "description" => $record["description"] ?? null,
            "created_at" => $record["created_at"] ?? null,
            "updated_at" => $record["updated_at"] ?? null,
            "is_active" => isset($record["is_active"]) ? (bool)$record["is_active"] : null
        );
        http_response_code(200);
        $database->send_response(200, "success", $list, "Record retrieved successfully.");
    } else {
        http_response_code(404);
        $database->send_response(404, "failed", [], $record); // "Team not found"
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Unable to get records. Invalid request.");
}

