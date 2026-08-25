<?php
include_once '../config/Database.php';
include_once '../class/User.php';
$database = new Database();
$db = $database->getConnection();
$user = new User($db);
$department_records = array();
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) && !empty($data->id) && !empty($data->tenant_id)) {
    $user->tenant_id = $data->tenant_id;
    $result = $user->instructor();
    $token_verification = $database->verify_user($data->token, $data->id);
    if ($token_verification == 100){
        $count = $result->num_rows;
        if ($count > 0) {
            while($records = $result->fetch_assoc()) {
                $record = array(
                    "user_id" => $records["id"],
                    "user_name" => $records["first_name"] . " " . $records["last_name"],
                );
                $department_records[] = $record;
            }
            http_response_code(200);
            $database->send_response(200, "success", $department_records,  'Instructors retrieved successfully.');
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
