<?php

class Labels{
    public ?int $id;
    public ?int $user;
    public string $label;
    private mysqli $conn;

    public function __construct($db){
        $this->conn = $db;
    }
    public function vendor_labels(): false|mysqli_result{
        if ($this->user){
            $stmt = $this->conn->prepare("SELECT * FROM vendor_custom_labels where user_id = ?");
            $stmt->bind_param("i", $this->user);
            $stmt->execute();
            return $stmt->get_result();
        }
        return false;
    }
    public function create_label(): bool{
        $stmt = $this->conn->prepare("INSERT INTO vendor_custom_labels 
        (user_id, label) VALUES (?, ?)");
        $stmt->bind_param("is",  $this->user, $this->label);
        if($stmt->execute()){ return true; } return false;
    }
    public function delete_label(): bool{
        $stmt = $this->conn->prepare("DELETE from vendor_custom_labels where vcl_id = ?");
        $stmt->bind_param("i",  $this->id);
        if($stmt->execute()){ return true; } return false;
    }
    function update_vendor_labels(): bool{
        $stmt = $this->conn->prepare("UPDATE vendor_custom_labels SET label = ? WHERE vcl_id = ?");
        $stmt->bind_param("si", $this->label, $this->id);
        if($stmt->execute()){ return true; } return false;
    }

}