<?php
include_once '../../config/Database.php';
include_once '../../class/Departments.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$department = new Departments($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) && !empty($data->id) && !empty($data->tenant_id) && !empty($data->department_id)) {
    $token_check = $database->verify_user($data->token, $data->id);
    if ($token_check != 100 || !$user->is_admin($data->id)) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }
    $department->id = $data->department_id;
    $record = $department->read_single();
    if (is_array($record)) {
        $list = array(
            "department_id" => $record["department_id"] ?? null,
            "department_code" => $record["department_code"] ?? null,
            "department_name" => $record["department_name"] ?? null,
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

