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
    !empty($data->user)  &&
    !empty($data->password)
) {
    $token_verification = $database->verify_host($data->token, $data->id);
    $user->id = $database->sanitize_input($data->user);
    $user->password = $database->sanitize_input($data->password);
    $user->password = password_hash($user->password, PASSWORD_DEFAULT);
    if ($token_verification == 100){
        http_response_code(200);
        if($user->change_password()){
            $database->send_response(200, "success", [], "Password updated successfully");
        } else{
            $database->send_response(400, "failed", [], "Unable to update password.");
        }
    }else{
        $database->send_response(401, "failed", $token_verification, "Unable to update password, Un-Authorized Request");
    }
}else{
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to update password. Data is incomplete.");
}
?>