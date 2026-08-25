<?php
if (str_replace(DIRECTORY_SEPARATOR, "/", __FILE__) == $_SERVER['SCRIPT_FILENAME']) {
    header("location: ../user-login");
}
# SECURITY MITIGATION MODULES - CSRF - XSS
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
$user_status = $user_address = "";
# Processing form data when form is submitted
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['email_address'])) {
    #Sanitize Email Address
    if(isset($_POST['email_address'])) {
        if (empty(trim($_POST["email_address"]))) {
            $errors['email'] = 'Email address cannot be empty';
        } else {
            $email_address = $_POST["email_address"];
            $email_address = mysqli_real_escape_string($link, $email_address);
            if (!filter_var($email_address, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Email format is invalid.';
            }
            $email_address = $antiXss->xss_clean($email_address);
            $sql = "SELECT * FROM users WHERE email_address = ?";
            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "s", $param_email_address,);
                $param_email_address = $email_address;
                if (mysqli_stmt_execute($stmt)) {
                    $result = mysqli_stmt_get_result($stmt);
                    if (mysqli_num_rows($result) == 1) {
                        $row = mysqli_fetch_array($result, MYSQLI_ASSOC);
                        $user_address = $row['email_address'];
                        $user_first_name = $row['first_name'];
                        $user_status = $row['user_status'];
                        $user_token = $row['user_token'];
                        $user_tokens = BASE_URL . 'public/' . 'change-password?token=' . $user_token;
                    } else {
                        $errors['confirm_exist'] = "We've emailed you instructions for resetting your password, if an account exists with the email you entered. You should receive them shortly.";
                    }
                }

                if ($user_status == 'active') {
                    if (count($errors) === 0) {
                        sendResetConfirmation($user_address, $user_tokens, $user_first_name);
                        AddLogs($email_address, "RESET PASSWORD", "User Initiated Password Reset", "Success", $link);
                        $statusMsg = '<strong> Success! </strong>An email has been sent to the supplied email address. Follow the instruction in the email to reset your password.';
                        $_SESSION['statusMsg'] = $statusMsg;
                    }
                }
            }
        }
    }else{$errors['email'] = 'Email address cannot be empty';}

}

