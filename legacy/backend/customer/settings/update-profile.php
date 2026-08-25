<?php
include_once '../../config/Database.php';
include_once '../../class/Customer.php';
$database = new Database();
$db = $database->getConnection();
$customer = new Customer($db);
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->token) && !empty($data->id)){
    $customer->first_name = $database->sanitize_input($data->first_name);
    $customer->last_name = $database->sanitize_input($data->last_name);
    $customer->location = $database->sanitize_input($data->location);
    $customer->phone = $database->sanitize_input($data->phone);
    $customer->id = $database->sanitize_input($data->id);
    $token_verification = $database->verify_vendor($data->token, $data->id);
    http_response_code(200);
    if ($token_verification == 100){
        if($customer->update_profile($data->countryCode)){
            $database->send_response(200, "success", [], "profile updated successfully");
        } else{
            $database->send_response(200, "failed", [], "Unable to update.");
        }
    }else{
        $database->send_response(401, "failed", [], "Un-Authorized Request");
    }
}else{
    http_response_code(200);
    $database->send_response(400, "failed", [], "Invalid request");
}
