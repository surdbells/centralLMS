<?php
include_once '../../config/Database.php';
include_once '../../class/CertificateTemplates.php';
include_once '../../class/User.php';
$database = new Database();
$db = $database->getConnection();
$templates = new CertificateTemplates($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) && !empty($data->id) && !empty($data->tenant_id) && !empty($data->template_id)) {
    $token_check = $database->verify_user($data->token, $data->id);
    if ($token_check != 100 || !$user->is_admin($data->id)) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }
    $templates->id = $data->template_id;
    $record = $templates->read_single();
    if (is_array($record)) {
        $list = array(
            "template_id" => (int)$record["template_id"],
            "template_name" => $record["template_name"],
            "description" => $record["description"],
            "template_html" => $record["template_html"],
            "template_css" => $record["template_css"],
            "orientation" => $record["orientation"],
            "page_size" => $record["page_size"],
            "is_default" => (bool)$record["is_default"],
            "status" => (bool)$record["is_active"],
            "created_at" => $record["created_at"],
            "updated_at" => $record["updated_at"]
        );
        http_response_code(200);
        $database->send_response(200, "success", $list, "Record retrieved successfully.");
    } else {
        http_response_code(404);
        $database->send_response(404, "failed", [], $record); // "not found"
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Unable to get records. Invalid request.");
}

