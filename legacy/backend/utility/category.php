<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
include_once '../config/Database.php';
include_once '../class/Generic.php';

    $database = new Database();
    $db = $database->getConnection();
    $category = new Generic($db);
    $result = $category->category();
    $category_records = array();
    while($records = $result->fetch_assoc()) {
        $category_record = array(
            "id" => $records["category_id"],
            "name" => $records["category_name"]
        );
        $category_records[] = $category_record;
    }
    http_response_code(200);
    $database->send_response(200, "success", $category_records, "Category retrieved successfully.");
?>