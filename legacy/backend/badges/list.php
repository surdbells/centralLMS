<?php
include_once '../../config/Database.php';
include_once '../../class/Badge.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$badge = new Badge($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }

    $badge->tenant_id = intval($data->tenant_id);
    $badge->page = intval($data->page ?? 1);
    $badge->per_page = intval($data->per_page ?? 20);
    $badge->sort_by = $data->sort_by ?? 'badge_id';
    $badge->sort_dir = $data->sort_dir ?? 'asc';
    $badge->is_active = $data->is_active ?? 1;
    $badge->badge_type = $data->badge_type ?? '';

    $result = $badge->list();

    http_response_code(200);
    $database->send_response(200, "success", [
        "badges" => $result['data'],
        "meta" => $result['meta'],
        "links" => $user->buildPaginationLinks($result['meta']['page'], $result['meta']['per_page'], $result['meta']['total_pages'])
    ], "Badges retrieved successfully");
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>