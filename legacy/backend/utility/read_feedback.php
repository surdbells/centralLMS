<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
include_once '../config/Database.php';
include_once '../class/Feedback.php';

$database = new Database();
$db = $database->getConnection();
$feedback = new Feedback($db);
$result = $feedback->read();
$feedbacks = array();
$count = $result->num_rows;
if ($count > 0) {
    while($records = $result->fetch_assoc()) {
        $feedback = array(
            "id" => $records["feedback_id"],
            "fn" => $records["fb_from_fn"],
            "ln" => $records["fb_from_ln"],
            "email" => $records["fb_from_email"],
            "phone" => $records["fb_from_phone"],
            "rating" => $records["fb_rating"],
            "improve" => $records["fb_improve"],
            "enjoy" => $records["fb_enjoy"]
        );
        $feedbacks[] = $feedback;
    }
    http_response_code(200);
    $database->send_response(200, "success", $feedbacks, "Feedbacks retrieved successfully.");
}else{
    http_response_code(200);
    $database->send_response(503, "failed", [], "No record found!");
}
?>