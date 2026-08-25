<?php
include_once '../config/Database.php';
include_once '../class/Users.php';
include_once '../class/OTPGenerator.php';
include_once '../class/EmailHandler.php';


$database = new Database();
$db = $database->getConnection();
$otpHandler = new OTPGenerator();
$email = new EmailHandler();
$otpData = $otpHandler->generateOTP();
$exist = false;
http_response_code(200);
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->token) && !empty($data->number)) {
$exist_query =  "SELECT * FROM users WHERE phone = ?";     # CHECK IF PHONE NUMBER EXIST
if($stmt = mysqli_prepare($db, $exist_query)){
        mysqli_stmt_bind_param($stmt, "s", $data->number);
        if(mysqli_stmt_execute($stmt)){
            $result = mysqli_stmt_get_result($stmt);
            if(mysqli_num_rows($result) > 0){ $exist = true; } else{ $exist = false; }
        }
    }
    http_response_code(200);
    if ($exist){
        $response = $email->send_otp($data->number, $data->token);
        echo $response;
    }else{
        $database->send_response(400, "failed", [], "Unable to validate, account not found.");
    }
}