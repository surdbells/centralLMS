<?php

class Generic{
    public ?int $id;
    public string $name;
    private mysqli $conn;

    public function __construct($db){
        $this->conn = $db;
    }
    public function category(): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT * FROM course_category");
        $stmt->execute();
        return $stmt->get_result();
    }

}