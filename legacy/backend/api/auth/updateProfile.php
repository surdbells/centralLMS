<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
 
include_once '../config/Database.php';
include_once '../class/Users.php';
 
$database = new Database();
$db = $database->getConnection();
 
$user = new Users($db);

$data = json_decode(file_get_contents("php://input"));

if(!empty($data->token) &&
    !empty($data->id)  &&
    !empty($data->first_name)  &&
    !empty($data->last_name)  &&
    !empty($data->email)  &&
    !empty($data->phone)  &&
    !empty($data->user)){
    $token_verification = $database->verify_host($data->token, $data->id);
    $user->id = $database->sanitize_input($data->user);
    $user->first_name = $database->sanitize_input($data->first_name);
    $user->last_name = $database->sanitize_input($data->last_name);
    $user->email = $database->sanitize_input($data->email);
    $user->phone = $database->sanitize_input($data->phone);
    if ($token_verification == 100){
        http_response_code(200);
        if($user->update_user_profile()){
            $database->send_response(200, "success", [], "Profile updated successfully");
        } else{
            $database->send_response(400, "failed", [], "Unable to update.");
        }
    }else{
        $database->send_response(401, "failed", $token_verification, "Unable to update, Un-Authorized Request");
    }


}else{
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to update. Data is incomplete.");
}
?>