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

if(
    !empty($data->token) &&
    !empty($data->id)  &&
    !empty($data->user_id) &&
    !empty($data->status)
)
{
    $token_verification = $database->verify_host($data->token, $data->id);
    $user->is_active = $database->sanitize_input($data->status);
    $user->id = $database->sanitize_input($data->user_id);
    if ($token_verification == 100){
        http_response_code(200);
        if($user->change_status()){
            $database->send_response(200, "success", [], "User updated successfully");
        } else{
            $database->send_response(400, "failed", [], "Unable to update user.");
        }
    }else{
        $database->send_response(401, "failed", $token_verification, "Unable to update user, Un-Authorized Request");
    }


}else{
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to update user. Data is incomplete.");
}
?>