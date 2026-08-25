<?php
include_once '../config/Database.php';
include_once '../class/Users.php';
include_once '../class/EmailHandler.php';
include_once '../class/OTP.php';

$database = new Database();
$db = $database->getConnection();
$otpHandler = new OTP();
$user = new Users($db);
$email = new EmailHandler();
$exist = false;
$otpData = $otpHandler->generateOTP();
$otp = $otpData['otp'];
$data = json_decode(file_get_contents("php://input"));
if(
    !empty($data->first_name)  &&
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
    $user->countryCode = $database->sanitize_input($data->countryCode);
    $user->business_name = $database->sanitize_input($data->business_name);
    $user->license_number = $database->sanitize_input($data->license_number);
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
        if($user->register_vendor()){
            $database->send_response(200, "success", $otpData, "Vendor account created successfully.");
        } else{
            $database->send_response(400, "failed", [], "Unable to create vendor.");
        }
    }else{
        $database->send_response(400, "failed", [], "Unable to create vendor, account already exists.");
    }
}else{
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to create user. invalid request");
}
