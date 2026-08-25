<?php
include_once '../config/Database.php';
include_once '../class/Tenant.php';
include_once '../class/User.php';

$database = new Database();
$db = $database->getConnection();
$tenant = new Tenant($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100 || !$user->is_admin($data->user_id)) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }

    $tenant->page = intval($data->page ?? 1);
    $tenant->per_page = intval($data->per_page ?? 20);
    $tenant->sort_by = $data->sort_by ?? 'tenant_id';
    $tenant->sort_dir = $data->sort_dir ?? 'asc';
    $tenant->is_active = $data->is_active ?? 1;

    $result = $tenant->list();

    http_response_code(200);
    $database->send_response(200, "success", [
        "tenants" => $result['data'],
        "meta" => $result['meta'],
        "links" => $user->buildPaginationLinks($result['meta']['page'], $result['meta']['per_page'], $result['meta']['total_pages'])
    ], "Tenants retrieved successfully");
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>