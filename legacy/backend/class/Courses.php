<?php

use Random\RandomException;

class Courses{
    public ?int $id;
    private mysqli $conn;
    public function __construct($db){
        $this->conn = $db;
    }

    function createCourse($course){

    }
}