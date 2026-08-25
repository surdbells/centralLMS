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
    if (empty($data->query) && empty($data->q)) {
        http_response_code(400);
        $database->send_response(400, "failed", [], "Search query is required");
        exit;
    }
    $note->tenant_id = intval($data->tenant_id);
    $note->user_id = intval($data->user_id);
    $query = $data->query ?? $data->q ?? '';
    $results = $note->search_notes($query);
    http_response_code(200);
    $database->send_response(200, "success", [
        "notes" => $results,
        "meta" => [
            "total" => count($results),
            "query" => $query
        ]
    ], "Search completed successfully");
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>