<?php
    header("Access-Control-Allow-Origin: *");
    header("Content-Type: application/json; charset=UTF-8");
    header("Access-Control-Allow-Methods: POST");
    header("Access-Control-Max-Age: 3600");
    header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
    include_once '../config/Database.php';
    include_once '../class/Menu.php';

    $database = new Database();
    $db = $database->getConnection();
    $menu = new Menu($db);
    $data = json_decode(file_get_contents("php://input"));

    $menu->category = $data->category;

    $result = $menu->read();
    $count = $result->num_rows;
    if ($count > 0) {
        $menus = array();
        while($record = $result->fetch_assoc()) {
            $a_menu = array(
                "id" => $record["menu_id"],
                "category" => $record["category_id"],
                "name" => $record["menu_name"],
                "image" => $record["menu_image"],
                "description" => $record["menu_description"],
                "price_formatted" => "₦" . number_format($record["price"] / 100, 2),
                "price" => $record["price"] / 100,
                "status" => (bool)$record["is_active"]
            );
            $menus[] = $a_menu;
        }
        http_response_code(200);
        $database->send_response(200, "success", $menus, "Menu retrieved successfully.");
    } else {
        http_response_code(200);
        $database->send_response(503, "failed", [], "Menu not found.");
}
