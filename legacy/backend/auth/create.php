<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
include_once '../config/Database.php';
include_once '../class/Users.php';

$database = new Database();
$raw_password = "";
$db = $database->getConnection();
$user = new Users($db);
$data = json_decode(file_get_contents("php://input"));
/*$subject = "Welcome to Sweet Sensation CRM";
$message = "We're thrilled to have you join our community! At Sweet Sensation, we're passionate about serving up delicious food and exceptional experiences.";
$message_two = "To access the CRM Log in with your personalized credentials provided below.";*/
if(
    !empty($data->first_name)  &&
    !empty($data->last_name) &&
    !empty($data->password) &&
    !empty($data->email)
){
    #$token_verification = $database->verify_host($data->token, $data->id);
    $token_verification = 100;
    $user->token = $database->generate_token();
    $user->first_name = $database->sanitize_input($data->first_name);
    $user->last_name = $database->sanitize_input($data->last_name);
    $user->email = $database->sanitize_input($data->email);
    $user->password =$database->sanitize_input($data->password);
    $raw_password = $user->password;

    $exist_query =  "SELECT * FROM users WHERE email = ?";     # CHECK IF EMAIL ADDRESS EXIST
    if($stmt = mysqli_prepare($db, $exist_query)){
        mysqli_stmt_bind_param($stmt, "s", $data->email);
        if(mysqli_stmt_execute($stmt)){
            $result = mysqli_stmt_get_result($stmt);
            if(mysqli_num_rows($result) > 0){
                http_response_code(200);
                $database->send_response(400, "failed", [], "An account already exist for this email address");
                return;
            }
        }
    }

    $user->phone = $database->sanitize_input($data->phone_number);
    $user->password = password_hash($raw_password, PASSWORD_DEFAULT);

    http_response_code(200);
    if ($token_verification == 100){
        if($user->create_user()){
           // $message_two = "Log in with your credentials at https://web.ssqsr.com.ng" . "<br><br>[ Email ] " . $user->email . "<br>[ Password ] " . $raw_password;
           // $email->notifications($user->email, $user->first_name, $subject, $subject, $message, $message_two);
            $database->send_response(200, "success", [], "User created");
        } else{
            $database->send_response(400, "failed", [], "Unable to create user.");
        }
    }else{
        $database->send_response(401, "failed", $token_verification, "Unable to create user, Un-Authorized Request");
    }
}else{
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to create user. Data is incomplete.");
}
?>