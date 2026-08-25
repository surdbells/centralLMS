<?php
include_once '../../config/Database.php';
include_once '../../class/UserPoint.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$points = new UserPoint($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }

    $points->tenant_id = intval($data->tenant_id);
    $points->page = intval($data->page ?? 1);
    $points->per_page = intval($data->per_page ?? 20);
    $points->sort_by = $data->sort_by ?? 'earned_at';
    $points->sort_dir = $data->sort_dir ?? 'desc';
    $points->user_id = $data->student_id ?? null;
    $points->point_type = $data->point_type ?? '';

    $result = $points->list();

    http_response_code(200);
    $database->send_response(200, "success", [
        "points" => $result['data'],
        "meta" => $result['meta'],
        "links" => $user->buildPaginationLinks($result['meta']['page'], $result['meta']['per_page'], $result['meta']['total_pages'])
    ], "Points history retrieved successfully");
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>