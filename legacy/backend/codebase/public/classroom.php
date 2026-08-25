<?php
include('../commons/config.php');
include('../commons/constants.php');
include('../includes/header.php');
?>
<!--  BEGIN MAIN CONTAINER  -->
<div class="container mt-3">
            <div class="row">
                <div class="col-3"></div>
                <div class="col-lg-6 col-xl-6 col-sm-6">
                    <div class="widget-content-area br-4">
                        <div class="text-center p-4">
                            <a href="<?php echo BASE_URL . 'public/index' ?>" class="navbar-brand">
                                <img style="width: 200px;" src="<?php echo LOGO ?>" alt="Logo">
                            </a>
                        </div>

                        <div class="widget-one">
                            <div class="form-form-wrap">
                        <div class="form-container">
                            <div class="form-content">
                                <h4 class="text-center">CLASSROOM CHECK IN</h4>
                                <form method="post" class="text-left">
                                    <div class="form">
                                        <div class='form-group'>
                                            <input hidden value="<?php echo date('Y-m-d'); ?>" id='_date' type="date" class="form-control">
                                            <input hidden value="<?php echo date("H:i"); ?>" id="_time" type="time" class="form-control">
                                        <div id="student-field" class="field-wrapper input">
                                            <label class="text-uppercase text-primary font-weight-bolder" for="email">USER ID</label>
                                            <input id="student" name="student" type="text" class="form-control">
                                        </div>
                                        <div class="mt-5"></div>
                                        <div id="training-field" class="field-wrapper input">
                                            <label class="text-uppercase text-primary font-weight-bolder" for="email">TRAINING ID</label>
                                            <input id="training" name="training" type="text" class="form-control">
                                        </div>
                                        <div class="mt-4"></div>
                                        <div id="username-field" class="field-wrapper input">
                                            <label class="text-uppercase text-primary font-weight-bolder" for="email">SCHEDULE ID</label>
                                            <input id="schedule" name="schedule" type="text" class="form-control">
                                        </div>
                                        <div class="mt-4"></div>
                                        <div class="d-sm-flex justify-content-between">
                                            <button name="check_in" id="check_in" type="button" class="btn btn-primary btn-block">
                                                CHECK IN
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                        </div>
                    </div>
                </div>
                <div class="col-3"></div>
    </div>
</div>
<style>
    #footer{
        display: none;
    }
</style>
<?php include('../includes/footer.php'); ?>
