<?php
include_once '../config/Database.php';
include_once '../class/OTP.php';
include_once '../class/Users.php';
include_once '../class/EmailHandler.php';
$database = new Database();
$email = new EmailHandler();
$db = $database->getConnection();
$otpHandler = new OTP();
$users = new Users($db);
$isValid = false;
$otpData = $otpHandler->generateOTP();
$otp = $otpData['otp'];
$data = json_decode(file_get_contents("php://input"));
if(
    !empty($data->otp)  &&
    !empty($data->input_otp) &&
    !empty($data->expires_at) &&
    !empty($data->email)
){
    $otpHandler->otp = $database->sanitize_input($data->otp);
    $otpHandler->input_otp = $database->sanitize_input($data->input_otp);
    $otpHandler->expires_at = $database->sanitize_input($data->expires_at);

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

    $users->email = $database->sanitize_input($data->email);
    $users->is_active = true;
    $users->is_vendor = true;
    $isValid = $otpHandler->validateOTP($otpHandler->input_otp, $otpHandler->otp, $otpHandler->expires_at);
    http_response_code(200);
    if ($isValid){
        $users->activate_vendor();
        $email->get_started_notification($users->first_name, $users->email);
        $database->send_response(200, "success", [], "Vendor account activated successfully, proceed to login");
    }else{
        $database->send_response(400, "failed", [], "Unable to validate vendor.");
    }
}else{
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to validate vendor. Invalid request");
}
