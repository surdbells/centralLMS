<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
include_once '../config/Database.php';
include_once '../class/Menu.php';

    $database = new Database();
    $db = $database->getConnection();
    $menu = new Menu($db);
    $result = $menu->read_all();
    $menu_records = array();
    while($records = $result->fetch_assoc()) {
        $menu_record = array(
            "id" => $records["menu_id"],
            "name" => $records["menu_name"]
        );
        $menu_records[] = $menu_record;
    }
    http_response_code(200);
$database->send_response(200, "success", $menu_records, "Menu retrieved successfully.");
?>