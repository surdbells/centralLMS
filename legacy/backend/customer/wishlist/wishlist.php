<?php
include_once '../../config/Database.php';
include_once '../../class/Generic.php';
$database = new Database();
$db = $database->getConnection();
$category = new Generic($db);
$category_records = array();
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) && !empty($data->id)) {
    $result = $category->category();
    $token_verification = $database->verify_vendor($data->token, $data->id);
    if ($token_verification == 100) {
        $count = $result->num_rows;
        if ($count > 0) {
            while($records = $result->fetch_assoc()) {
                $category_record = array(
                    "id" => $records["category_id"],
                    "icon" => $records["icon"],
                    "name" => $records["category_name"]
                );
                $category_records[] = $category_record;
            }
            http_response_code(200);
            $database->send_response(200, "success", $category_records, "success");
        } else {
            http_response_code(200);
            $database->send_response(503, "failed", [], "not found.");
        }
    } else {
        http_response_code(200);
        $database->send_response(401, "failed", [], "Un-Authorized Request");
    }

} else {
    http_response_code(200);
    $database->send_response(400, "failed", [], "Invalid request.");
}
