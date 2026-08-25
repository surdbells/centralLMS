<?php
include_once '../../config/Database.php';
include_once '../../class/Admin.php';
$database = new Database();
$db = $database->getConnection();
$admin = new Admin($db);
$customer_records = array();
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->token) && !empty($data->id)) {
    $result = $admin->customers();
    $token_verification = $database->verify_user($data->token, $data->id);
    if ($token_verification == 100){
        $count = $result->num_rows;
        while($records = $result->fetch_assoc()) {
            $record = array(
                "id" => $records["user_id"],
                "token" => $records["token"],
                "name" => $records["first_name"] . " " . $records["last_name"],
                "email" => $records["email"],
                "phone" => $records["phone"],
                "last_login" => $records["last_login"],
                "orders" => $admin->total_customer_orders($records["user_id"]),
                "status" => (bool)$records["is_active"]
            );
            $customer_records[] = $record;
        }
        http_response_code(200);
        $database->send_response(200, "success", $customer_records, 'success');
    }else{
        http_response_code(200);
        $database->send_response(401, "failed", [], "Unable to get data, Un-Authorized Request");
    }

} else {
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to get data. Invalid request.");
}
