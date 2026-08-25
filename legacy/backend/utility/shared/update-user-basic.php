<?php
include_once '../../config/Database.php';
include_once '../../class/Vendor.php';
$database = new Database();
$db = $database->getConnection();
$vendor = new Vendor($db);
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->token) &&
    !empty($data->id)
) {
    $token_verification = $database->verify_user($data->token, $data->id);
    http_response_code(200);
    if ($token_verification == 100){
        if($vendor->update_vendor_user_basic($data->first_name, $data->last_name, $data->email, $data->phone, $data->avatar, $data->id)){
            $database->send_response(200, "success", [], "Profile updated successfully.");
        } else {
            $database->send_response(503, "failed", [], "Unable to update profile.");
        }
    }else{
        $database->send_response(401, "failed", [], "Unable to update profile, Un-Authorized Request");
    }
} else {
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to update profile. Invalid request.");
}
?>