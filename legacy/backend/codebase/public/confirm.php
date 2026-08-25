<?php
include('../commons/config.php');
include('../commons/constants.php');
include('../includes/header.php');
include('controllers/sessions.php');
include('controllers/EmailController.php');
include('controllers/ConfirmationController.php');
?>
<?php include('../includes/menu.php'); ?>
    <!--  BEGIN MAIN CONTAINER  -->
    <div class="main-container" id="container">
        <!--  BEGIN CONTENT AREA  -->
        <div id="content" class="main-content">
            <div class="layout-px-spacing">
                <!-- CONTENT AREA -->
                <div class="row layout-top-spacing">
                    <div class="offset-3 col-xl-6 col-lg-6 col-md-6 col-6 layout-spacing">
                        <div class="widget-content-area br-4">
                            <div class="widget-one">
                                <h6 class="text-uppercase font-weight-bolder">Account Confirmation</h6>
                                <?php include("views/messages.php"); ?>
                                <a href="user-login" class="btn btn-primary">CLICK HERE TO LOGIN</a>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- CONTENT AREA -->
            </div>
        </div>
        <!--  END CONTENT AREA  -->
    </div>
    <!-- END MAIN CONTAINER -->
<?php include('../includes/footer.php'); ?>