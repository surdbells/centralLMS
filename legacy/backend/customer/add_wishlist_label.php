<?php
include_once '../config/Database.php';
include_once '../class/Customer.php';
$database = new Database();
$db = $database->getConnection();
$customer = new Customer($db);
$products = array();
$store_name = "";
$store_desc = "";
$data = json_decode(file_get_contents("php://input"));
if(
    !empty($data->token)  &&
    !empty($data->id)  &&
    !empty($data->label_name)
){
    $customer->id = $database->sanitize_input($data->id);
    $customer->label_name = $database->sanitize_input($data->label_name);
    $token_verification = $database->verify_vendor($data->token, $data->id);
    http_response_code(200);
    if ($token_verification == 100){
        if($customer->add_wishlist_label()){
            $database->send_response(200, "success", [], "Category created successfully");
        } else{
            $database->send_response(200, "failed", [], "Unable to add complete.");
        }
    }else{
        $database->send_response(401, "failed", [], "Un-Authorized Request");
    }
}else{
    http_response_code(200);
    $database->send_response(400, "failed", [], "invalid request");
}
