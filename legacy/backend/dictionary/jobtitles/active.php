<?php
include_once '../../config/Database.php';
include_once '../../class/Departments.php';
include_once '../../class/JobTitles.php';
$database = new Database();
$db = $database->getConnection();
$title = new JobTitles($db);
$title_records = array();
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) && !empty($data->id) && !empty($data->tenant_id)) {
    $title->tenant_id = $data->tenant_id;
    $result = $title->active();
    $token_verification = $database->verify_user($data->token, $data->id);
    if ($token_verification == 100){
        $count = $result->num_rows;
        if ($count > 0) {
            while($records = $result->fetch_assoc()) {
                $record = array(
                    "jobTitle_id" => $records["job_title_id"],
                    "jobTitle_name" => $records["title_name"]
                );
                $title_records[] = $record;
            }
            http_response_code(200);
            $database->send_response(200, "success", $title_records,  'Active job titles retrieved successfully.');
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
