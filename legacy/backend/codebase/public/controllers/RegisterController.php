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
// Processing form data when form is submitted

include('EmailController.php');
# Define Data Variables
 $first_name = $last_name = $email_address = $password = "";

// Processing form data when form is submitted
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['user_register'])) {
    # Sanitize First Name
    if(isset($_POST['first_name'])) {
        if(empty(trim($_POST["first_name"]))){
            $errors['first_name'] = 'First name cannot be empty!.';
        }else {
            $first_name = $_POST["first_name"];
            $first_name = mysqli_real_escape_string($link, $first_name);
            if(!preg_match("/^[a-zA-Z ]*$/", $first_name)) {
                $errors['first_name'] = 'Only letters and white space allowed.';
            }
            $first_name = $antiXss->xss_clean($first_name);
        }
    }else{$errors['first_name'] = 'First Name cannot be empty!.';}

    #Sanitize Last Name
    if(isset($_POST['last_name'])) {
        if(empty(trim($_POST["last_name"]))){
            $errors['last_name'] = 'Last Name cannot be empty!.';
        }else {
            $last_name = $_POST["last_name"];
            $last_name = mysqli_real_escape_string($link, $last_name);
            if(!preg_match("/^[a-zA-Z ]*$/", $last_name)) {
                $errors['last_name'] = 'Only letters and white space allowed.';
            }
            $last_name = $antiXss->xss_clean($last_name);
        }
    }else{$errors['last_name'] = 'Last Name cannot be empty!.';}

    #Sanitize Email Address
    if(isset($_POST['email_address'])) {
        if(empty(trim($_POST["email_address"]))){
            $errors['email'] = 'Email address cannot be empty';
        }else {
            $email_address = $_POST["email_address"];
            $email_address = mysqli_real_escape_string($link, $email_address);
            if(!filter_var($email_address, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Email format is invalid.';
            }
            $email_address = $antiXss->xss_clean($email_address);

            # CHECK IF EMAIL ADDRESS EXIST
            $email_check_query =  "SELECT * FROM users WHERE email_address = ?";
            if($stmt = mysqli_prepare($link, $email_check_query)){
                mysqli_stmt_bind_param($stmt, "s", $param_email_address);
                $param_email_address = $email_address;
                if(mysqli_stmt_execute($stmt)){
                    $result = mysqli_stmt_get_result($stmt);
                    if(mysqli_num_rows($result) > 0){
                        $errors['email'] = 'An account already exist for the supplied email address';
                        AddLogs($email_address, "REGISTRATION", "User Tried to register with an existing email address", "Failed", $link);
                    }
                }
            }
        }
    }else{$errors['email'] = 'Email address cannot be empty';}


    if(isset($_POST['_terms'])){
        # Register User
    }else{
        $errors['_terms'] = 'You need to accept the terms and conditions';
    }

    if(isset($_POST['password'])) {
        #Sanitize Password
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
    }

    # Generate Other Information
    try {$bytes = random_bytes(20);} catch (Exception $e) {}
    $user_token =  bin2hex($bytes);
    $user_tokens =  BASE_URL . 'public/' . 'confirm?token=' . $user_token;;
    $user_type = "Student";
    //$username = $user_first_name . $user_last_name;

    // Check input errors before inserting in database
    if(count($errors) === 0){
        // Prepare an insert statement
        $sql = "INSERT INTO users (user_type, user_token, user_password, first_name, last_name, email_address) VALUES (?, ?, ?, ?, ?, ?)";

        if($stmt = mysqli_prepare($link, $sql)){
            // Bind variables to the prepared statement as parameters
            $param_password = password_hash($password, PASSWORD_DEFAULT); 
            mysqli_stmt_bind_param($stmt, "ssssss", $user_type, $user_token, $param_password, $first_name, $last_name, $email_address);
             # Creates a password hash
            // Attempt to execute the prepared statement
            if(mysqli_stmt_execute($stmt)){
                sendVerificationEmail($email_address, $user_tokens, $first_name);
                // Redirect to login page
                AddLogs($email_address, "REGISTRATION", "User Successfully registered", "Success", $link);
                $statusMsg = '<strong> Success! </strong>The account has been created successfully check your email address ' . '<strong>' . $email_address . '</strong> to activate your account';
                $_SESSION['statusMsg'] = $statusMsg;
                $user_name  = $email_address = $_home_address = $_organization = $_job_role = "";
            } else{
                exit();
            }

            // Close statement
            mysqli_stmt_close($stmt);
        }
    }

    // Close connection
    mysqli_close($link);
}