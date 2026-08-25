<table class="table table table-bordered table-striped dataTable" style="width: 100%;" role="grid" aria-describedby="zero-config_info">
                    <tbody>
                    <?php
                    // Attempt select query execution
                    $sql = "SELECT * FROM course_category INNER JOIN courses ON course_category._category_id = courses._category_id WHERE courses._status = 'published' LIMIT 5";

                    if($result = mysqli_query($link, $sql)){
                        if(mysqli_num_rows($result) > 0){
                            while($row = mysqli_fetch_array($result)){ ?>
                                <?php $current_course = $row['_course_id']; ?>
                                <tr role="row">
                                    <td>
                                        <a href="<?php echo BASE_URL . 'public/course/description/' . $row['_course_id'] . '/' ?>">
                                            <div class="avatar avatar-xl">
                                                <img alt="avatar" src="<?php echo BASE_URL . 'content/courses/' . $row['_course_image']; ?>" class="card-img" />
                                            </div>
                                        </a>
                                    </td>
                                    <td nowrap class="sorting_1">
                                        <a href="<?php echo BASE_URL . 'public/course/description/' . $row['_course_id'] . '/' ?>">
                                            <div class="text-uppercase font-weight-bolder fmdqblue"><?php echo $row['_course_name']; ?></div>
                                        </a>
                                        <span style="height: 75px; text-overflow: ellipsis;" class="font-weight-bolder fmdqgold text-wrap"><?php echo $row['_summary']; ?></span>
                                    </td>
                                </tr>
                                <?php
                            }
                            mysqli_free_result($result);
                        }
                    }
                    ?>
                    </tbody>
                </table>

