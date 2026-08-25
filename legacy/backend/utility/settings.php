<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
include_once '../config/Database.php';
include_once '../class/Branches.php';

    $database = new Database();
    $db = $database->getConnection();
    $branch = new Branches($db);
    $result = $branch->read_settings();
    $settings = array();
    while($records = $result->fetch_assoc()) {
        $settings = array(
            "charge_delivery" => (bool)$records["charge_delivery"],
            "delivery_fee" => $records["delivery_fee"],
            "charge_packaging" => (bool)$records["charge_packaging"],
            "packaging_fee" => $records["packaging_fee"],
            "accept_order" => $records["accept_order"],
        );
    }
    http_response_code(200);
    $database->send_response(200, "success", $settings, "Settings retrieved successfully.");
?>