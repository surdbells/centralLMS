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
    $product->store_id = $database->sanitize_input($data->id);
    $product->category_id = $database->sanitize_input($data->category);
    $product->name = $database->sanitize_input($data->name);
    $product->description = $data->description;
    try {
        $product->image_1 = strlen($data->image_1) > 50 ? $database->save_base64_image_to_directory($data->image_1) : $data->image_1;
        $product->images = count($data->images) != 0 ? $database->save_images_array_json($data->images) : json_encode($data->images, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {}

       $notify->created = $database->getDubaiMySQLTimestamp();
    $notify->user = $data->id;
    $notify->message = "Your product has been created successfully";

    $token_verification = $database->verify_vendor($data->token, $data->id);
    http_response_code(200);
    if ($token_verification == 100){
        if(){
            $database->send_response(200, "success", [], "Product added to cart successfully");
        } else{
            $database->send_response(200, "failed", [], "Unable to create product.");
        }
    }else{
        $database->send_response(401, "failed", [], "Unable to create product, Un-Authorized Request");
    }
}else{
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to create product. invalid request");
}
