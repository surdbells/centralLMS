<?php
include_once '../config/Database.php';
include_once '../class/OTP.php';
include_once '../class/EmailHandler.php';
include_once '../class/Users.php';

$database = new Database();
$db = $database->getConnection();
$otpHandler = new OTP();
$users = new Users($db);
$email = new EmailHandler();
$exist = false;
$otpData = $otpHandler->generateOTP();
$otp = $otpData['otp'];
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->email)){
    $users->email = $database->sanitize_input($data->email);
    $users->first_name = '';
    $exist_query =  "SELECT user_id, email, first_name FROM users WHERE email = ?";     # CHECK IF EMAIL ADDRESS EXIST
    if($stmt = mysqli_prepare($db, $exist_query)){
        mysqli_stmt_bind_param($stmt, "s", $data->email);
        if(mysqli_stmt_execute($stmt)){
            $result = mysqli_stmt_get_result($stmt);
            if(mysqli_num_rows($result) > 0){
                $exist = true;
                $row = mysqli_fetch_assoc($result);
                $users->first_name = $row['first_name'];
            } else{
                $exist = false;

            }
        }
    }
    http_response_code(200);
    if ($exist){
        $email->send_otp($users->first_name, $otp, $users->email);
        $database->send_response(200, "success", $otpData, "account validated successfully");
    }else{
        $database->send_response(400, "failed", [], "Unable to validate account.");
    }
}else{
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to validate vendor. Invalid request");
}
