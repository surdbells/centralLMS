
<?php
include('../commons/config.php');
include('../commons/constants.php');
include('../includes/header.php');
?>
<?php include('../includes/menu.php'); ?>
<!--  BEGIN MAIN CONTAINER  -->
<div class="main-container" id="container">
    <!--  BEGIN CONTENT AREA  -->
    <div id="content" class="main-content mt-5">
        <div style="margin-top: 150px" class="layout-px-spacing">
            <!-- CONTENT AREA -->
            <div class="row layout-top-spacing">
                <div class="col-6 offset-3 layout-spacing">
                            <?php include("views/content.php"); ?>
                </div>
            </div>
            <!-- CONTENT AREA -->
        </div>
    </div>
    <!--  END CONTENT AREA  -->
</div>
<!-- END MAIN CONTAINER -->
<?php include('../includes/footer.php'); ?>