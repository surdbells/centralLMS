<?php
include_once '../config/Database.php';
include_once '../class/Scorm.php';

$database = new Database();
$db = $database->getConnection();
$scorm = new Scorm($db);

$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id)) {

    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }

    if (empty($data->course_id)) {
        http_response_code(400);
        $database->send_response(400, "failed", [], "Course ID is required");
        exit;
    }

    $scorm->tenant_id = intval($data->tenant_id);
    $scorm->user_id = intval($data->user_id);
    $scorm->course_id = intval($data->course_id);

    $progress = $scorm->read_single();

    if ($progress) {
        $progress_json = json_decode($progress['data'], true);
        http_response_code(200);
        $database->send_response(200, "success", [
            "suspend_data" => $progress_json['cmi.suspend_data'] ?? '',
            "score" => $progress_json['cmi.core.score.raw'] ?? $progress_json['cmi.score.raw'] ?? 0,
            "completionStatus" => $progress_json['cmi.core.lesson_status'] ?? $progress_json['cmi.completion_status'] ?? 'incomplete',
            "successStatus" => $progress_json['cmi.success_status'] ?? 'unknown',
            "sessionTime" => $progress_json['cmi.core.session_time'] ?? $progress_json['cmi.session_time'] ?? 0
        ], "SCORM progress retrieved successfully");
    } else {
        http_response_code(200);
        $database->send_response(200, "success", [
            "suspend_data" => '',
            "score" => 0,
            "completionStatus" => 'incomplete',
            "successStatus" => 'unknown',
            "sessionTime" => 0
        ], "No SCORM progress found");
    }

} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>
