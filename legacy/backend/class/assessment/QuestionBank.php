<?php
// QuestionBank.php
class QuestionBank {
    private mysqli $conn;

    public function __construct($db){
        $this->conn = $db;
    }

    /* Create a question. $meta is optional JSON for hotspots/matching etc */
    public function createQuestion(string $text, string $type, string $difficulty = 'medium', int $created_by = null, int $estimated_time = 0, bool $shuffle_options = true, ?string $meta = null): bool {
        $sql = "INSERT INTO questions (question_text, question_type, difficulty, estimated_time, shuffle_options, created_by) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return false;
        $shuffle = $shuffle_options ? 1 : 0;
        $stmt->bind_param("sssiii", $text, $type, $difficulty, $estimated_time, $shuffle, $created_by);
        return $stmt->execute();
    }

    public function getAll(): false|mysqli_result {
        $stmt = $this->conn->prepare("SELECT * FROM questions ORDER BY id DESC");
        if (!$stmt) return false;
        $stmt->execute();
        return $stmt->get_result();
    }

    public function getById(int $id): false|mysqli_result {
        $stmt = $this->conn->prepare("SELECT * FROM questions WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function updateQuestion(int $id, string $text, string $type, string $difficulty, int $estimated_time, bool $shuffle): bool {
        $sql = "UPDATE questions SET question_text = ?, question_type = ?, difficulty = ?, estimated_time = ?, shuffle_options = ? WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return false;
        $shuffle_int = $shuffle ? 1 : 0;
        $stmt->bind_param("sssiii", $text, $type, $difficulty, $estimated_time, $shuffle_int, $id);
        return $stmt->execute();
    }

    public function deleteQuestion(int $id): bool {
        $stmt = $this->conn->prepare("DELETE FROM questions WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("i", $id);
        return $stmt->execute();
    }

    /* Options */
    public function addOption(int $question_id, string $option_text, bool $is_correct = false, int $sort_order = 0): bool {
        $sql = "INSERT INTO question_options (question_id, option_text, is_correct, sort_order) VALUES (?, ?, ?, ?)";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return false;
        $is = $is_correct ? 1 : 0;
        $stmt->bind_param("isii", $question_id, $option_text, $is, $sort_order);
        return $stmt->execute();
    }

    public function getOptions(int $question_id): false|mysqli_result {
        $stmt = $this->conn->prepare("SELECT * FROM question_options WHERE question_id = ? ORDER BY sort_order ASC");
        if (!$stmt) return false;
        $stmt->bind_param("i", $question_id);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function updateOption(int $id, string $text, bool $is_correct, int $sort_order): bool {
        $sql = "UPDATE question_options SET option_text = ?, is_correct = ?, sort_order = ? WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return false;
        $is = $is_correct ? 1 : 0;
        $stmt->bind_param("siii", $text, $is, $sort_order, $id);
        return $stmt->execute();
    }

    public function deleteOption(int $id): bool {
        $stmt = $this->conn->prepare("DELETE FROM question_options WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("i", $id);
        return $stmt->execute();
    }
}
