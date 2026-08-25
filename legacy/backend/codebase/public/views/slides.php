
<div id="style1" class="carousel slide style-custom-1" data-ride="carousel">
        <?php
        // Attempt select query execution
        $sql = "SELECT * FROM slider_banners WHERE is_shown = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "s", $param_status,);
        $param_status = 'yes';
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            if (mysqli_num_rows($result) > 0) {?>
                <ol class="carousel-indicators">
                <?php
                    $i = 0;
                    while($row = mysqli_fetch_array($result)){
                        $id = $row['id'];
                        $slide = $row['slide'];
                        $status = $row['status'] ?>
                            <li data-target="#style1" data-slide-to="<?php echo $i; ?>" class="<?php echo $status ?>"></li>
                        <?php
                        $i++;
                    }?>
                 </ol>
                <?php
                mysqli_free_result($result);
            }
        }
}
        ?>

<?php
// Attempt select query execution
$sql = "SELECT * FROM slider_banners WHERE is_shown = ?";
if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "s", $param_status,);
    $param_status = 'yes';
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        if (mysqli_num_rows($result) > 0) {?>
        <div class="carousel-inner">
    <?php
    while($row = mysqli_fetch_array($result)){
        $id = $row['id'];
        $slide = $row['slide'];
        $status = $row['status'] ?>
        <div class="carousel-item <?php echo $status ?>">
            <img class="d-block w-100 slide-image img-fluid" src="<?php echo $slide ?>" alt="Second slide">
        </div>
         <?php } ?>
        </div>
            <?php
            mysqli_free_result($result);
            }
    }
}
?>
</div>