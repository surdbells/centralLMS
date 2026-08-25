<?php
include_once '../../config/Database.php';
include_once '../../class/Media.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$media = new Media($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }

    $media->tenant_id = intval($data->tenant_id);
    $media->page = intval($data->page ?? 1);
    $media->per_page = intval($data->per_page ?? 20);
    $media->sort_by = $data->sort_by ?? 'media_id';
    $media->sort_dir = $data->sort_dir ?? 'desc';
    $media->is_active = $data->is_active ?? 1;
    $media->media_type = $data->media_type ?? '';
    $media->is_latest_version = $data->is_latest_version ?? 1;

    $result = $media->list();

    http_response_code(200);
    $database->send_response(200, "success", [
        "media" => $result['data'],
        "meta" => $result['meta'],
        "links" => $user->buildPaginationLinks($result['meta']['page'], $result['meta']['per_page'], $result['meta']['total_pages'])
    ], "Media retrieved successfully");
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>