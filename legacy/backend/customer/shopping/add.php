<?php
include_once '../../config/Database.php';
include_once '../../class/Product.php';
include_once '../../class/Notifications.php';

$database = new Database();
$db = $database->getConnection();
$product = new Product($db);
$notify = new Notifications($db);
$data = json_decode(file_get_contents("php://input"));
if(
    !empty($data->token)  &&
    !empty($data->id)  &&
    !empty($data->product_id)
){
    $product->product_id = $database->sanitize_input($data->product_id);
    $product->quantity = $database->sanitize_input($data->quantity);
    $product->price = $data->price;
    $product->measurement = $database->sanitize_input($data->measurement);
    $product->color = $data->color;
    $product->user = $data->user;

    $token_verification = $database->verify_vendor($data->token, $data->id);
    http_response_code(200);
    if ($token_verification == 100){
        if($product->addcart()){
            $database->send_response(200, "success", [], "Product added to cart successfully");
        } else{
            $database->send_response(200, "failed", [], "Unable to complete");
        }
    }else{
        $database->send_response(401, "failed", [], "Unable to create product, Un-Authorized Request");
    }
}else{
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to create product. invalid request");
}
