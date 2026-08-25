<?php
include_once '../config/Database.php';
include_once '../class/Customer.php';
$database = new Database();
$db = $database->getConnection();
$customer = new Customer($db);
$labels = array();
$store_name = "";
$store_desc = "";
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) && !empty($data->id)) {
    $customer->id = $database->sanitize_input($data->id);
    $result = $customer->wishlist_label();
    $token_verification = $database->verify_vendor($data->token, $data->id);
    $label = array(
        "id" => 2,
        "name" => 'Favorite',
        "count" => $customer->total_wishlist_label(2)
    );
    $labels[] = $label;
    if ($token_verification == 100) {
        $count = $result->num_rows;
        while($records = $result->fetch_assoc()) {
            $label = array(
                "id" => $records["wishll_id"],
                "name" => $records["label_name"],
                "count" => $customer->total_wishlist_label($records["wishll_id"])
            );
            $labels[] = $label;
        }
        http_response_code(200);
        $database->send_response(200, "success", $labels, "");
    } else {
        http_response_code(200);
        $database->send_response(401, "failed", [], "Un-Authorized Request");
    }

} else {
    http_response_code(200);
    $database->send_response(400, "failed", [], "Invalid request.");
}
