<?php
include_once '../../config/Database.php';
include_once '../../class/Admin.php';
$database = new Database();
$db = $database->getConnection();
$admin = new Admin($db);
$store_records = array();
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->token) && !empty($data->id)) {
    $result = $admin->stores();
    $token_verification = $database->verify_user($data->token, $data->id);
    if ($token_verification == 100){
        $count = $result->num_rows;
        while($records = $result->fetch_assoc()) {
            $record = array(
                "id" => $records["user_id"],
                "token" => $records["token"],
                "store_name" => $records["store_name"],
                "store_email" => $records["store_email"],
                "store_phone" => $records["store_phone"],
                "store_address" => $records["store_address"],
                "last_login" => $records["last_login"],
                "sales" => $admin->total_vendor_orders($records["user_id"]),
                "status" => (bool)$records["store_status"],
                "approved" => (bool)$records["store_approved"],
            );
            $store_records[] = $record;
        }
        http_response_code(200);
        $database->send_response(
            200, "success", $store_records, 'success');
    }else{
        http_response_code(200);
        $database->send_response(401, "failed", [], "Unable to get data, Un-Authorized Request");
    }

} else {
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to get data. Invalid request.");
}
