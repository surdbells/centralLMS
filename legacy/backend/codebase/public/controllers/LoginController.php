<?php
if (str_replace(DIRECTORY_SEPARATOR, "/", __FILE__) == $_SERVER['SCRIPT_FILENAME']) {
    header("location: ../user-login");
}
# SECURITY MITIGATION MODULES - CSRF - XSS
require_once '../vendor/autoload.php';
include_once '../vendor/owasp/csrf-protector-php/libs/csrf/csrfprotector.php';
try {
    csrfProtector::init();
} catch (alreadyInitializedException|incompleteConfigurationException|configFileNotFoundException $e) {
}

use voku\helper\AntiXSS;
$antiXss = new AntiXSS();
// Processing form data when form is submitted
$email_address = $password = $rowCount = "";
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['public_login'])) {

    #CHECK EMAIL ADDRESS
    if(isset($_POST['email_address'])){
        if (empty(trim($_POST["email_address"]))) {
            $errors['email'] = 'Email address cannot be empty';
        } else {
            $email_address = $_POST["email_address"];
            $email_address = mysqli_real_escape_string($link, $email_address);
            if (!filter_var($email_address, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Email format is invalid.';
            }
            $email_address = $antiXss->xss_clean($email_address);
        }
    }else{$errors['email'] = 'Email address cannot be empty';}

    #SANITIZE PASSWORD
    if(isset($_POST['password'])){
        if (empty(trim($_POST["password"]))) {
            $errors['password'] = 'Password cannot be empty';
        } else {
            $password = $_POST["password"];
        /*    $password = mysqli_real_escape_string($link, $password);
            if (!preg_match("/^(?=.*\d)(?=.*[@#\-_$%^&+=§!?])(?=.*[a-z])(?=.*[A-Z])[0-9A-Za-z@#\-_$%^&+=§!?]{6,20}$/", $password)) {
                $errors['password'] = 'Password should be between 6 to 20 characters long, contains at least one special character, lowercase, uppercase and a digit.';
            }*/
            $password = $antiXss->xss_clean($password);
        }
    }else{$errors['password'] = 'Password cannot be empty';}




    # check if account is activated
    $email_check_query =  "SELECT * FROM users WHERE email_address = ? AND user_status = ?";
    if($stmt = mysqli_prepare($link, $email_check_query)){
        mysqli_stmt_bind_param($stmt, "ss", $param_email_address, $param_status );
        $param_email_address = $email_address;
        $param_status = "active";
        if(mysqli_stmt_execute($stmt)){
            $result = mysqli_stmt_get_result($stmt);
            $rowCount = mysqli_num_rows($result);
        }
    }

    if($rowCount > 0){
        if (count($errors) === 0) {
            # Login User Here
            # Prepare a select statement
            $sql = "SELECT id, user_type, category_id, user_token, user_status, first_name, last_name, email_address, avatar, nickname, department, job_title, line_manager_name, line_manager_email, date_time, user_password FROM users WHERE email_address = ?";

            if ($stmt = mysqli_prepare($link, $sql)) {
                // Bind variables to the prepared statement as parameters
                mysqli_stmt_bind_param($stmt, "s", $param_email);

                // Set parameters
                $param_email = $email_address;
                // Attempt to execute the prepared statement
                if (mysqli_stmt_execute($stmt)) {
                    // Store result
                    mysqli_stmt_store_result($stmt);
                    // Check if email exists, if yes then verify password
                    if (mysqli_stmt_num_rows($stmt) == 1) {
                        // Bind result variables
                        mysqli_stmt_bind_result($stmt, $id,$user_type, $category_id, $user_token, $user_status, $first_name, $last_name, $email_address, $avatar, $nickname, $department, $job_title, $line_manager_name, $line_manager_email,  $date_time, $hashed_password);
                        if (mysqli_stmt_fetch($stmt)) {
                            if (password_verify($password, $hashed_password)) {
                                # Password is correct, so start a new session
                                # Store data in session variables
                                $_SESSION["loggedin"] = true;
                                $_SESSION["id"] = $id;
                                $_SESSION["course_category_id"] = $category_id;
                                $_SESSION["user_type"] = $user_type;
                                $_SESSION["token"] = $user_token;
                                $_SESSION["status"] = $user_status;
                                $_SESSION["first_name"] = $first_name;
                                $_SESSION["last_name"] = $last_name;
                                $_SESSION["email_address"] = $email_address;
                                $_SESSION["avatar"] = $avatar;
                                $_SESSION["nickname"] = $nickname;
                                $_SESSION["department"] = $department;
                                $_SESSION["job_title"] = $job_title;
                                $_SESSION["line_manager_name"] = $line_manager_name;
                                $_SESSION["line_manager_email"] = $line_manager_email;
                                $_SESSION["date"] = $date_time;

                                #use user type to redirect user to course_category
                                #$course = $_SESSION['viewing_course'];
                                #$redirect = BASE_URL . 'student/course/description/' . $course . '/';

                                if($user_type == "Student"){
                                    if(isset($_POST['mobile'])){
                                        $navigate_mobile = BASE_URL . "pwa/student";
                                        header( "location: $navigate_mobile");
                                    }else {
                                        $navigate = BASE_URL . "student/student";
                                        header( "location: $navigate");
                                    }
                                }else if ($user_type == "Administrator"){
                                    if(isset($_POST['mobile'])){
                                        $errors['notallowed'] = "Oops! Administrators are not allowed on mobile.";
                                    }else {
                                        $navigate = BASE_URL . "backend/index";
                                        header( "location: $navigate");
                                    }
                                }else if ($user_type == "Instructor"){
                                    if(isset($_POST['mobile'])){
                                        $errors['notallowed'] = "Oops! Instructors are not allowed on mobile.";
                                    }else {
                                        $navigate = BASE_URL . "instructor/index";
                                        header( "location: $navigate");
                                    }
                                }
                                AddLogs($id, "LOGIN", "User Logged in to the system", "Success", $link);
                                # Redirect user to account
                            } else {
                                // Display an error message if password is not valid
                                $errors['password'] = "Incorrect Email address / Password";
                                AddLogs($email_address, "LOGIN", "User Entered Invalid Password", "Failed", $link);
                            }

                        }
                    }else {
                        // Display an error message if email doesn't exist
                        $errors['no_account'] = "Incorrect Email address / Password";
                        AddLogs($email_address, "LOGIN", "No Account Found for the email entered", "Failed", $link);
                    }
                }
            } else {
                $errors['wrong'] = "Oops! Something went wrong. Please try again later.";
            }
            // Close statement
           // mysqli_stmt_close($stmt);
        }
    }else {
        $errors['noaccount'] = 'Your account has not been activated, check your email and activate your account.';
        AddLogs($email_address, "LOGIN", "Account pending activation", "Failed", $link);

    }

}