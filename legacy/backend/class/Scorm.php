<?php
class Scorm {
    private $conn;
    private $table = "scorm_progress";
    public $tenant_id;
    public $user_id;
    public $course_id;
    public $data;
    public $updated_at;

    public function __construct($db){
        $this->conn = $db;
    }

    public function save(){
        $query = "INSERT INTO {$this->table} (tenant_id, user_id, course_id, data, updated_at)
                  VALUES (?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE data=VALUES(data), updated_at=VALUES(updated_at)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iiiss", $this->tenant_id, $this->user_id, $this->course_id, $this->data, $this->updated_at);
        return $stmt->execute();
    }

    public function read_single(){
        $query = "SELECT data FROM {$this->table} WHERE tenant_id=? AND user_id=? AND course_id=?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iii", $this->tenant_id, $this->user_id, $this->course_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result;
    }
}
?>
