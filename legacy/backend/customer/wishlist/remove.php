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
    !empty($data->wishlist_id)
){
    $product->wishlist_id = $database->sanitize_input($data->wishlist_id);
    $token_verification = $database->verify_vendor($data->token, $data->id);
    http_response_code(200);
    if ($token_verification == 100){
        if($product->removewishlist()){
            $database->send_response(200, "success", [], "Product removed from wishlist successfully");
        } else{
            $database->send_response(200, "failed", [], "Unable to remove product.");
        }
    }else{
        $database->send_response(401, "failed", [], "Un-Authorized Request");
    }
}else{
    http_response_code(200);
    $database->send_response(400, "failed", [], "invalid request");
}
