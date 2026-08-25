<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
include_once '../config/Database.php';
include_once '../class/Feedback.php';

$database = new Database();
$db = $database->getConnection();
$feedback = new Feedback($db);
$data = json_decode(file_get_contents("php://input"));

if(
    !empty($data->first_name) &&
    !empty($data->last_name) &&
    !empty($data->email) &&
    !empty($data->phone) &&
    !empty($data->enjoy) &&
    !empty($data->improve) &&
    !empty($data->rating) &&
    !empty($data->branch)
){
    $feedback->fn = $database->sanitize_input($data->first_name);
    $feedback->ln = $database->sanitize_input($data->last_name);
    $feedback->email = $database->sanitize_input($data->email);
    $feedback->phone = $database->sanitize_input($data->phone);
    $feedback->rating = $database->sanitize_input($data->rating);
    $feedback->enjoy = $database->sanitize_input($data->enjoy);
    $feedback->improve = $database->sanitize_input($data->improve);
    $feedback->branch = $database->sanitize_input($data->branch);
    http_response_code(200);
    if($feedback->create()){
        $database->send_response(200, "success", [], "Your feedback has been received.");
    } else{
        $database->send_response(400, "failed", [], "Unable to submit feedback, bad request.");
    }
}else{
    http_response_code(200);
    $database->send_response(400, "success", [], "Unable to submit feedback, data is incomplete.");
}
?>