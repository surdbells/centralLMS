<?php
include_once '../../config/Database.php';
include_once '../../class/CertificateTemplate.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$template = new CertificateTemplate($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id) && !empty($data->template_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized access.");
        exit;
    }

    $template->id = intval($data->template_id);
    $template->tenant_id = intval($data->tenant_id);
    $template->template_name = $database->sanitize_input($data->template_name);
    $template->description = $database->sanitize_input($data->description);
    $template->template_html = $data->template_html;
    $template->template_css = $data->template_css;
    $template->orientation = $database->sanitize_input($data->orientation);
    $template->page_size = $database->sanitize_input($data->page_size);
    $template->is_default = intval($data->is_default);
    $template->is_active = intval($data->is_active);

    if ($template->update()) {
        http_response_code(200);
        $database->send_response(200, "success", [], "Certificate template updated successfully.");
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Unable to update certificate template.");
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>