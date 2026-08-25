<?php
include_once '../config/Database.php';
include_once '../class/Customer.php';
$database = new Database();
$db = $database->getConnection();
$customer = new Customer($db);
$wishlists = array();
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) && !empty($data->id)) {
    $customer->id = $database->sanitize_input($data->id);
    $result = $customer->wishlist($data->label);
    $token_verification = $database->verify_vendor($data->token, $data->id);
    if ($token_verification == 100) {
        $count = $result->num_rows;
        if ($count > 0) {
        while($records = $result->fetch_assoc()) {
            $wishlist = array(
                "id" => $records["wishlist_id"],
                "product" => $records["product_id"],
                "name" => $records["product_name"],
                "image" => $database->product_image_base . $records["product_image"]
            );
            $wishlists[] = $wishlist;
        }
        http_response_code(200);
        $database->send_response(200, "success", $wishlists, "");
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
