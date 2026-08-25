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
    $result = $branch->read_all();
    $branch_records = array();
    while($records = $result->fetch_assoc()) {
        $branch_record = array(
            "id" => $records["branch_id"],
            "name" => $records["branch_name"],
            "map" => $records["map_url"],
            "image" => $records["branch_image"],
            "location" => $records["location"],
            "contacts" => $records["contacts"],
            "latitude" => $records["latitude"],
            "longitude" => $records["longitude"],
            "status" => (bool)$records["_active"]
        );
        $branch_records[] = $branch_record;
    }
    http_response_code(200);
    $database->send_response(200, "success", $branch_records, "Branch retrieved successfully.");
?>