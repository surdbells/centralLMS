<?php
include_once '../../config/Database.php';
include_once '../../class/Vendor.php';
$database = new Database();
$db = $database->getConnection();
$hash_password = "";
$vendor = new Vendor($db);
$data = json_decode(file_get_contents("php://input"));
if(
    !empty($data->token) &&
    !empty($data->id) &&
    !empty($data->password) &&
    !empty($data->first_name) &&
    !empty($data->email)
) {
    $token_verification = $database->verify_user($data->token, $data->id);
    $hash_password = password_hash($data->password, PASSWORD_DEFAULT);
    http_response_code(200);
    if ($token_verification == 100){
        if($vendor->change_vendor_password($hash_password, $data->id)){
            $database->send_response(200, "success", [], "Password changed successfully.");
        } else {
            $database->send_response(503, "failed", [], "Unable to change password.");
        }
    }else{
        $database->send_response(401, "failed", [], "Unable to change password. Un-Authorized Request");
    }
} else {
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to change password. Invalid request.");
}