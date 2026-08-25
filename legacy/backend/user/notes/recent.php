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

    $note->tenant_id = intval($data->tenant_id);
    $note->user_id = intval($data->user_id);
    $limit = intval($data->limit ?? 10);

    $notes = $note->get_recent_notes($limit);

    http_response_code(200);
    $database->send_response(200, "success", [
        "notes" => $notes,
        "meta" => [
            "total" => count($notes),
            "limit" => $limit
        ]
    ], "Recent notes retrieved successfully");
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>