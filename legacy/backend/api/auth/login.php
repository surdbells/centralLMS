<?php
include_once '../config/Database.php';
include_once '../class/Users.php';
include_once '../class/EmailHandler.php';
$user_record = array();
$database = new Database();
$email = new EmailHandler();
$db = $database->getConnection();
$users = new Users($db);
$logged_in_user = "";
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->email) && !empty($data->password)){
    $users->email = $database->sanitize_input($data->email);
    $users->password = $database->sanitize_input($data->password);
    $result = $users->user_login();
    if ($result->num_rows > 0) {
        while($records = $result->fetch_assoc()) {
            $users->id = $records["user_id"];
            $users->first_name = $records["first_name"];
            $users->email = $records["email"];
            $user_record  = array(
                "id" => $records["user_id"],
                "token" => $records["token"],
                "first_name" => $records["first_name"],
                "last_name" => $records["last_name"],
                "email" => $records["email"],
                "phone" => $records["phone"],
                "avatar" => $records['avatar'] ??= 'assets/img/placeholder-1.png',
                "id_front" => $records['id_front'] ??= 'assets/img/placeholder-1.png',
                "id_back" => $records['id_back'] ??= 'assets/img/placeholder-1.png',
                "location" => $records['location'] ??= 'United Arab Emirates',
                "is_2fa" => (bool)$records["is_2fa"],
                "is_active" => (bool)$records["is_active"],
                "is_admin" => (bool)$records["is_admin"],
                "is_vendor" => (bool)$records["is_vendor"],
                "is_customer" => (bool)$records["is_customer"]
            );
        }
        $users->update_last_login();
        $email->login_notification($users->first_name, $users->email);
        http_response_code(200);
        $database->send_response(200, "success", $user_record, "User loggedIn successfully.");
    } else {
        http_response_code(200);
        $database->send_response(400, "failed", [], $result);
    }
}else{
    http_response_code(200);
    $database->send_response(400, "failed", [], "Invalid request");
}
?>