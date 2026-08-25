<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
include_once '../config/Database.php';
include_once '../class/Departments.php';

    $database = new Database();
    $db = $database->getConnection();
    $departments = new Departments($db);
    $result = $departments->read_all();
    $departments_records = array();
    while($records = $result->fetch_assoc()) {
        $departments_record = array(
            "id" => $records["dept_id"],
            "name" => $records["dept_name"]
        );
        $departments_records[] = $departments_record;
    }
    http_response_code(200);
$database->send_response(200, "success", $departments_records, "Departments retrieved successfully.");
?>