<?php
include_once '../../config/Database.php';
include_once '../../class/Departments.php';
include_once '../../class/User.php';

$database = new Database();
$db = $database->getConnection();
$department = new Departments($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) && !empty($data->id) && !empty($data->tenant_id) && !empty($data->department_name) && !empty($data->description)) {
    $token_check = $database->verify_user($data->token, $data->id);
    if ($token_check != 100 || !$user->is_admin($data->id)) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized access.");
        exit;
    }
    $department->department_code = "DEP-".$database->getInitials($data->department_name);
    $department->department_name = $database->sanitize_input($data->department_name);
    $department->description = $database->sanitize_input($data->description);
    if ($department->create()) {
        http_response_code(200);
        $database->send_response(200, "success", [], "Department created successfully.");
    } else {
        http_response_code(500);
        $database->send_response(500, "failed", [], "Unable to create department.");
    }

} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
