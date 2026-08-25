<?php
include_once '../../config/Database.php';
include_once '../../class/CertificateTemplate.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$template = new CertificateTemplate($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id) && !empty($data->template_name) && !empty($data->template_html)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized access.");
        exit;
    }

    $template->tenant_id = intval($data->tenant_id);
    $template->template_name = $database->sanitize_input($data->template_name);
    $template->description = $database->sanitize_input($data->description ?? '');
    $template->template_html = $data->template_html; // Don't sanitize HTML template
    $template->template_css = $data->template_css ?? '';
    $template->orientation = $database->sanitize_input($data->orientation ?? 'landscape');
    $template->page_size = $database->sanitize_input($data->page_size ?? 'A4');
    $template->is_default = intval($data->is_default ?? 0);

    if ($template->create()) {
        http_response_code(200);
        $database->send_response(200, "success", [], "Certificate template created successfully.");
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Unable to create certificate template.");
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>