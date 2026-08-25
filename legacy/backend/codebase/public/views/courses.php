<?php

if (isset($_GET['pageno']) AND is_int($_GET['pageno'])) {
    $pageno = $_GET['pageno'];
} else {
    $pageno = 1;
}
$no_of_records_per_page = 8;
$offset = ($pageno-1) * $no_of_records_per_page;

$total_pages_sql = "SELECT COUNT(*) FROM courses";
$result = mysqli_query($link,$total_pages_sql);
$total_rows = mysqli_fetch_array($result)[0];
$total_pages = ceil($total_rows / $no_of_records_per_page);

// Attempt select query execution
$sql = "SELECT * FROM course_category INNER JOIN courses ON course_category._category_id = courses._category_id WHERE courses._status = ? LIMIT $offset, $no_of_records_per_page";
if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "s", $param_status,);
    $param_status = 'published';
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        if (mysqli_num_rows($result) > 0) {
            while($row = mysqli_fetch_array($result)){
                $image = BASE_URL . 'content/courses/' . $row['_course_image'];
                $name = $row['_course_name'];
                $category = $row['_name'];
                $price = $row['_price'];
                $category = $row['_name']; ?>
                <a href="<?php echo BASE_URL . 'public/course/description/' . $row['_course_id'] . '/' ?>">
                    <div class="col-md-12 col-sm-12 col-lg-4 col-xl-3 mt-4">
                        <div class="card">
                            <img style="height: 300px; object-fit: contain; object-position: 50% 50%" class="card-img-top" src="<?php echo $image ?>" alt="<?php echo $name ?>">
                            <div class="card-body">
                                <h4 style="min-height: 55px;" class="card-title text-uppercase font-weight-bolder"><?php echo $name ?></h4>
                            </div>
                            <div class="card-footer">
                                <a style="cursor: text;" class="btn btn-primary"><?php echo $category ?></a>
                                <?php if($row['_price'] == 'free'){
                                    echo "<a style='cursor: text;' class='btn btn-outline-primary'>{$row['_price']} </a>";
                                }else {
                                    echo "<a style='cursor: text;' class='btn btn-outline-primary'> ₦ {$row['_price']} </a>";
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </a>
                <?php
            }
            mysqli_free_result($result);
        }
    }
}
