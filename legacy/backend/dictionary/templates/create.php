<?php
include_once '../../config/Database.php';
include_once '../../class/CertificateTemplates.php';
include_once '../../class/User.php';
$database = new Database();
$db = $database->getConnection();
$templates = new CertificateTemplates($db);
$user = new User($db);
$templates_records = array();
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) &&
    !empty($data->id) &&
    !empty($data->tenant_id) &&
    !empty($data->template_name) &&
    !empty($data->description) &&
    !empty($data->template_html) &&
    !empty($data->template_css)
) {
    $token_check = $database->verify_user($data->token, $data->id);
    if ($token_check != 100 || !$user->is_admin($data->id)) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized access.");
        exit;
    }
    $templates->template_name = $database->sanitize_input($data->template_name);
    $templates->description = $database->sanitize_input($data->description);
    $templates->template_html = $database->sanitize_input($data->template_html);
    $templates->template_css = $database->sanitize_input($data->template_css);
    if ($templates->create()) {
        http_response_code(200);
        $database->send_response(200, "success", [], "Template created successfully.");
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Unable to create template.");
    }

} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
