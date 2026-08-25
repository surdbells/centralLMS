<?php
include_once '../../config/Database.php';
include_once '../../class/Teams.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$teams = new Teams($db);
$user = new User($db);
$list = array();
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) && !empty($data->id) && !empty($data->team_id)) {
    $token_check = $database->verify_user($data->token, $data->id);
    if ($token_check != 100 || !$user->is_admin($data->id)) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }
    $teams->id = $data->team_id;
    $result = $teams->read_single();
    $count = $result->num_rows;
    if ($count > 0) {
        while($records = $result->fetch_assoc()) {
            $list = array(
                "school" => $records["school_id"],
                "name" => $records["school_name"],
                "latitude" => $records["latitude"],
                "longitude" => $records["longitude"],
                "lga" => $records["lga_id"],
                "status" => (bool)$records["status"]
            );
        }
        http_response_code(200);
        $database->send_response(200, "success", $list, "record retrieved successfully.");
    } else {
        http_response_code(200);
        $database->send_response(503, "failed", [], "Record not found.");
    }
} else {
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to get records. Invalid request.");
}
?>