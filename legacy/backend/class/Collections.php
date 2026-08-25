<?php

class Collections{
    public ?int $id;
    public string $collection;
    public bool $is_active;
    private mysqli $conn;
    public function __construct($db){
        $this->conn = $db;
    }
    public function collections(): false|mysqli_result{
            $stmt = $this->conn->prepare("SELECT * FROM collections where is_active = true");
            if ($stmt->execute()){
                return $stmt->get_result();
            }else{ return false; }
    }
    public function all_collections(): false|mysqli_result{
            $stmt = $this->conn->prepare("SELECT * FROM collections");
            if ($stmt->execute()){
                return $stmt->get_result();
            }else{ return false; }
    }
    public function single_collections(): false|mysqli_result{
            $stmt = $this->conn->prepare("SELECT * FROM collections where collections_id = ?");
            $stmt->bind_param("i", $this->id);
            if ($stmt->execute()){
                return $stmt->get_result();
            }else{ return false; }
    }
    public function create_collection(): bool{
        $stmt = $this->conn->prepare("INSERT INTO collections 
        (collection_name) VALUES (?)");
        $stmt->bind_param("s", $this->collection);
        if($stmt->execute()){ return true; } return false;
    }
    function update_collections(): bool{
        $stmt = $this->conn->prepare("UPDATE collections SET collection_name = ?, is_active = ? WHERE collections_id = ?");
        $stmt->bind_param("sii", $this->collection,$this->is_active, $this->id);
        if($stmt->execute()){ return true; } return false;
    }

}