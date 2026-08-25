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

$password = $confirm_password = "";
// Processing form data when form is submitted
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['reactivate_account'])){
  /*  try {
        if(isset($_POST['csrf_fmdq_token'])){
            $easyCSRF->check('fmdq_token', $_POST['csrf_fmdq_token'], 60 * 60, true);
        }else{
            $errors['fmdq_token_not_found'] = '<strong class="text-danger"> Failed! </strong>Invalid Request Token. Please, resubmit the form or try a different browser';
        }
    } catch(InvalidCsrfTokenException $e) {
        $errors['fmdq_token'] = $e->getMessage();
        $statusMsg = '<strong class="text-danger"> Failed! </strong>Invalid Request Token. Please, resubmit the form or try a different browser';
        $_SESSION['statusMsg'] = $statusMsg;
    } */


    #Sanitize Password
    if(isset($_POST['password'])){
        if(empty(trim($_POST["password"]))){
            $errors['password'] = 'Password cannot be empty';
        }else {
            $password = $_POST["password"];
            $password = mysqli_real_escape_string($link, $password);
            if(!preg_match("/^(?=.*\d)(?=.*[@#\-_$%^&+=§!?])(?=.*[a-z])(?=.*[A-Z])[0-9A-Za-z@#\-_$%^&+=§!?]{6,20}$/", $password)) {
                $errors['password'] = 'Password should be between 6 to 20 characters long, contains at least one special character, lowercase, uppercase and a digit.';
            }
            $password = $antiXss->xss_clean($password);
        }
    }else{$errors['password'] = 'Password cannot be empty';}


    #Validate Confirm Password
    if(isset($_POST['confirm_password']) && isset($_POST['password'])) {
        $confirm_password = $_POST["confirm_password"];
        $confirm_password = $antiXss->xss_clean($confirm_password);
        if(($password != $confirm_password)){
            $errors['confirm_password'] = 'Password did not match.';
        }
    }else{$errors['confirm_password'] = 'Password did not match.';}

    #Validate Confirm Password
    if(isset($_POST['token'])) {
        $token = $_POST["token"];
        $token = $antiXss->xss_clean($token);
    }else{$errors['confirm_token'] = 'Invalid Token Supplied';}




    // Check input errors before inserting in database
    if(count($errors) === 0){
        $password = password_hash($password, PASSWORD_DEFAULT);
        $sql = "UPDATE users SET user_password = ? WHERE user_token = ?";
        if($stmt = mysqli_prepare($link, $sql)){
            mysqli_stmt_bind_param($stmt, "ss", $param_password, $param_token);
            $param_password = $password;
            $param_token = $token;
            if(mysqli_stmt_execute($stmt)){
                $statusMsg = '<strong> Success! </strong>Your password has been changed successfully, proceed to login with your new password.';
                $_SESSION['statusMsg'] = $statusMsg;
            }
        }
        // Close statement
        mysqli_stmt_close($stmt);
    }
    // Close connection
    //mysqli_close($link);
}