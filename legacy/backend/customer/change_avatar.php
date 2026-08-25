<?php
include_once '../config/Database.php';
include_once '../class/Generic.php';
include_once '../class/Customer.php';
$database = new Database();
$db = $database->getConnection();
$customer = new Customer($db);
$category_records = array();
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->token) && !empty($data->id)) {
    $token_verification = $database->verify_vendor($data->token, $data->id);
    http_response_code(200);
    if ($token_verification == 100){
        if($customer->update_avatar($data->first_name, $data->last_name, $data->email, $data->phone, $data->avatar, $data->id)){
            $database->send_response(200, "success", [], "avatar updated successfully.");
        } else {
            $database->send_response(503, "failed", [], "Unable to update.");
        }
    }else{
        $database->send_response(401, "failed", [], "Unable to update, Un-Authorized Request");
    }
} else {
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to update. Invalid request.");
}
?>