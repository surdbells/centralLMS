<?php
include_once '../../config/Database.php';
include_once '../../class/Admin.php';
include_once '../../class/Generic.php';
$database = new Database();
$db = $database->getConnection();
$admin = new Admin($db);
$generic = new Generic($db);

$top_selling_products = array();
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->token) && !empty($data->id)) {
    $order_delivered = "delivered";
    $result = $admin->top_selling_products($order_delivered);
    $token_verification = $database->verify_user($data->token, $data->id);
    if ($token_verification == 100){
        $count = $result->num_rows;
        while($records = $result->fetch_assoc()) {
            $record = array(
                "name" => $records["name"],
                "order_count" => $records["order_count"],
                "image" => "https://api.3bayti.com/vendors/products/" . $records["image_1"],
            );
            $top_selling_products[] = $record;
        }
        http_response_code(200);
        $database->send_response(
            200, "success", $top_selling_products, 'success');
    }else{
        http_response_code(200);
        $database->send_response(401, "failed", [], "Unable to get data, Un-Authorized Request");
    }

} else {
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to get data. Invalid request.");
}
