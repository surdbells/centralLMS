<?php
include_once '../../config/Database.php';
include_once '../../class/Certificate.php';

$database = new Database();
$db = $database->getConnection();
$certificate = new Certificate($db);
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->tenant_id) && !empty($data->verification_code)) {
    $certificate->tenant_id = intval($data->tenant_id);
    $certificate->verification_code = $database->sanitize_input($data->verification_code);
    $record = $certificate->verify_by_code();

    if (is_array($record)) {
        http_response_code(200);
        $database->send_response(200, "success", [
            "valid" => true,
            "certificate_number" => $record['certificate_number'],
            "course_title" => $record['course_title'],
            "learner_name" => $record['learner_first_name'] . ' ' . $record['learner_last_name'],
            "issued_at" => $record['issued_at']
        ], "Certificate is valid.");
    } else {
        http_response_code(404);
        $database->send_response(404, "failed", ["valid" => false], "Certificate not found or invalid.");
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>