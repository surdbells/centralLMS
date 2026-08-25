<?php
include_once '../../config/Database.php';
include_once '../../class/Branches.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$branch = new Branches($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) &&
    !empty($data->id) &&
    !empty($data->branch_id) &&
    !empty($data->branch_code) &&
    !empty($data->branch_name) &&
    !empty($data->city) &&
    !empty($data->address) &&
    !empty($data->country) &&
    !empty($data->state
    )) {
    $token_check = $database->verify_user($data->token, $data->id);
    if ($token_check != 100 || !$user->is_admin($data->id)) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized access.");
        exit;
    }
    $branch->id = intval($data->branch_id);
    $branch->branch_code = $database->sanitize_input($data->branch_code);
    $branch->branch_name = $database->sanitize_input($data->branch_name);
    $branch->address = $database->sanitize_input($data->address);
    $branch->city = $database->sanitize_input($data->city);
    $branch->state = $database->sanitize_input($data->state);
    $branch->country = $database->sanitize_input($data->country);
    $branch->is_active = $database->sanitize_input($data->status);

    if ($branch->update()) {
        http_response_code(200);
        $database->send_response(200, "success", [], "Branch updated successfully.");
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Unable to update branch.");
    }

} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
