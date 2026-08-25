<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
include_once '../config/Database.php';
include_once '../class/Status.php';

    $database = new Database();
    $db = $database->getConnection();
    $status = new Status($db);
    $result = $status->read_all();
    $status_records = array();
    while($records = $result->fetch_assoc()) {
        $status_record = array(
            "id" => $records["status_id"],
            "name" => $records["status_name"]
        );
        $status_records[] = $status_record;
    }
    http_response_code(200);
$database->send_response(200, "success", $status_records, "Status retrieved successfully.");
?>