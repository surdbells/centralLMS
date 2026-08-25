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
    $result = $menu->read_exceptions();
    $exceptions = array();
    $count = $result->num_rows;
    if ($count > 0) {
        while($records = $result->fetch_assoc()) {
            $exception = array(
                "id" => $records["ex_id"],
                "menu" => $records["menu_name"],
                "branch" => $records["branch_name"]
            );
            $exceptions[] = $exception;
        }
        http_response_code(200);
        $database->send_response(200, "success", $exceptions, "Exceptions retrieved successfully.");
    }else{
        http_response_code(200);
        $database->send_response(503, "failed", [], "No record found!");
    }
?>