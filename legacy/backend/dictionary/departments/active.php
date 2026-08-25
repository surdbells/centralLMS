<?php
include_once '../../config/Database.php';
include_once '../../class/Departments.php';
include_once '../../class/User.php';
$database = new Database();
$db = $database->getConnection();
$department = new Departments($db);
$department_records = array();
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) && !empty($data->id) && !empty($data->tenant_id)) {
    $department->tenant_id = $data->tenant_id;
    $result = $department->active();
    $token_verification = $database->verify_user($data->token, $data->id);
    if ($token_verification == 100){
        $count = $result->num_rows;
        if ($count > 0) {
            while($records = $result->fetch_assoc()) {
                $record = array(
                    "department_id" => $records["department_id"],
                    "department_name" => $records["department_name"]
                );
                $department_records[] = $record;
            }
            http_response_code(200);
            $database->send_response(200, "success", $department_records,  'Active departments retrieved successfully.');
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
