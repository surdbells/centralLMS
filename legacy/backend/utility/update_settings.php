<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
include_once '../config/Database.php';
include_once '../class/Branches.php';

$database = new Database();
$db = $database->getConnection();
$branch = new Branches($db);
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->shipping_fee) && !empty($data->packaging_fee)){
    $branch->charge_delivery = $data->charge_delivery;
    $branch->charge_packaging = $data->charge_packaging;
    $branch->accept_order = $data->accept_order;

    $token_verification = $database->verify_host($data->token, $data->id);
    http_response_code(200);
    if ($token_verification == 100){
        if($branch->update_settings($data->shipping_fee, $data->packaging_fee)){
            $database->send_response(200, "success", [], "Settings Updated successfully.");
        } else {
            $database->send_response(503, "failed", [], "Unable to Update settings.");
        }
    }else{
        $database->send_response(401, "failed", [], "Unable to Update settings, Un-Authorized Request");
    }
} else {
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to Update settings. Invalid request.");
}
?>