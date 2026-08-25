<?php
include_once '../../config/Database.php';
include_once '../../class/LiveSession.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$session = new LiveSession($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }

    $session->tenant_id = intval($data->tenant_id);
    $session->page = intval($data->page ?? 1);
    $session->per_page = intval($data->per_page ?? 20);
    $session->sort_by = $data->sort_by ?? 'scheduled_at';
    $session->sort_dir = $data->sort_dir ?? 'asc';
    $session->is_active = $data->is_active ?? 1;
    $session->course_id = $data->course_id ?? null;
    $session->status = $data->status ?? '';

    $result = $session->list();

    http_response_code(200);
    $database->send_response(200, "success", [
        "sessions" => $result['data'],
        "meta" => $result['meta'],
        "links" => $user->buildPaginationLinks($result['meta']['page'], $result['meta']['per_page'], $result['meta']['total_pages'])
    ], "Live sessions retrieved successfully");
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>