<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
include_once '../config/Database.php';
include_once '../class/Roles.php';

    $database = new Database();
    $db = $database->getConnection();
    $roles = new Roles($db);
    $result = $roles->read_all();
    $role_records = array();
    while($records = $result->fetch_assoc()) {
        $role_record = array(
            "id" => $records["role_id"],
            "name" => $records["role_name"]
        );
        $role_records[] = $role_record;
    }
    http_response_code(200);
$database->send_response(200, "success", $role_records, "Roles retrieved successfully.");
?>