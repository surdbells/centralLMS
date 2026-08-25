<?php
include('../commons/config.php');
include('../commons/constants.php');
include('../includes/header.php');
?>
<?php include('../includes/menu.php'); ?>
    <!--  BEGIN MAIN CONTAINER  -->
    <div class="main-container" id="container">
        <!--  BEGIN CONTENT AREA  -->
        <div id="content" class="main-content">
            <div class="layout-px-spacing">
                <!-- CONTENT AREA -->
                <div class="row layout-top-spacing">
                    <div class="col-xl-12 col-lg-12 col-md-12 col-12 layout-spacing">
                        <div class="widget-content-area br-4">
                            <div class="widget-one">
                                <?php include 'views/courses.php' ?>
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