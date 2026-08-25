<?php
include_once '../../../config/Database.php';
include_once '../../../class/Customer.php';
$database = new Database();
$db = $database->getConnection();
$customer = new Customer($db);
$record = array();
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->token) && !empty($data->id) && !empty($data->measurement)) {
    $result = $customer->read_measurement_by_id($data->measurement);
    $token_verification = $database->verify_vendor($data->token, $data->id);
    if ($token_verification == 100){
        $count = $result->num_rows;
        if ($count == 1) {
            while($records = $result->fetch_assoc()) {
                $record = array(
                    "id" => $data->id,
                    "token" => $data->token,
                    "measurement" => $records["m_id"],
                    "name" => $records["m_name"],
                    "unit" => $records["unit"],
                    "shoulderLength" => $records["shoulderLength"],
                    "armLength" => $records["armLength"],
                    "underarmLength" => $records["underarmLength"],
                    "chest" => $records["chest"],
                    "chestOpening" => $records["chestOpening"],
                    "height" => $records["height"],
                    "neckHigh" => $records["neckHigh"],
                    "wrist" => $records["wrist"],
                    "hips" => $records["hips"],
                    "belowHips" => $records["belowHips"],
                    "waist" => $records["waist"]
                );
            }
            http_response_code(200);
            $database->send_response(200, "success", $record,  "success");
        } else {
            http_response_code(200);
            $database->send_response(503, "failed", [], "No measurements found.");
        }
    }else{
        http_response_code(200);
        $database->send_response(401, "failed", [], "Un-Authorized Request");
    }

} else {
    http_response_code(200);
    $database->send_response(400, "failed", [], "Invalid request.");
}
