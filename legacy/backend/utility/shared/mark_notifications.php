<?php
include_once '../../config/Database.php';
include_once '../../class/Notifications.php';
$database = new Database();
$db = $database->getConnection();
$notification = new Notifications($db);
$notification_records = array();
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->token) && !empty($data->id)) {
    $notification->user = $data->id;
    $notification->is_read = 1;
    $token_verification = $database->verify_user($data->token, $data->id);
    http_response_code(200);
    if ($token_verification == 100){
        if ($notification->is_read_all()){
            $database->send_response(200, "success", [],  "Notifications marked as read successfully.");
        }else {
            $database->send_response(503, "failed", [], "unable to complete");
        }
    }else{
        $database->send_response(401, "failed", [], "Un-Authorized Request");
    }
} else {
    http_response_code(200);
    $database->send_response(400, "failed", [], "Invalid request.");
}
