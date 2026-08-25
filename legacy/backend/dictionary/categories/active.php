<?php
include_once '../../config/Database.php';
include_once '../../class/Category.php';
$database = new Database();
$db = $database->getConnection();
$category = new Category($db);
$category_records = array();
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) && !empty($data->id) && !empty($data->tenant_id)) {
    $category->tenant_id = $data->tenant_id;
    $result = $category->active();
    $token_verification = $database->verify_user($data->token, $data->id);
    if ($token_verification == 100){
        $count = $result->num_rows;
        if ($count > 0) {
            while($records = $result->fetch_assoc()) {
                $record = array(
                    "category_id" => $records["category_id"],
                    "category_name" => $records["name"]
                );
                $category_records[] = $record;
            }
            http_response_code(200);
            $database->send_response(200, "success", $category_records,  'Active categories retrieved successfully.');
        } else {
            http_response_code(200);
            $database->send_response(503, "failed", [], "not found.");
        }
    }else{
        http_response_code(200);
        $database->send_response(401, "failed", [], "Un-Authorized Request");
    }
} else {
    http_response_code(200);
    $database->send_response(400, "failed", [], "Invalid request.");
}
