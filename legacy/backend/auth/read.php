<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
include_once '../config/Database.php';
include_once '../class/Users.php';

$database = new Database();
$db = $database->getConnection();
$users = new Users($db);
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->token) && !empty($data->id)) {
    $users->id = $data->user;
    $users->is_active = $data->branch;
    $users->is_customer = $data->is_customer;
    $users->is_vendor = $data->is_vendor;
    $users->is_admin = $data->is_admin;
    $result = $users->read();
    $token_verification = $database->verify_host($data->token, $data->id);
    if ($token_verification == 100){
        $count = $result->num_rows;
        if ($count > 0) {
            $user_records = array();
            while($records = $result->fetch_assoc()) {
                $user_record = array(
                    "id" => $records["user_id"],
                    "user" => $records["user_id"],
                    "token" => $records["token"],
                    "first_name" => $records["first_name"],
                    "last_name" => $records["last_name"],
                    "email" => $records["user_email"],
                    "phone" => $records["user_phone"],
                    "role" => $records["role_name"],
                    "role_id" => $records["user_role"],
                    "branch" => $records["branch_name"],
                    "branch_id" => $records["branch"],
                    "department" => $records["dept_name"],
                    "department_id" => $records["department"],
                    "is_super" => (bool)$records["is_super"],
                    "is_2fa" => (bool)$records["is_2fa"],
                    "is_active" => (bool)$records["is_active"],
                    "last_login" => $records["last_login"]
                );
                $user_records[] = $user_record;
            }
            http_response_code(200);
            $database->send_response(200, "success", $user_records, "Users retrieved successfully.");
        } else {
            http_response_code(200);
            $database->send_response(503, "failed", [], "Users not found.");
        }
    }else{
        http_response_code(200);
        $database->send_response(401, "failed", [], "Unable to get users, Un-Authorized Request");
    }

} else {
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to get users. Invalid request.");
}
?>