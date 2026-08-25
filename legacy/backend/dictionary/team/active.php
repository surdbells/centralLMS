<?php
include_once '../../config/Database.php';
include_once '../../class/Teams.php';
$database = new Database();
$db = $database->getConnection();
$teams = new Teams($db);
$teams_records = array();
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) && !empty($data->id) && !empty($data->tenant_id)) {
    $teams->tenant_id = $data->tenant_id;
    $result = $teams->active();
    $token_verification = $database->verify_user($data->token, $data->id);
    if ($token_verification == 100){
        $count = $result->num_rows;
        if ($count > 0) {
            while($records = $result->fetch_assoc()) {
                $record = array(
                    "team_id" => $records["team_id"],
                    "team_name" => $records["team_name"]
                );
                $teams_records[] = $record;
            }
            http_response_code(200);
            $database->send_response(200, "success", $teams_records,  'Active team retrieved successfully.');
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
