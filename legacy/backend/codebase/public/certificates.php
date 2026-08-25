<?php
include('../commons/config.php');
include('../commons/constants.php');
if(isset($_GET['certificate'])) {
    $certificate = $_GET['certificate'];
    $cert_std_id = '';
    $sql = "SELECT * FROM student_certificates WHERE _std_cert_id = '$certificate'";
    if ($result = mysqli_query($link, $sql)) {
        if (mysqli_num_rows($result) > 0) {
            while ($row = mysqli_fetch_array($result)) {
                $certificate_id = $row['_std_cert_id'];
                $cert = $row['_certificate'];
                $cert_username = $row['_name_on_cert'];
                $cert_courses = $row['_course_name'];
                $cert_date = $row['_cert_date'];
                $cert_std_id = $row['_cert_student'];
            }
        }
    }
}
?>

<html>
<head>
    <meta charset="utf-8"/>
    <title>i-Fitness Gym Wellness Centres</title>
    <meta name="description" content="i-Fitness Gym & Wellness Centres" />
    <meta name="keywords" content="Find a centre close to you Lagos Agungi Badore Jakande Gbagada Ikorodu Lekki Phase 1 Maruwa Onikan Sangotedo VGC Ikeja GRA Yaba Surulere Opebi Magodo Festac Ogba Egbeda Abuja Gwarinpa Port Harcourt Trans Amadi Ibadan Ring Road WHY i-FITNESS Unique experiences tailored to your lifestyle Wide Range of Fitness Programs Our timetable offers a wide [&hellip;]" />
    <meta name="author" content="CENTRAL LMS SYSTEMS - KODEK INNOVATIONS v3.0"/>
    <meta property="og:url"           content="<?php echo BASE_URL . 'public/student/certificate/' . $certificate . '/' ?>" />
    <meta property="og:type"          content="website" />
    <meta property="og:title"         content="i-Fitness E-Learning Hub" />
    <meta property="og:description"   content="<?php echo strtoupper($cert_username . ' earned a certificate on ') ?> i-Fitness E-Learning Hub" />
    <meta property="og:image"         content="<?php echo BASE_URL . 'content/certificates/students/' . $cert ?>" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/plyr/3.6.2/plyr.css" integrity="sha512-jrLDXl9jUPe5DT19ukacvpX39XiErIBZxiaVMDFRe+OAKoBVYO126Dt7cvhMJ3Fja963lboD9DH+ev/2vbEnMw==" crossorigin="anonymous" />
    <link rel="icon" type="image/x-icon" href="https://ifitness.ng/wp-content/uploads/2021/10/IFITNESS-FAVICON-100x100.png' ?>"/>
    <link href="<?php echo BASE_URL . 'globals/assets/css/loader.css' ?>" rel="stylesheet" type="text/css" />
    <script src="<?php echo BASE_URL . 'globals/assets/js/loader.js' ?>"></script>
    <!-- BEGIN GLOBAL MANDATORY STYLES -->
    <link rel="preconnect" href="https://fonts.gstatic.com">
    <link href="https://fonts.googleapis.com/css?family=Quicksand:400,500,600,700&display=swap" rel="stylesheet">
    <link href="<?php echo BASE_URL . 'globals/bootstrap/css/bootstrap.min.css' ?>" rel="stylesheet" type="text/css" />
    <link href="<?php echo BASE_URL . 'globals/assets/css/plugins.css' ?>" rel="stylesheet" type="text/css" />
    <!-- END GLOBAL MANDATORY STYLES -->
    <!-- BEGIN PAGE LEVEL PLUGINS/CUSTOM STYLES -->
    <link href="<?php echo BASE_URL . 'globals/assets/css/dashboard/dash_2.css' ?>" rel="stylesheet" type="text/css" />
    <link href="<?php echo BASE_URL . 'globals/assets/css/scrollspyNav.css' ?>" rel="stylesheet" type="text/css" />
    <link href="<?php echo BASE_URL . 'globals/assets/css/components/tabs-accordian/custom-accordions.css' ?>" rel="stylesheet" type="text/css" />
    <link href="<?php echo BASE_URL . 'globals/assets/css/components/custom-list-group.css' ?>" rel="stylesheet" type="text/css" />
    <link href="<?php echo BASE_URL . 'globals/assets/css/components/tabs-accordian/custom-tabs.css' ?>" rel="stylesheet" type="text/css" />
    <link href="<?php echo BASE_URL . 'globals/plugins/table/datatable/datatables.css' ?>" rel="stylesheet" type="text/css" />
    <link href="<?php echo BASE_URL . 'globals/plugins/table/datatable/dt-global_style.css' ?>" rel="stylesheet" type="text/css" />
    <link href="<?php echo BASE_URL . 'globals/plugins/sweetalerts/sweetalert2.min.css' ?>" rel="stylesheet" type="text/css" />
    <link href="<?php echo BASE_URL . 'globals/plugins/sweetalerts/sweetalert.css' ?>" rel="stylesheet" type="text/css" />
    <link href="<?php echo BASE_URL . 'globals/plugins/notification/snackbar/snackbar.min.css' ?>" rel="stylesheet" type="text/css" />
    <link href="<?php echo BASE_URL . 'globals/plugins/select2/select2.min.css' ?>" rel="stylesheet" type="text/css" />
    <link href="<?php echo BASE_URL . 'globals/assets/css/components/custom-sweetalert.css' ?>" rel="stylesheet" type="text/css" />
    <link href="<?php echo BASE_URL . 'globals/assets/css/authentication/form-2.css' ?>" rel="stylesheet" type="text/css" />
    <!-- END GLOBAL MANDATORY STYLES -->
    <link rel="stylesheet" type="text/css" href="<?php echo BASE_URL . 'globals/assets/css/forms/theme-checkbox-radio.css' ?>" />
    <link rel="stylesheet" type="text/css" href="<?php echo BASE_URL . 'globals/assets/css/forms/switches.css' ?>" />
    <link rel="stylesheet" type="text/css" href="<?php echo BASE_URL . 'globals/assets/css/elements/avatar.css' ?>" />
    <link rel="stylesheet" type="text/css" href="<?php echo BASE_URL . 'globals/assets/css/components/custom-media_object.css' ?>" />
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote.min.css" rel="stylesheet">
    <!-- END PAGE LEVEL PLUGINS/CUSTOM STYLES -->
    <link href="<?php echo BASE_URL . 'globals/assets/css/custom.css' ?>" rel="stylesheet" type="text/css" />
    <script type="text/javascript" src="https://platform-api.sharethis.com/js/sharethis.js#property=601704c103ca8f0018e48360&product=inline-reaction-buttons" async="async"></script>
    <script type='text/javascript' src='https://platform-api.sharethis.com/js/sharethis.js#property=6017045e627bc500198ab484&product=inline-share-buttons' async='async'></script>
     <div id="fb-root"></div>
    <script async defer crossorigin="anonymous" src="https://connect.facebook.net/en_GB/sdk.js#xfbml=1&version=v9.0&appId=800044573402959&autoLogAppEvents=1" nonce="yQzTTAjC"></script>
</head>
<body>
<!--  BEGIN MAIN CONTAINER  -->
<div style="margin-top: 100px"></div>
<div class="main-container" id="container">
    <!--  BEGIN CONTENT AREA  -->
    <div id="content" class="main-content">
        <div class="layout-px-spacing">
            <!-- CONTENT AREA -->
            <div class="row layout-top-spacing">
                <div class="col-xl-2 col-lg-2 col-md-2 col-sm-12 layout-spacing"></div>
                <div class="col-xl-8 col-lg-8 col-md-8 col-sm-12 layout-spacing">
                    <div class="widget-content-area">
                        <div class="widget-one">
                            <img class="img-fluid" width="100%" src="<?php echo BASE_URL . 'content/certificates/students/' . $cert ?>" />
                        </div>
                    </div>
                    <div class="widget-content-area">
                        <div class="widget-two">
                            <div class="card">
                                <div class="card-body">
                                    <div class="sharethis-inline-share-buttons"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-lg-2 col-md-2 col-sm-12 layout-spacing"></div>
            </div>
            <!-- CONTENT AREA -->
        </div>
    </div>
    <!--  END CONTENT AREA  -->
</div>
<!-- END MAIN CONTAINER -->
</body>
</html>