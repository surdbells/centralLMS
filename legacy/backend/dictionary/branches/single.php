<?php
include_once '../../config/Database.php';
include_once '../../class/Branches.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$branch = new Branches($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) && !empty($data->id) && !empty($data->tenant_id) && !empty($data->branch_id)) {
    $token_check = $database->verify_user($data->token, $data->id);
    if ($token_check != 100 || !$user->is_admin($data->id)) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }
    $branch->id = $data->branch_id;
    $record = $branch->read_single();
    if (is_array($record)) {
        $list = array(
            "branch_id" => $record["branch_id"] ?? null,
            "branch_code" => $record["branch_code"] ?? null,
            "branch_name" => $record["branch_name"] ?? null,
            "address" => $record["address"] ?? null,
            "city" => $record["city"] ?? null,
            "state" => $record["state"] ?? null,
            "country" => $record["country"] ?? null,
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

