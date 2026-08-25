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
    $collections = new Generic($db);
    $result = $collections->collections();
    $collections_records = array();
    while($records = $result->fetch_assoc()) {
        $record = array(
            "id" => $records["collections_id"],
            "collection" => $records["collection_name"],
        );
        $collections_records[] = $record;
    }
    http_response_code(200);
    $database->send_response(200, "success", $collections_records, "Collection retrieved successfully.");
?>