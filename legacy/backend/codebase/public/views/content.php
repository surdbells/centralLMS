<?php
if(isset($_GET['course'])){
    $course = $_GET['course'];
    $sql = "SELECT * FROM course_category INNER JOIN courses ON course_category._category_id = courses._category_id WHERE _course_id = ?";
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "s", $param_course,);
        $param_course = $course;
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            if (mysqli_num_rows($result) > 0) {
                while($row = mysqli_fetch_array($result)){
                    $course_id = $row['_course_id'];
                    $_SESSION['viewing_course'] = $course_id;
                    $course_name = $row['_course_name'];
                    $category = $row['_name'];
                    $summary = $row['_summary'];
                    $start_date = $row['_start_date'];
                    $end_date = $row['_end_date'];
                    $description = $row['_description'];
                    $image = $row['_course_image'];
                    $supervisor = $row['_supervisor'];
                    $price = $row['_price'];
                    $duration = $row['_duration'];
                    $credits = $row['_credits'];
                    $target_audience = $row['_target_audience'];
                    $contacts = $row['_contacts'];
                    $course_pre_requisites = $row['_course_pre_requisites'];
                }
                mysqli_free_result($result);
                # check if account is activated
                $sql = "SELECT * FROM users WHERE id = ?";
                if($stmt = mysqli_prepare($link, $sql)){
                    mysqli_stmt_bind_param($stmt, "s", $param_spvs);
                    $param_spvs = $supervisor;
                    if (mysqli_stmt_execute($stmt)) {
                        $result = mysqli_stmt_get_result($stmt);
                        if (mysqli_num_rows($result) == 1) {
                            $row = mysqli_fetch_array($result, MYSQLI_ASSOC);
                            $user_title = $row['user_title'];
                            $in_first_name = $row['first_name'];
                            $in_last_name = $row['last_name'];
                            $in_avatar = $row['avatar'];
                        }
                    }
                }
            }
        }
    }
}else {
    header("location: ../user-login");
}
include "../student/views/CourseReviews.php";
?>

<div class="widget-content-area br-4">
<div class="row">
    <div class="col-lg-4 col-xl-4 col-sm-12 col-md-12">
        <img style="border: 2px dotted #D6B34B;" class="img-fluid" src="<?php echo BASE_URL . 'content/courses/' . $image ?>"/>
        <?php if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] == true && $_SESSION["user_type"] == "Student"){ ?>
        <?php } else { ?>
            <hr />
            <a href="<?php echo BASE_URL . 'public/user-login' ?>" class="btn btn-primary btn-block bg-primary">Sign In</a>
        <?php } ?>
    </div>
    <div class="col-lg-8 col-xl-8 col-sm-12 col-md-12">
        <h3 class="text-uppercase font-weight-bolder fmdqblue"><?php echo $course_name; ?></h3>
        <p style="font-size: 20px"><?php echo $summary; ?></p>
        <table class="table table-responsive-sm">
            <tr style="border: none !important;">
                <td class="float-left"><span class="text-uppercase badge badge-primary block font-weight-bolder"><?php echo $category; ?></span></td>
                <?php if ($start_date != 'EMPTY') { ?>
                    <td class="float-left">START DATE: <span class="text-uppercase badge outline-badge-primary block font-weight-bolder"><?php echo $start_date; ?></span></td>
                <?php } ?>
                <?php if ($end_date != 'EMPTY') { ?>
                    <td class="float-left">END DATE: <span class="text-uppercase badge outline-badge-primary block font-weight-bolder"><?php echo $end_date; ?></span></td>
                <?php } ?>

                <td class="float-left">INSTRUCTOR: <span class="text-uppercase font-weight-bolder text-primary"><?php echo $in_first_name . " " . $in_last_name?></span></td>
                <td class="float-left">PRICE: <span class="text-uppercase font-weight-bolder text-primary"><?php echo $price ?></span></td>
                <td class="float-left">DURATION: <span class="text-uppercase font-weight-bolder text-primary"><?php echo $duration ?> Minutes</span></td>
                <?php if ($credits != 'EMPTY') { ?>
                    <td class="float-left">CREDITS: <span class="text-uppercase font-weight-bolder text-primary"><?php echo $credits ?></span></td>
                <?php } ?>
                <td class="float-left">TARGET AUDIENCE: <span class="text-uppercase font-weight-bolder text-primary"><?php echo $target_audience ?></span></td>
                <td class="float-left">CONTACTS: <span class="text-uppercase font-weight-bolder text-primary"><?php echo $contacts ?></span></td>
                <?php if ($course_pre_requisites != 'EMPTY') { ?>
                    <td class="float-left">COURSE PRE REQUISITES: <span class="text-uppercase font-weight-bolder text-primary"><?php echo $course_pre_requisites ?></span></td>
                <?php } ?>
            </tr>
        </table>
    </div>
</div>
</div>
<div class="mt-4"></div>
<div class="widget-content-area br-4">
<div class="row">
    <div class="col">
        <ul class="nav nav-tabs mt-3" id="border-tabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active font-weight-bolder" id="border-home-tab" data-toggle="tab" href="#border-home" role="tab" aria-controls="border-home" aria-selected="true">
                    ABOUT THIS COURSE
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link font-weight-bolder" id="border-profile-tab" data-toggle="tab" href="#border-profile" role="tab" aria-controls="border-profile" aria-selected="false">
                    MODULES
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link font-weight-bolder" id="border-contact-tab" data-toggle="tab" href="#border-contact" role="tab" aria-controls="border-contact" aria-selected="false">
                    REVIEWS
                </a>
            </li>
        </ul>
        <div class="tab-content mb-4" id="border-tabsContent">
            <div class="tab-pane fade show active" id="border-home" role="tabpanel" aria-labelledby="border-home-tab">
                <div class="mt-4 ml-5 mr-5 text-justify">
                   <?php echo $description; ?>
                </div>

            </div>
            <div class="tab-pane fade" id="border-profile" role="tabpanel" aria-labelledby="border-profile-tab">
                    <?php

                    // Attempt select query execution
                    $sql = "SELECT * FROM modules WHERE _mod_course_id = ?";

                    if ($stmt = mysqli_prepare($link, $sql)) {
                    mysqli_stmt_bind_param($stmt, "s", $param_course_id,);
                    $param_course_id = $course_id;
                    if (mysqli_stmt_execute($stmt)) {
                    $result = mysqli_stmt_get_result($stmt);
                    if (mysqli_num_rows($result) > 0)?>
                            <table class="table table-responsive-lg">
                                <thead>
                                <tr role="row"><th>Name</th></tr>
                                </thead>
                                <tbody>
                                </tbody>
                                <?php
                                while($row = mysqli_fetch_array($result)){
                                $module_id = $row['_modules_id'];
                                ?>
                                <tr role="row">
                                    <td nowrap class="sorting_1 text-primary text-uppercase font-weight-bolder">
                                        <?php echo $row['_module_name']; ?>
                                    </td>
                                </tr>
                                <?php
                                }
                                mysqli_free_result($result);
                                ?>
                                </tbody>
                            </table>
                    <?php } else{echo '<p class="m-4">No module has been added to this course</p>';}
                }
            ?>
            </div>
                <div class="tab-pane fade" id="border-contact" role="tabpanel" aria-labelledby="border-contact-tab">
                    <div class="row mt-5">
                        <div class="card card-body col-lg-12 col-md-12 col-xl-12 col-sm-12">
                            <?php echo getrating($course, $link); ?>
                            <hr />
                        </div>
                    </div>
                </div>

            </div>
            <div class="sharethis-inline-share-buttons"></div>
        </div>
    </div>
    </div>
