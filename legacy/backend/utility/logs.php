<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
include_once '../config/Database.php';
include_once '../class/Audits.php';

$database = new Database();
$db = $database->getConnection();
$logs = new Audits($db);
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->token) && !empty($data->id)) {
    $logs->user = $data->user;
    $result = $logs->read();
    $token_verification = $database->verify_host($data->token, $data->id);
    if ($token_verification == 100){
        $count = $result->num_rows;
        if ($count > 0) {
            $logs_records = array();
            while($records = $result->fetch_assoc()) {
                $logs_record = array(
                    "id" => $records["id"],
                    "user_id" => $records["user_id"],
                    "user" => $records["first_name"] . " " . $records["last_name"],
                    "action" => $records["action"],
                    "action_desc" => $records["action_desc"],
                    "status" => $records["status"],
                    "timestamp" => $database->format_date($records["timestamp"]),
                );
                $logs_records[] = $logs_record;
            }
            http_response_code(200);
            $database->send_response(200, "success", $logs_records, "logs retrieved successfully.");
        } else {
            http_response_code(200);
            $database->send_response(503, "failed", [], "logs not found.");
        }
    }else{
        http_response_code(200);
        $database->send_response(401, "failed", [], "Unable to get logs, Un-Authorized Request");
    }

} else {
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to get logs. Invalid request.");
}



?>