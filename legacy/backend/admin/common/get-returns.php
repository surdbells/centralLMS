<?php
include_once '../../config/Database.php';
include_once '../../class/Admin.php';
$database = new Database();
$db = $database->getConnection();
$admin = new Admin($db);
$sales_records = array();
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->token) && !empty($data->id)) {
    $result = $admin->product_returns();
    $token_verification = $database->verify_user($data->token, $data->id);
    if ($token_verification == 100){
        $count = $result->num_rows;
        while($records = $result->fetch_assoc()) {
            $record = array(
                "id" => $records["ec_orders_id"],
                "code" => $records["code"],
                "product_name" => $records["product_name"],
                "customer_name" => $records["customer_name"],
                "customer_email" => $records["customer_email"],
                "amount" => number_format($records["amount"], 2),
                "payment_fee" => number_format($records["payment_fee"], 2),
                "shipping_amount" => number_format($records["shipping_amount"], 2),
                "payment_ref" => $records["payment_ref"],
                "status" => $records["status"]
            );
        }
        http_response_code(200);
        $database->send_response(
            200, "success", $sales_records, 'success');
    }else{
        http_response_code(200);
        $database->send_response(401, "failed", [], "Unable to get data, Un-Authorized Request");
    }

} else {
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to get data. Invalid request.");
}
