<?php
include_once '../../config/Database.php';
include_once '../../class/Customer.php';
$database = new Database();
$db = $database->getConnection();
$customer = new Customer($db);
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->token) && !empty($data->id) && !empty($data->name)){
    $customer->id = $database->sanitize_input($data->id);
    $customer->m_name = $database->sanitize_input($data->name);
    $customer->unit = $database->sanitize_input($data->unit);
    $customer->shoulderLength = $database->sanitize_input($data->shoulderLength);
    $customer->armLength = $database->sanitize_input($data->armLength);
    $customer->underarmLength = $database->sanitize_input($data->underarmLength);
    $customer->chest = $database->sanitize_input($data->chest);
    $customer->chestOpening = $database->sanitize_input($data->chestOpening);
    $customer->height = $database->sanitize_input($data->height);
    $customer->neckHigh = $database->sanitize_input($data->neckHigh);
    $customer->wrist = $database->sanitize_input($data->wrist);
    $customer->hips = $database->sanitize_input($data->hips);
    $customer->belowHips = $database->sanitize_input($data->belowHips);
    $customer->waist = $database->sanitize_input($data->waist);
    $token_verification = $database->verify_vendor($data->token, $data->id);
    http_response_code(200);
    if ($token_verification == 100){
        if($customer->create_measurement()){
            $database->send_response(200, "success", [], "Measurement created successfully");
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
