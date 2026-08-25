<?php
include_once '../../../config/Database.php';
include_once '../../../class/Customer.php';
$database = new Database();
$db = $database->getConnection();
$customer = new Customer($db);
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->token) && !empty($data->id) && !empty($data->name)){
    $customer->id = $database->sanitize_input($data->id);
    $customer->name = $database->sanitize_input($data->name);
    $customer->phone = $database->sanitize_input($data->phone);
    $customer->email = $database->sanitize_input($data->email);
    $customer->city = $database->sanitize_input($data->city);
    $customer->area = $database->sanitize_input($data->area);
    $customer->street = $database->sanitize_input($data->street);
    $token_verification = $database->verify_vendor($data->token, $data->id);
    http_response_code(200);
    if ($token_verification == 100){
        if($customer->create_billing()){
            $database->send_response(200, "success", [], "Billing addresses created successfully");
        } else{
            $database->send_response(200, "failed", [], "Unable to create.");
        }
    }else{
        $database->send_response(401, "failed", [], "Un-Authorized Request");
    }
}else{
    http_response_code(200);
    $database->send_response(400, "failed", [], "Invalid request");
}
