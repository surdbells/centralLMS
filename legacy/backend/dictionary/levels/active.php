<?php
include_once '../../config/Database.php';
include_once '../../class/Levels.php';
$database = new Database();
$db = $database->getConnection();
$level = new Levels($db);
$level_records = array();
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) && !empty($data->id) && !empty($data->tenant_id)) {
    $level->tenant_id = $data->tenant_id;
    $result = $level->active();
    $token_verification = $database->verify_user($data->token, $data->id);
    if ($token_verification == 100){
        $count = $result->num_rows;
        if ($count > 0) {
            while($records = $result->fetch_assoc()) {
                $record = array(
                    "level_id" => $records["level_id"],
                    "level_name" => $records["level_name"]
                );
                $level_records[] = $record;
            }
            http_response_code(200);
            $database->send_response(200, "success", $level_records,  'Active levels retrieved successfully.');
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
