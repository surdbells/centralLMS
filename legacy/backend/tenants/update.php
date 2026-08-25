<?php
include_once '../../config/Database.php';
include_once '../../class/Tenant.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$tenant = new Tenant($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100 || !$user->is_admin($data->user_id)) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized access.");
        exit;
    }

    $tenant->id = intval($data->tenant_id);
    $tenant->name = $database->sanitize_input($data->name);
    $tenant->custom_domain = $database->sanitize_input($data->custom_domain ?? '');
    $tenant->status = $database->sanitize_input($data->status);
    $tenant->plan_type = $database->sanitize_input($data->plan_type);
    $tenant->max_users = intval($data->max_users);
    $tenant->max_storage_gb = intval($data->max_storage_gb);
    $tenant->is_active = intval($data->is_active);

    if ($tenant->update()) {
        http_response_code(200);
        $database->send_response(200, "success", [], "Tenant updated successfully.");
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Unable to update tenant.");
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>