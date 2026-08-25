<?php
include_once '../../config/Database.php';
include_once '../../class/Branches.php';
$database = new Database();
$db = $database->getConnection();
$branches = new Branches($db);
$branch_records = array();
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) && !empty($data->id) && !empty($data->tenant_id)) {
    $branches->tenant_id = $data->tenant_id;
    $result = $branches->active();
    $token_verification = $database->verify_user($data->token, $data->id);
    if ($token_verification == 100){
        $count = $result->num_rows;
        if ($count > 0) {
            while($records = $result->fetch_assoc()) {
                $record = array(
                    "branch_id" => $records["branch_id"],
                    "branch_name" => $records["branch_name"]
                );
                $branch_records[] = $record;
            }
            http_response_code(200);
            $database->send_response(200, "success", $branch_records,  'Active branch retrieved successfully.');
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
