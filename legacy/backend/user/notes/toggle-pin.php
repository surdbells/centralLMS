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

    if (empty($data->note_id)) {
        http_response_code(400);
        $database->send_response(400, "failed", [], "Note ID is required");
        exit;
    }

    $note->tenant_id = intval($data->tenant_id);
    $note->user_id = intval($data->user_id);
    $note->note_id = intval($data->note_id);

    if ($note->toggle_pin()) {
        $updated_note = $note->read_single();
        http_response_code(200);
        $database->send_response(200, "success", ["note" => $updated_note], "Note pin status toggled successfully");
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Failed to toggle pin status");
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>