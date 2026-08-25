<?php
include_once '../../config/Database.php';
include_once '../../class/CertificateTemplate.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$template = new CertificateTemplate($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }

    $template->tenant_id = intval($data->tenant_id);
    $template->page = intval($data->page ?? 1);
    $template->per_page = intval($data->per_page ?? 20);
    $template->sort_by = $data->sort_by ?? 'template_id';
    $template->sort_dir = $data->sort_dir ?? 'desc';
    $template->is_active = $data->is_active ?? 1;

    $result = $template->list();

    http_response_code(200);
    $database->send_response(200, "success", [
        "templates" => $result['data'],
        "meta" => $result['meta'],
        "links" => $user->buildPaginationLinks($result['meta']['page'], $result['meta']['per_page'], $result['meta']['total_pages'])
    ], "Certificate templates retrieved successfully");
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>