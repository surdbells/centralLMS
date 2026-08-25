<?php
include_once '../../config/Database.php';
include_once '../../class/UserBadge.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$userBadge = new UserBadge($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }

    $userBadge->tenant_id = intval($data->tenant_id);
    $userBadge->page = intval($data->page ?? 1);
    $userBadge->per_page = intval($data->per_page ?? 20);
    $userBadge->sort_by = $data->sort_by ?? 'earned_at';
    $userBadge->sort_dir = $data->sort_dir ?? 'desc';
    $userBadge->user_id = $data->student_id ?? null;
    $userBadge->badge_id = $data->badge_id ?? null;

    $result = $userBadge->list();

    http_response_code(200);
    $database->send_response(200, "success", [
        "user_badges" => $result['data'],
        "meta" => $result['meta'],
        "links" => $user->buildPaginationLinks($result['meta']['page'], $result['meta']['per_page'], $result['meta']['total_pages'])
    ], "User badges retrieved successfully");
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>