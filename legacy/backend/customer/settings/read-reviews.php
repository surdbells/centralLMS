<?php
include_once '../../config/Database.php';
include_once '../../class/Customer.php';
$database = new Database();
$db = $database->getConnection();
$customer = new Customer($db);
$m_records = array();
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->token) && !empty($data->id)) {
    $customer->id = $data->id;
    $result = $customer->read_reviews();
    $token_verification = $database->verify_vendor($data->token, $data->id);
    if ($token_verification == 100){
        $count = $result->num_rows;
        if ($count > 0) {
            while($records = $result->fetch_assoc()) {
                $record = array(
                    "id" => $records["id"],
                    "store_name" => $records["store_name"],
                    "comment" => $records["comment"],
                    "star" => $records["star"]
                );
                $m_records[] = $record;
            }
            http_response_code(200);
            $database->send_response(200, "success", $m_records,  "success");
        } else {
            http_response_code(200);
            $database->send_response(503, "failed", [], "No billings found.");
        }
    }else{
        http_response_code(200);
        $database->send_response(401, "failed", [], "Un-Authorized Request");
    }

} else {
    http_response_code(200);
    $database->send_response(400, "failed", [], "Invalid request.");
}
