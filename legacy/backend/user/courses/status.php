<?php
include_once '../../config/Database.php';
include_once '../../class/Course.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$course = new Course($db);
$user = new User($db);
$message = "";
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->id) && !empty($data->tenant_id) && !empty($data->course_id) && !empty($data->status)) {
    $token_check = $database->verify_user($data->token, $data->id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized access.");
        exit;
    }
    $course->id = intval($data->course_id);
    $course->tenant_id = intval($data->tenant_id);
    $course->status = $data->status;


    if ($data->status =='draft'){ $message = "Course unpublished successfully"; }
    if ($data->status =='delete'){ $message = "Course deleted successfully"; }
    if ($data->status =='published'){ $message = "Course published successfully"; }
    if ($data->status =='archived'){ $message = "Course archived successfully"; }

    if ($data->status == 'delete'){ $course->is_active = 0; }else{ $course->is_active = 1;}

    if ($course->status()) {
        http_response_code(200);
        $database->send_response(200, "success", [], $message);
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Unable to update course status.");
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
?>