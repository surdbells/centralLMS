<?php
include_once '../config/Database.php';
include_once '../class/Customer.php';
$database = new Database();
$db = $database->getConnection();
$customer = new Customer($db);
$products = array();
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) && !empty($data->id)) {
    $result = $customer->store_latest_product($data->id);
    $token_verification = $database->verify_vendor($data->token, $data->id);
    if ($token_verification == 100) {
        $count = $result->num_rows;
        if ($count > 0) {
            while($records = $result->fetch_assoc()) {
                $product = array(
                    "id" => $data->id,
                    "token" => $data->token,
                    "product_id" => $records["product_id"],
                    "store_name" => $records["store_name"],
                    "store_description" => $records["store_description"],
                    "image_1" => $database->product_image_base . $records["image_1"]
                );
                $products[] = $product;
            }
            http_response_code(200);
            $database->send_response(200, "success", $products, "success");
        } else {
            http_response_code(200);
            $database->send_response(503, "failed", [], "not found.");
        }
    } else {
        http_response_code(200);
        $database->send_response(401, "failed", [], "Un-Authorized Request");
    }

} else {
    http_response_code(200);
    $database->send_response(400, "failed", [], "Invalid request.");
}
