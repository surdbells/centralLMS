<?php
include_once '../../config/Database.php';
include_once '../../class/CertificateTemplates.php';
include_once '../../class/User.php';
$database = new Database();
$db = $database->getConnection();
$templates = new CertificateTemplates($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) &&
    !empty($data->id) &&
    !empty($data->template_id) &&
    !empty($data->template_name) &&
    !empty($data->description) &&
    !empty($data->template_html) &&
    !empty($data->template_css) &&
    !empty($data->orientation) &&
    !empty($data->page_size) &&
    !empty($data->is_default) &&
    !empty($data->is_active
    )) {
    $token_check = $database->verify_user($data->token, $data->id);
    if ($token_check != 100 || !$user->is_admin($data->id)) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized access.");
        exit;
    }
    $templates->id = intval($data->template_id);
    $templates->template_name = $database->sanitize_input($data->template_name);
    $templates->description = $database->sanitize_input($data->description);
    $templates->template_html = $database->sanitize_input($data->template_html);
    $templates->template_css = $database->sanitize_input($data->template_css);
    $templates->orientation = $database->sanitize_input($data->orientation);
    $templates->page_size = $database->sanitize_input($data->page_size);
    $templates->is_default = $database->sanitize_input($data->is_default);
    $templates->is_active = $database->sanitize_input($data->is_active);

    if ($templates->update()) {
        http_response_code(200);
        $database->send_response(200, "success", [], "Template updated successfully.");
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Unable to update branch.");
    }

} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
