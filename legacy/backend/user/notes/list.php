<?php
include_once '../../config/Database.php';
include_once '../../class/Note.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$note = new Note($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }

    $note->tenant_id = intval($data->tenant_id);
    $note->user_id = intval($data->user_id);
    $note->lesson_id = $data->lesson_id ?? null;
    $note->page = intval($data->page ?? 1);
    $note->per_page = intval($data->per_page ?? 20);
    $note->sort_by = $data->sort_by ?? 'note_id';
    $note->sort_dir = $data->sort_dir ?? 'desc';
    $note->is_active = $data->is_active ?? 1;

    if (isset($data->is_pinned)) {
        $note->is_pinned = $data->is_pinned;
    }

    $result = $note->list();

    http_response_code(200);
    $database->send_response(200, "success", [
        "notes" => $result['data'],
        "meta" => $result['meta'],
        "links" => $user->buildPaginationLinks($result['meta']['page'], $result['meta']['per_page'], $result['meta']['total_pages'])
    ], "Notes retrieved successfully");
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>