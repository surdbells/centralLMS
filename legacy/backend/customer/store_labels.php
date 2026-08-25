<?php
include_once '../config/Database.php';
include_once '../class/Customer.php';
$database = new Database();
$db = $database->getConnection();
$customer = new Customer($db);
$labels = array();
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) && !empty($data->id)) {
    $result = $customer->store_label($data->id);
    $token_verification = $database->verify_vendor($data->token, $data->id);
    if ($token_verification == 100) {
        $count = $result->num_rows;
        if ($count > 0) {
            while($records = $result->fetch_assoc()) {
                $label = array(
                    "id" => $data->id,
                    "token" => $data->token,
                    "label_id" => $records["vcl_id"],
                    "label" => $records["label"]
                );
                $labels[] = $label;
            }
            http_response_code(200);
            $database->send_response(200, "success", $labels, "success");
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
