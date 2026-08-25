<?php
include('../commons/config.php');
include('../commons/constants.php');
include('../includes/header.php');
include('controllers/sessions.php');
include('controllers/ReactivateController.php');
# GET USER INFO
if(isset($_GET['token'])){
    $token = $_GET['token'];
    $sql = "SELECT * FROM users WHERE user_token = '$token'";
    if ($result = mysqli_query($link, $sql)) {
        if (mysqli_num_rows($result) > 0) {
            while ($row = mysqli_fetch_array($result)) {
                $_SESSION['x_first_name'] =  $x_first_name = $row['first_name'];
                $_SESSION['x_last_name'] =    $x_last_name = $row['last_name'];
            }
        }
    }
}else{
    exit('You are not allowed to access this page');
}
?>
<?php include('../includes/menu.php'); ?>

    <!--  BEGIN MAIN CONTAINER  -->
    <div class="form-container outer">
        <div class="form-form">
            <div class="form-form-wrap">
                <?php include("views/messages.php"); ?>
                <div class="form-container">
                    <div class="form-content">
                        <div class="card card-body text-left">
                            <p>Welcome, <span class="text-primary font-weight-bolder"><?php echo $_SESSION['x_first_name'] . ' ' . $_SESSION['x_last_name'] ?></span></p>
                            <p>to a new learning experience, change your password below to restore your account. </p>
                        </div>
                        <h1 class="">Change Password</h1>
                        <p class="">Enter your new password.</p>
                        <form action="" method="post" class="text-left">
                            <input type="hidden" value="<?php echo $_GET['token']; ?>" name="token">
                            <div class="form">
                                <div id="username-field" class="field-wrapper input">
                                    <div class="form-row">
                                        <div class="form-group col-md-6">
                                            <div id="password-field" class="field-wrapper input mb-2">
                                                <div class="d-flex justify-content-between">
                                                    <label for="password">PASSWORD</label>
                                                </div>
                                                <input id="password" name="password" type="password" class="form-control" placeholder="Password">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" id="toggle-password" class="feather feather-eye"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                            </div>
                                        </div>
                                        <div class="form-group col-md-6">
                                            <div id="password-field" class="field-wrapper input mb-2">
                                                <div class="d-flex justify-content-between">
                                                    <label for="password">CONFIRM PASSWORD</label>
                                                </div>
                                                <input id="password" name="confirm_password" type="password" class="form-control" placeholder="Confirm Password">
                                            </div>
                                        </div>
                                        <a href="user-login" class="forgot-pass-link btn btn-primary">Click Here to Login</a>

                                    </div>
                                </div>
                                <div class="d-sm-flex justify-content-between">
                                    <div class="field-wrapper">
                                        <button onclick="addspinner()" type="submit" class="btn btn-primary" name="reactivate_account">
                                            <div id="submit" class=""></div>
                                            RESET PASSWORD
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>

                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- END MAIN CONTAINER -->
<?php include('../includes/footer.php'); ?>