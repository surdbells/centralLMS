<?php
if (str_replace(DIRECTORY_SEPARATOR, "/", __FILE__) == $_SERVER['SCRIPT_FILENAME']) {
    header("location: ../user-login");
}
# SECURITY MITIGATION MODULES - CSRF - XSS
require_once '../vendor/autoload.php';
include_once '../vendor/owasp/csrf-protector-php/libs/csrf/csrfprotector.php';
try {
    csrfProtector::init();
} catch (alreadyInitializedException $e) {
} catch (configFileNotFoundException $e) {
} catch (incompleteConfigurationException $e) {
}

use voku\helper\AntiXSS;
$antiXss = new AntiXSS();

# Define Data Variables
# Processing form data when form is submitted
if($_SERVER["REQUEST_METHOD"] == "GET"){
    $user_status = '';
    if(isset($_GET['token'])) {
        if (empty($_GET['token'])) {
            header("location: ./user-login");
        }else {
            $token = $_GET['token'];
            $token = $antiXss->xss_clean($token);
            $sql = "SELECT * FROM users WHERE user_token = ?";
            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "s", $param_token);
                $param_token = $token;
                if (mysqli_stmt_execute($stmt)) {
                    $result = mysqli_stmt_get_result($stmt);
                    if (mysqli_num_rows($result) == 1) {
                        $row = mysqli_fetch_array($result, MYSQLI_ASSOC);
                        $email_address = $row['email_address'];
                        $user_first_name = $row['name'];
                        $user_status = $row['user_status'];
                    } else {
                        $errors['Invalid_Token'] = 'The token is invalid, pls register..';
                    }
                }
            }
            if ($user_status == 'active'){
                $errors['confirm_exist'] = 'Your account is already confirmed.';
            } else {
                # check if account already exist
                $email_check_query =  "SELECT * FROM users WHERE user_token = ?";
                if($stmt = mysqli_prepare($link, $email_check_query)){
                    mysqli_stmt_bind_param($stmt, "s", $token);
                    $param_token = $token;
                    if(mysqli_stmt_execute($stmt)){
                        $result = mysqli_stmt_get_result($stmt);
                        $rowCount = mysqli_num_rows($result);
                        if($rowCount > 0){
                            if(count($errors) === 0){
                                # UPDATE USER ACCOUNT
                                $sql = "UPDATE users SET user_status = ? WHERE user_token = ? AND user_status = ?";
                                if($stmt = mysqli_prepare($link, $sql)){
                                    mysqli_stmt_bind_param($stmt, "sss", $param_statuss, $param_token, $param_status);
                                    $param_statuss = "active";
                                    $param_token = $token;
                                    $param_status = "pending";
                                    if(mysqli_stmt_execute($stmt)){
                                        // Records updated successfully. Redirect to landing page
                                        sendActivate($email_address, $user_first_name);
                                        $statusMsg = '<strong> Success! </strong>Your account has been confirmed and activated successfully, proceed to login.';
                                        $_SESSION['statusMsg'] = $statusMsg;
                                    }
                                }
                            }
                        } else {
                            $errors['confirm_exist'] = 'The token is invalid, pls register..';
                        }
                    }
                }
            }
        }
    }else{
        header("location: ../user-login");
    }
}