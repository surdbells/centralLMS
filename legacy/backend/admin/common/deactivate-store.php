<?php
include_once '../../config/Database.php';
include_once '../../class/Admin.php';
$database = new Database();
$db = $database->getConnection();
$admin = new Admin($db);
$top_selling_products = array();
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->token)  && !empty($data->id)  && !empty($data->store)){
    $token_verification = $database->verify_user($data->token, $data->id);
    http_response_code(200);
    if ($token_verification == 100){
        if($admin->store_status($data->status, $data->store)){
            $database->send_response(200, "success", [], "Store suspended successfully");
        } else{
            $database->send_response(200, "failed", [], "Unable to complete");
        }
    }else{
        $database->send_response(401, "failed", [], "Un-Authorized Request");
    }
}else{
    http_response_code(200);
    $database->send_response(400, "failed", [], "Invalid request");
}
