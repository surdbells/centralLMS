<?php
include_once '../../config/Database.php';
include_once '../../class/Vendor.php';
$database = new Database();
$db = $database->getConnection();
$vendor = new Vendor($db);
$record = array();
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->token) && !empty($data->id)) {
    $result = $vendor->user_basic($data->id);
    $token_verification = $database->verify_user($data->token, $data->id);
    if ($token_verification == 100){
        $count = $result->num_rows;
        if ($count > 0) {
            while($records = $result->fetch_assoc()) {
                $record = array(
                    "id" => $records["user_id"],
                    "token" => $records["token"],
                    "first_name" => $records["first_name"],
                    "last_name" => $records["last_name"],
                    "avatar" => $records['avatar'] ??= 'assets/img/placeholder-1.png',
                    "email" => $records["email"],
                    "phone" => $records["phone"]
                );
            }
            http_response_code(200);
            $database->send_response(200, "success", $record, "Retrieved successfully.");
        } else {
            http_response_code(200);
            $database->send_response(503, "failed", [], "Data not found.");
        }
    }else{
        http_response_code(200);
        $database->send_response(401, "failed", [], "Unable to get data, Un-Authorized Request");
    }

} else {
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to get data. Invalid request.");
}
