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
    $result = $notification->user_notifications();
    $notification_count = $notification->notification_count();
    $token_verification = $database->verify_user($data->token, $data->id);
    if ($token_verification == 100){
        $count = $result->num_rows;
        if ($count > 0) {
            while($records = $result->fetch_assoc()) {
                $record = array(
                    "id" => $records["notifications_id"],
                    "user" => $records["user_id"],
                    "message" => $records["notification_message"],
                    "is_read" => (bool)$records["is_read"],
                    "created" => $records["created"]
                );
                $notification_records[] = $record;
            }
            http_response_code(200);
            $database->send_response(200, "success", $notification_records,  $notification_count);
        } else {
            http_response_code(200);
            $database->send_response(503, "failed", [], "notifications not found.");
        }
    }else{
        http_response_code(200);
        $database->send_response(401, "failed", [], "Unable to get notifications, Un-Authorized Request");
    }
} else {
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to get notifications. Invalid request.");
}
