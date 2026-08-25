<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
include_once '../config/Database.php';
include_once '../class/Users.php';
$user_record = array();
$database = new Database();
$db = $database->getConnection();
$users = new Users($db);
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->token) && !empty($data->id)){
    $token_verification = $database->verify_vendor($data->token, $data->id);
    $users->id = $database->sanitize_input($data->id);
    if ($token_verification == 100){
        $users->user_logout();
        http_response_code(200);
        $database->send_response(200, "success", $user_record, "User loggedOut successfully.");
    }
}else{
    http_response_code(200);
    $database->send_response(400, "failed", [], "Invalid request");
}
?>