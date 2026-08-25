<?php
include_once '../config/Database.php';
include_once '../class/Auth.php';
include_once '../class/EmailHandler.php';
include_once '../class/OTPGenerator.php';
include_once '../class/User.php';

$database = new Database();
$db = $database->getConnection();
$otpHandler = new OTPGenerator();
$user = new User($db);
$email = new EmailHandler();
$exist = false;
$data = json_decode(file_get_contents("php://input"));
if( !empty($data->id)  && !empty($data->tenant_id) && !empty($data->token)){
    $user->token = $database->generate_token();
    $user->first_name = $database->sanitize_input($data->first_name);
    $user->last_name = $database->sanitize_input($data->last_name);
    $user->email = $database->sanitize_input($data->email);
    $user->branch = $database->sanitize_input($data->branch);
    $user->department = $database->sanitize_input($data->department);
    $user->level = $database->sanitize_input($data->level);
    $user->team = $database->sanitize_input($data->team);
    $user->job_title = $database->sanitize_input($data->job_title);
    $user->tenant_id = $database->sanitize_input($data->tenant_id);
    $user->is_active = $database->sanitize_input($data->is_active);
    $user->is_admin = $database->sanitize_input($data->is_admin);
    $user->is_content = $database->sanitize_input($data->is_content);
    $user->is_hr = $database->sanitize_input($data->is_hr);
    $user->is_instructor = $database->sanitize_input($data->is_instructor);
    $user->is_learner = $database->sanitize_input($data->is_learner);
    $user->password = $database->generate_password(12);
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
        if($user->create($user->password)){
            $user->email = $database->sanitize_input($data->email);
            $email->onboarding_notification($user->first_name, $user->email, $user->password);
            $database->send_response(200, "success", '', "User created and activated successfully.");
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
