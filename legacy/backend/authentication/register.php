<?php
include_once '../config/Database.php';
include_once '../class/Auth.php';
include_once '../class/EmailHandler.php';
include_once '../class/OTPGenerator.php';

$database = new Database();
$db = $database->getConnection();
$otpHandler = new OTPGenerator();
$user = new Auth($db);
$email = new EmailHandler();
$exist = false;
$otpData = $otpHandler->generateOTP();
$otp = $otpData['otp'];
$data = json_decode(file_get_contents("php://input"));
if( !empty($data->first_name)  &&
    !empty($data->last_name) &&
    !empty($data->email) &&
    !empty($data->phone) &&
    !empty($data->password)
){
    $user->token = $database->generate_token();
    $user->first_name = $database->sanitize_input($data->first_name);
    $user->last_name = $database->sanitize_input($data->last_name);
    $user->email = $database->sanitize_input($data->email);
    $user->password =$database->sanitize_input($data->password);
    $user->phone = $database->sanitize_input($data->phone);
    $user->password = password_hash($data->password, PASSWORD_DEFAULT);

    $exist_query =  "SELECT * FROM users WHERE email = ?";     # CHECK IF EMAIL ADDRESS EXIST
    if($stmt = mysqli_prepare($db, $exist_query)){
        mysqli_stmt_bind_param($stmt, "s", $data->email);
        if(mysqli_stmt_execute($stmt)){
            $result = mysqli_stmt_get_result($stmt);
            if(mysqli_num_rows($result) > 0){ $exist = true; } else{ $exist = false; }
        }
    }
    http_response_code(200);
    if (!$exist){
        if($user->register_learner()){
            $user->email = $database->sanitize_input($data->email);
            $user->is_active = 1;
            $user->is_learner = 1;
            $user->activate_account();
            $email->get_started_notification($user->first_name, $user->email);
            $database->send_response(200, "success", $otpData, "Account created and activated successfully.");
        } else{
            $database->send_response(400, "failed", [], "Unable to create account.");
        }
    }else{
        $database->send_response(400, "failed", [], "Unable to create, account already exists with the email address or phone number");
    }
}else{
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to create. invalid request");
}
