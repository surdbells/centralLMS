<?php
// Attempt select query execution
$sql = "SELECT * FROM course_category INNER JOIN courses ON course_category._category_id = courses._category_id WHERE courses._featured = 'yes' AND courses._status = 'published' LIMIT 8";
// $sql = "SELECT * FROM elibrary_ebook INNER JOIN elibrary_category ON elibrary_ebook.category_id = elibrary_category.id";

if($result = mysqli_query($link, $sql)){
    if(mysqli_num_rows($result) > 0){
        while($row = mysqli_fetch_array($result)){
            $image = BASE_URL . 'content/courses/' . $row['_course_image'];
            $name = $row['_course_name'];
            $category = $row['_name'];
            $price = $row['_price'];
            $category = $row['_name']; ?>
            <a href="<?php echo BASE_URL . 'public/course/description/' . $row['_course_id'] . '/' ?>">
                <div class="col-md-3 col-sm-6 col-lg-3 col-xl-3 card card-body">
                    <img style="height: 250px; width: 100%;" src="<?php echo $image ?>" class="card-img-top" alt="...">
                    <div class="card-body">
                        <h5 class="card-title text-uppercase font-weight-bolder"><?php echo $name ?></h5>
                    </div>
                    <div class="card-body">
                        <a style="cursor: text;" class="btn btn-primary"><?php echo $category ?></a>
                        <?php if($row['_price'] == 'free'){
                            echo "<a style='cursor: text;' class='btn btn-outline-primary'>{$row['_price']} </a>";
                        }else {
                            echo "<a style='cursor: text;' class='btn btn-outline-primary'> ₦ {$row['_price']} </a>";
                        }
                        ?>
                    </div>
                </div>
            </a>
            <?php
        }
        mysqli_free_result($result);
    }
}
?>