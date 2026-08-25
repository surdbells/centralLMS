<?php
if (str_replace(DIRECTORY_SEPARATOR, "/", __FILE__) == $_SERVER['SCRIPT_FILENAME']) {
    echo "Direct access not allowed";
    exit();
}
if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] == true && $_SESSION["user_type"] == "Student"){
    $navigate = BASE_URL . "student/account";
    header("location: $navigate");
} else if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] == true && $_SESSION["user_type"] == "Administrator") {
    $navigate = BASE_URL . "backend";
    header("location: $navigate");
}else if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] == true && $_SESSION["user_type"] == "Instructor") {
    $navigate = BASE_URL . "instructor";
    header("location: $navigate");
}