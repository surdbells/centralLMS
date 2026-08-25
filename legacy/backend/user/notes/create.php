<?php
include_once '../../config/Database.php';
include_once '../../class/Note.php';

$database = new Database();
$db = $database->getConnection();
$note = new Note($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }

    if (empty($data->lesson_id) || empty($data->content)) {
        http_response_code(400);
        $database->send_response(400, "failed", [], "Lesson ID and content are required");
        exit;
    }

    $note->tenant_id = intval($data->tenant_id);
    $note->user_id = intval($data->user_id);
    $note->lesson_id = intval($data->lesson_id);
    $note->title = $data->title ?? '';
    $note->content = $data->content;
    $note->lesson_timestamp = $data->lesson_timestamp ?? null;
    $note->is_pinned = $data->is_pinned ?? false;

    if ($note->create()) {
        $note->note_id = $db->insert_id;
        $created_note = $note->read_single();

        http_response_code(201);
        $database->send_response(201, "success", ["note" => $created_note], "Note created successfully");
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Failed to create note");
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>