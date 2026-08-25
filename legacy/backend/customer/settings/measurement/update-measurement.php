<?php
include_once '../../../config/Database.php';
include_once '../../../class/Customer.php';
$database = new Database();
$db = $database->getConnection();
$customer = new Customer($db);
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->token) && !empty($data->id)){
    $customer->id = $database->sanitize_input($data->id);
    $customer->arm = $database->sanitize_input($data->arm);
    $customer->bust = $database->sanitize_input($data->bust);
    $customer->hip = $database->sanitize_input($data->hip);
    $customer->length = $database->sanitize_input($data->length);
    $customer->neck = $database->sanitize_input($data->neck);
    $customer->waist = $database->sanitize_input($data->waist);
    $token_verification = $database->verify_vendor($data->token, $data->id);
    http_response_code(200);
    if ($token_verification == 100){
        if($customer->update_measurement($data->measurement)){
            $database->send_response(200, "success", [], "Measurement updated successfully");
        } else{
            $database->send_response(200, "failed", [], "Unable to update measurement.");
        }
    }else{
        $database->send_response(401, "failed", [], "Un-Authorized Request");
    }
}else{
    http_response_code(200);
    $database->send_response(400, "failed", [], "Invalid request");
}
