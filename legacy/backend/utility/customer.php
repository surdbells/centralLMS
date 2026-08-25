<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
include_once '../config/Database.php';
include_once '../class/Customers.php';

$database = new Database();
$db = $database->getConnection();
$customer = new Customers($db);
    $result = $customer->read_all();
    $count = $result->num_rows;
    if ($count > 0) {
        $customer_records = array();
        while($records = $result->fetch_assoc()) {
            $customer_record = array(
                "id" => $records["customer_id"],
                "user" => $records["customer_id"],
                "token" => $records["token"],
                "name" => $records["first_name"] .  " " . $records["last_name"] ,
                "first_name" => $records["first_name"],
                "last_name" => $records["last_name"],
                "email" => $records["email"],
                "phone" => $records["phone"],
                "is_active" => (bool)$records["is_active"],
                "delivery_pref" => $records["delivery_pref"],
                "last_login" => $records["last_active"]
            );
            $customer_records[] = $customer_record;
        }
        http_response_code(200);
        $database->send_response(200, "success", $customer_records, "Customers retrieved successfully.");
    } else {
        http_response_code(200);
        $database->send_response(503, "failed", [], "Customers not found.");
    }